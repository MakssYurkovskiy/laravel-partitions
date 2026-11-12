<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Unit;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yurkovskiy\Partitions\Contracts\PartitionStrategy;
use Yurkovskiy\Partitions\Strategies\DailyStrategy;
use Yurkovskiy\Partitions\Strategies\MonthlyStrategy;
use Yurkovskiy\Partitions\Strategies\WeeklyStrategy;
use Yurkovskiy\Partitions\Strategies\YearlyStrategy;

class StrategyTest extends TestCase
{
    public function test_daily_naming_and_boundaries(): void
    {
        $strategy = new DailyStrategy;
        $date = CarbonImmutable::parse('2025-06-13 17:42:11');

        $this->assertSame('p20250613', $strategy->partitionName($date));
        $this->assertSame('2025-06-13', $strategy->start($date)->toDateString());
        $this->assertSame('2025-06-14', $strategy->next($date)->toDateString());
        $this->assertSame('2025-06-13', $strategy->parse('p20250613')?->toDateString());
    }

    public function test_weekly_naming_and_boundaries(): void
    {
        $strategy = new WeeklyStrategy;
        $date = CarbonImmutable::parse('2025-06-13');

        $this->assertSame('p202524', $strategy->partitionName($date));
        $this->assertSame('2025-06-09', $strategy->start($date)->toDateString());
        $this->assertSame('2025-06-16', $strategy->next($date)->toDateString());
        $this->assertSame('2025-06-09', $strategy->parse('p202524')?->toDateString());
    }

    public function test_weekly_handles_the_turn_of_the_year(): void
    {
        $strategy = new WeeklyStrategy;

        $this->assertSame('p202501', $strategy->partitionName(CarbonImmutable::parse('2024-12-30')));
        $this->assertSame('2024-12-30', $strategy->parse('p202501')?->toDateString());
    }

    public function test_weekly_parses_week_53_only_in_years_that_have_one(): void
    {
        $strategy = new WeeklyStrategy;

        $this->assertNull($strategy->parse('p202553'));
        $this->assertSame('2020-12-28', $strategy->parse('p202053')?->toDateString());
        $this->assertSame('p202053', $strategy->partitionName(CarbonImmutable::parse('2020-12-28')));
    }

    public function test_monthly_naming_and_boundaries(): void
    {
        $strategy = new MonthlyStrategy;
        $date = CarbonImmutable::parse('2025-06-13');

        $this->assertSame('p202506', $strategy->partitionName($date));
        $this->assertSame('2025-06-01', $strategy->start($date)->toDateString());
        $this->assertSame('2025-07-01', $strategy->next($date)->toDateString());
        $this->assertSame('2025-06-01', $strategy->parse('p202506')?->toDateString());
    }

    public function test_yearly_naming_and_boundaries(): void
    {
        $strategy = new YearlyStrategy;
        $date = CarbonImmutable::parse('2025-06-13');

        $this->assertSame('p2025', $strategy->partitionName($date));
        $this->assertSame('2026-01-01', $strategy->next($date)->toDateString());
        $this->assertSame('2025-01-01', $strategy->parse('p2025')?->toDateString());
    }

    public function test_periods_are_inclusive_on_both_ends(): void
    {
        $periods = collect((new DailyStrategy)->periods(
            CarbonImmutable::parse('2025-06-13 23:00'),
            CarbonImmutable::parse('2025-06-15 01:00')
        ))->map(fn (CarbonImmutable $date) => $date->toDateString())->all();

        $this->assertSame(['2025-06-13', '2025-06-14', '2025-06-15'], $periods);
    }

    public function test_monthly_periods_do_not_skip_short_months(): void
    {
        $periods = collect((new MonthlyStrategy)->periods(
            CarbonImmutable::parse('2025-01-31'),
            CarbonImmutable::parse('2025-04-15')
        ))->map(fn (CarbonImmutable $date) => $date->format('Y-m'))->all();

        $this->assertSame(['2025-01', '2025-02', '2025-03', '2025-04'], $periods);
    }

    public function test_shift_moves_whole_periods(): void
    {
        $strategy = new MonthlyStrategy;

        $this->assertSame('2025-09-01', $strategy->shift(CarbonImmutable::parse('2025-06-13'), 3)->toDateString());
        $this->assertSame('2025-03-01', $strategy->shift(CarbonImmutable::parse('2025-06-13'), -3)->toDateString());
    }

    /**
     * @return iterable<string, array{0: PartitionStrategy, 1: string, 2: string, 3: string}>
     */
    public static function skippedMidnights(): iterable
    {
        yield 'daily' => [new DailyStrategy, 'America/Santiago', '2026-09-06', '2026-09-07'];
        yield 'weekly' => [new WeeklyStrategy, 'Asia/Tehran', '2021-03-22', '2021-03-29'];
        yield 'monthly' => [new MonthlyStrategy, 'America/Asuncion', '2023-10-01', '2023-11-01'];
        yield 'yearly' => [new YearlyStrategy, 'Asia/Khandyga', '2004-01-01', '2005-01-01'];
    }

    #[DataProvider('skippedMidnights')]
    public function test_a_period_starting_on_a_skipped_midnight_still_ends_at_midnight(
        PartitionStrategy $strategy,
        string $zone,
        string $start,
        string $next
    ): void {
        $date = CarbonImmutable::parse("$start 12:00:00", $zone);

        $this->assertSame("$start 01:00:00", $strategy->start($date)->format('Y-m-d H:i:s'));
        $this->assertSame("$next 00:00:00", $strategy->next($date)->format('Y-m-d H:i:s'));
        $this->assertSame("$next 00:00:00", $strategy->shift($date, 1)->format('Y-m-d H:i:s'));
    }

    public function test_periods_return_to_midnight_after_a_skipped_one(): void
    {
        $periods = collect((new DailyStrategy)->periods(
            CarbonImmutable::parse('2026-09-05', 'America/Santiago'),
            CarbonImmutable::parse('2026-09-08', 'America/Santiago')
        ))->map(fn (CarbonImmutable $date) => $date->format('Y-m-d H:i'))->all();

        $this->assertSame(
            ['2026-09-05 00:00', '2026-09-06 01:00', '2026-09-07 00:00', '2026-09-08 00:00'],
            $periods
        );
    }

    public function test_foreign_partition_names_are_not_parsed(): void
    {
        $strategy = new DailyStrategy;

        $this->assertNull($strategy->parse('pMax'));
        $this->assertNull($strategy->parse('p_archive'));
        $this->assertNull($strategy->parse('p202506'));
        $this->assertNull($strategy->parse('20250613'));
    }

    public function test_impossible_stamps_are_rejected_instead_of_overflowing(): void
    {
        $this->assertNull((new MonthlyStrategy)->parse('p202524'));
        $this->assertSame('2025-06-09', (new WeeklyStrategy)->parse('p202524')?->toDateString());

        $this->assertNull((new DailyStrategy)->parse('p20251399'));
        $this->assertNull((new DailyStrategy)->parse('p20250229'));
        $this->assertNull((new MonthlyStrategy)->parse('p202500'));

        $this->assertSame('2024-02-29', (new DailyStrategy)->parse('p20240229')?->toDateString());
    }

    public function test_prefix_is_configurable(): void
    {
        $strategy = (new DailyStrategy)->withPrefix('part_');

        $this->assertSame('part_20250613', $strategy->partitionName(CarbonImmutable::parse('2025-06-13')));
        $this->assertSame('2025-06-13', $strategy->parse('part_20250613')?->toDateString());
        $this->assertNull($strategy->parse('p20250613'));
    }

    public function test_the_same_prefix_is_not_cloned(): void
    {
        $strategy = new DailyStrategy;

        $this->assertSame($strategy, $strategy->withPrefix('p'));
        $this->assertNotSame($strategy, $strategy->withPrefix('part_'));
    }
}

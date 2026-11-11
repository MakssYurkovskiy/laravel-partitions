<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Unit;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use Yurkovskiy\Partitions\Contracts\PartitionStrategy;
use Yurkovskiy\Partitions\Exceptions\UnknownStrategyException;
use Yurkovskiy\Partitions\PartitionBuilder;
use Yurkovskiy\Partitions\Schema\PartitionPeriod;
use Yurkovskiy\Partitions\Strategies\DailyStrategy;
use Yurkovskiy\Partitions\Strategies\MonthlyStrategy;
use Yurkovskiy\Partitions\Strategies\WeeklyStrategy;
use Yurkovskiy\Partitions\Strategies\YearlyStrategy;
use Yurkovskiy\Partitions\Tests\Support\FakePartitionRepository;
use Yurkovskiy\Partitions\Tests\Support\QuarterlyStrategy;

class PeriodTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2025-06-13 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_every_case_builds_its_own_strategy(): void
    {
        $this->assertInstanceOf(DailyStrategy::class, PartitionPeriod::Daily->strategy());
        $this->assertInstanceOf(WeeklyStrategy::class, PartitionPeriod::Weekly->strategy());
        $this->assertInstanceOf(MonthlyStrategy::class, PartitionPeriod::Monthly->strategy());
        $this->assertInstanceOf(YearlyStrategy::class, PartitionPeriod::Yearly->strategy());
    }

    public function test_a_strategy_is_named_after_its_case(): void
    {
        foreach (PartitionPeriod::cases() as $period) {
            $this->assertSame($period->value, $period->strategy()->name());
        }

        $this->assertSame(['daily', 'weekly', 'monthly', 'yearly'], PartitionPeriod::values());
    }

    public function test_a_strategy_resolves_back_to_its_case(): void
    {
        foreach (PartitionPeriod::cases() as $period) {
            $this->assertSame($period, PartitionPeriod::tryFromStrategy($period->strategy()));
            $this->assertSame($period->strategyClass(), $period->strategy()::class);
        }

        $extended = new class extends MonthlyStrategy {};

        $this->assertSame(PartitionPeriod::Monthly, PartitionPeriod::tryFromStrategy($extended));
        $this->assertSame('monthly', $extended->name());

        $this->assertNull(PartitionPeriod::tryFromStrategy(new QuarterlyStrategy));
        $this->assertSame('quarterly', (new \Month)->name());
    }

    public function test_a_strategy_is_built_with_the_default_prefix(): void
    {
        $strategy = PartitionPeriod::Monthly->strategy();

        $this->assertSame('p', $strategy->prefix());
        $this->assertSame('p202506', $strategy->partitionName(CarbonImmutable::parse('2025-06-13')));
        $this->assertSame('part_202506', $strategy->withPrefix('part_')->partitionName(CarbonImmutable::parse('2025-06-13')));
    }

    public function test_canonical_values_units_and_initials_resolve_to_the_same_case(): void
    {
        foreach (PartitionPeriod::cases() as $period) {
            $this->assertSame($period, PartitionPeriod::tryMake($period));
            $this->assertSame($period, PartitionPeriod::tryMake($period->value));
            $this->assertSame($period, PartitionPeriod::tryMake($period->unit()));
            $this->assertSame($period, PartitionPeriod::tryMake($period->unit()[0]));
        }

        $this->assertSame(['day', 'week', 'month', 'year'], array_map(
            fn (PartitionPeriod $period) => $period->unit(),
            PartitionPeriod::cases(),
        ));
    }

    public function test_the_case_is_resolved_regardless_of_letter_case_and_padding(): void
    {
        $this->assertSame(PartitionPeriod::Daily, PartitionPeriod::tryMake('D'));
        $this->assertSame(PartitionPeriod::Weekly, PartitionPeriod::tryMake('W'));
        $this->assertSame(PartitionPeriod::Monthly, PartitionPeriod::tryMake('M'));
        $this->assertSame(PartitionPeriod::Yearly, PartitionPeriod::tryMake('Y'));

        $this->assertSame(PartitionPeriod::Monthly, PartitionPeriod::tryMake('MONTHLY'));
        $this->assertSame(PartitionPeriod::Monthly, PartitionPeriod::tryMake('Month'));
        $this->assertSame(PartitionPeriod::Monthly, PartitionPeriod::tryMake("  monthly\n"));
    }

    public function test_a_period_lists_its_shorthands(): void
    {
        $this->assertSame(['day', 'd'], PartitionPeriod::Daily->aliases());
        $this->assertSame(['week', 'w'], PartitionPeriod::Weekly->aliases());
        $this->assertSame(['month', 'm'], PartitionPeriod::Monthly->aliases());
        $this->assertSame(['year', 'y'], PartitionPeriod::Yearly->aliases());
    }

    public function test_the_accepted_aliases_are_spelled_out(): void
    {
        $resolved = [];

        foreach (['daily', 'day', 'd', 'weekly', 'week', 'w', 'monthly', 'month', 'm', 'yearly', 'year', 'y'] as $alias) {
            $resolved[$alias] = PartitionPeriod::tryMake($alias);
        }

        $this->assertSame([
            'daily' => PartitionPeriod::Daily,
            'day' => PartitionPeriod::Daily,
            'd' => PartitionPeriod::Daily,
            'weekly' => PartitionPeriod::Weekly,
            'week' => PartitionPeriod::Weekly,
            'w' => PartitionPeriod::Weekly,
            'monthly' => PartitionPeriod::Monthly,
            'month' => PartitionPeriod::Monthly,
            'm' => PartitionPeriod::Monthly,
            'yearly' => PartitionPeriod::Yearly,
            'year' => PartitionPeriod::Yearly,
            'y' => PartitionPeriod::Yearly,
        ], $resolved);
    }

    public function test_anything_else_is_not_a_period(): void
    {
        $this->assertNull(PartitionPeriod::tryMake('hourly'));
        $this->assertNull(PartitionPeriod::tryMake('quarterly'));
        $this->assertNull(PartitionPeriod::tryMake('days'));
        $this->assertNull(PartitionPeriod::tryMake('dai'));
        $this->assertNull(PartitionPeriod::tryMake(''));
        $this->assertNull(PartitionPeriod::tryMake(QuarterlyStrategy::class));
    }

    public function test_the_builder_accepts_a_case_a_value_and_a_shorthand(): void
    {
        foreach ([PartitionPeriod::Monthly, 'monthly', 'month', 'M'] as $strategy) {
            $builder = $this->builder()->table('transactions')->strategy($strategy, 'created_at');

            $this->assertSame('p202506', $builder->partitionNameFor('2025-06-13'));
        }
    }

    public function test_a_shorthand_strategy_keeps_the_configured_prefix(): void
    {
        $builder = $this->builder()->table('transactions')->prefix('part_')->strategy('M', 'created_at');

        $this->assertSame('part_202506', $builder->partitionNameFor('2025-06-13'));
    }

    public function test_a_case_and_a_class_keep_the_configured_prefix(): void
    {
        $builder = $this->builder()->table('transactions')->prefix('part_')->strategy(PartitionPeriod::Monthly, 'created_at');

        $this->assertSame('part_202506', $builder->partitionNameFor('2025-06-13'));

        $builder = $this->builder()->table('transactions')->prefix('part_')->strategy(QuarterlyStrategy::class, 'created_at');

        $this->assertSame('part_202504', $builder->partitionNameFor('2025-06-13'));
    }

    public function test_a_custom_strategy_class_is_resolved_by_its_name(): void
    {
        $builder = $this->builder()->table('transactions')->strategy(QuarterlyStrategy::class, 'created_at');

        $this->assertSame('p202504', $builder->partitionNameFor('2025-06-13'));
    }

    public function test_a_period_name_wins_over_a_class_called_the_same(): void
    {
        $this->assertTrue(is_a(\Month::class, PartitionStrategy::class, true));
        $this->assertSame('p202504', (new \Month)->partitionName(CarbonImmutable::parse('2025-06-13')));

        $builder = $this->builder()->table('transactions')->strategy(\Month::class, 'created_at');

        $this->assertSame(PartitionPeriod::Monthly, PartitionPeriod::tryMake(\Month::class));
        $this->assertSame('p202506', $builder->partitionNameFor('2025-06-13'));
    }

    public function test_an_unknown_strategy_is_reported_with_the_known_cases(): void
    {
        $this->expectException(UnknownStrategyException::class);
        $this->expectExceptionMessage('Unknown partition strategy [hourly]. Use daily, weekly, monthly, yearly or a class implementing');

        $this->builder()->table('transactions')->strategy('hourly');
    }

    private function builder(): PartitionBuilder
    {
        return new PartitionBuilder(new FakePartitionRepository);
    }
}

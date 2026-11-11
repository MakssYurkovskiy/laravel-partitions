<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Unit;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use PHPUnit\Framework\TestCase;
use Yurkovskiy\Partitions\Exceptions\InvalidIdentifierException;
use Yurkovskiy\Partitions\PartitionBuilder;
use Yurkovskiy\Partitions\Strategies\AbstractStrategy;
use Yurkovskiy\Partitions\Strategies\DailyStrategy;
use Yurkovskiy\Partitions\Tests\Support\FakePartitionRepository;
use Yurkovskiy\Partitions\Tests\Support\QuarterlyStrategy;
use Yurkovskiy\Partitions\Tests\Support\UnderscoredMonthlyStrategy;
use Yurkovskiy\Partitions\Tests\Support\UnnamedStrategy;

class CustomStrategyTest extends TestCase
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

    public function test_a_custom_strategy_can_be_used_by_class_name(): void
    {
        $sql = (new PartitionBuilder(new FakePartitionRepository))
            ->table('transactions')
            ->strategy(QuarterlyStrategy::class, 'created_at')
            ->usingRangeColumns()
            ->future(2)
            ->createStatement();

        $this->assertSame(<<<'SQL'
            ALTER TABLE `transactions`
            PARTITION BY RANGE COLUMNS(`created_at`) (
                PARTITION `p202504` VALUES LESS THAN ('2025-07-01'),
                PARTITION `p202507` VALUES LESS THAN ('2025-10-01'),
                PARTITION `p202510` VALUES LESS THAN ('2026-01-01'),
                PARTITION `pMax` VALUES LESS THAN MAXVALUE
            )
            SQL, $sql);
    }

    public function test_a_custom_strategy_keeps_its_own_name(): void
    {
        $this->assertSame('quarterly', (new QuarterlyStrategy)->name());
        $this->assertSame(UnnamedStrategy::class, (new UnnamedStrategy)->name());
    }

    public function test_a_custom_strategy_parses_its_own_partitions_back(): void
    {
        $strategy = new QuarterlyStrategy;

        $this->assertSame('p202504', $strategy->partitionName(CarbonImmutable::parse('2025-06-13')));
        $this->assertSame('2025-04-01', $strategy->parse('p202504')?->toDateString());
        $this->assertSame('2024-10-01', $strategy->shift(CarbonImmutable::parse('2025-06-13'), -2)->toDateString());
    }

    public function test_a_format_with_more_than_digits_parses_its_own_partitions_back(): void
    {
        $strategy = new UnderscoredMonthlyStrategy;

        $this->assertSame('p2025_06', $strategy->partitionName(CarbonImmutable::parse('2025-06-13')));
        $this->assertSame('2025-06-01', $strategy->parse('p2025_06')?->toDateString());
        $this->assertNull($strategy->parse('p202506'));
        $this->assertNull($strategy->parse('p2025_13'));
        $this->assertNull($strategy->parse('p2025_6'));
        $this->assertNull($strategy->parse('pMax'));
    }

    public function test_retention_and_range_drops_reach_partitions_of_a_format_with_more_than_digits(): void
    {
        $builder = (new PartitionBuilder($this->underscoredMonths()))
            ->table('transactions')
            ->strategy(UnderscoredMonthlyStrategy::class, 'created_at')
            ->usingRangeColumns()
            ->chunk(10);

        $this->assertSame(
            ['ALTER TABLE `transactions` DROP PARTITION `p2025_02`, `p2025_03`'],
            $builder->retain(2)->prune()->all()
        );

        $this->assertSame(
            ['ALTER TABLE `transactions` DROP PARTITION `p2025_04`'],
            (new PartitionBuilder($this->underscoredMonths()))
                ->table('transactions')
                ->strategy(UnderscoredMonthlyStrategy::class, 'created_at')
                ->usingRangeColumns()
                ->dropBetween('2025-04-01', '2025-04-30')
                ->all()
        );
    }

    public function test_a_format_outside_the_identifier_characters_is_rejected_with_the_strategy_named(): void
    {
        $strategy = new class extends AbstractStrategy
        {
            public function start(DateTimeInterface $date): CarbonImmutable
            {
                return CarbonImmutable::instance($date)->startOfMonth();
            }

            public function next(DateTimeInterface $date): CarbonImmutable
            {
                return $this->start($date)->addMonth();
            }

            protected function format(): string
            {
                return 'Y-m';
            }
        };

        $this->expectException(InvalidIdentifierException::class);
        $this->expectExceptionMessage('names a partition [p2025-06]');

        $strategy->partitionName(CarbonImmutable::parse('2025-06-13'));
    }

    public function test_a_prefix_outside_the_identifier_characters_is_rejected_before_any_sql(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        $this->expectExceptionMessage('Partition strategy ['.DailyStrategy::class.'] names a partition [p-20250613]');

        (new PartitionBuilder(new FakePartitionRepository))
            ->table('transactions')
            ->daily('created_at')
            ->prefix('p-')
            ->partitionNameFor('2025-06-13');
    }

    private function underscoredMonths(): FakePartitionRepository
    {
        return FakePartitionRepository::withPartitions([
            'p2025_02' => "'2025-03-01'",
            'p2025_03' => "'2025-04-01'",
            'p2025_04' => "'2025-05-01'",
            'p2025_05' => "'2025-06-01'",
            'p2025_06' => "'2025-07-01'",
            'pMax' => 'MAXVALUE',
        ], 'RANGE COLUMNS', '`created_at`');
    }
}

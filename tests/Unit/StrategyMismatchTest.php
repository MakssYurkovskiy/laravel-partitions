<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Unit;

use Carbon\CarbonImmutable;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yurkovskiy\Partitions\Exceptions\StrategyMismatchException;
use Yurkovskiy\Partitions\PartitionBuilder;
use Yurkovskiy\Partitions\Schema\PartitionInfo;
use Yurkovskiy\Partitions\Schema\PendingPartition;
use Yurkovskiy\Partitions\Tests\Support\FakePartitionRepository;
use Yurkovskiy\Partitions\Tests\Support\QuarterlyStrategy;

class StrategyMismatchTest extends TestCase
{
    private const TABLES = [
        'daily' => ['p20250612' => '739780', 'p20250613' => '739781'],
        'weekly' => ['p202523' => '739776', 'p202524' => '739783'],
        'monthly' => ['p202505' => '739768', 'p202506' => '739798'],
        'yearly' => ['p2024' => '739617', 'p2025' => '739982'],
    ];

    private string $timezone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');

        CarbonImmutable::setTestNow('2025-06-13 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        date_default_timezone_set($this->timezone);

        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $partitions
     */
    private function repository(array $partitions, string $expression = 'to_days(`created_at`)', string $method = 'RANGE'): FakePartitionRepository
    {
        return FakePartitionRepository::withPartitions($partitions + ['pMax' => 'MAXVALUE'], $method, $expression);
    }

    private function builder(FakePartitionRepository $repository, string $strategy): PartitionBuilder
    {
        return (new PartitionBuilder($repository))->table('transactions')->strategy($strategy, 'created_at');
    }

    /**
     * @return list<string>
     */
    private function names(PartitionBuilder $builder): array
    {
        return $builder->missing()->map(fn (PendingPartition $pending) => $pending->name)->all();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function matchingPeriods(): array
    {
        return [
            'daily' => ['daily', 'p20250614'],
            'weekly' => ['weekly', 'p202525'],
            'monthly' => ['monthly', 'p202507'],
            'yearly' => ['yearly', 'p2026'],
        ];
    }

    #[DataProvider('matchingPeriods')]
    public function test_a_table_cut_by_the_period_of_the_builder_is_managed(string $period, string $next): void
    {
        $builder = $this->builder($this->repository(self::TABLES[$period]), $period)->future(1);

        $this->assertSame([$next], $this->names($builder));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function foreignPeriods(): array
    {
        return [
            'daily table, weekly builder' => ['daily', 'weekly', 'p20250613'],
            'daily table, monthly builder' => ['daily', 'monthly', 'p20250613'],
            'daily table, yearly builder' => ['daily', 'yearly', 'p20250613'],
            'weekly table, daily builder' => ['weekly', 'daily', 'p202524'],
            'weekly table, monthly builder' => ['weekly', 'monthly', 'p202524'],
            'weekly table, yearly builder' => ['weekly', 'yearly', 'p202524'],
            'monthly table, daily builder' => ['monthly', 'daily', 'p202506'],
            'monthly table, weekly builder' => ['monthly', 'weekly', 'p202505'],
            'monthly table, yearly builder' => ['monthly', 'yearly', 'p202506'],
            'yearly table, daily builder' => ['yearly', 'daily', 'p2025'],
            'yearly table, weekly builder' => ['yearly', 'weekly', 'p2025'],
            'yearly table, monthly builder' => ['yearly', 'monthly', 'p2025'],
        ];
    }

    #[DataProvider('foreignPeriods')]
    public function test_a_table_cut_by_another_period_is_refused(string $table, string $configured, string $partition): void
    {
        $repository = $this->repository(self::TABLES[$table]);

        $this->expectException(StrategyMismatchException::class);
        $this->expectExceptionMessage(sprintf(
            'Table [transactions] holds %s partitions — [%s] is one of them — but the builder is configured for %s ones. '
            .'Set the strategy to %s, or repartition the table before managing it.',
            $table,
            $partition,
            $configured,
            $table
        ));

        try {
            $this->builder($repository, $configured)->future(3)->sync();
        } finally {
            $this->assertSame([], $repository->executed);
        }
    }

    /**
     * @return array<string, array{Closure(PartitionBuilder): mixed}>
     */
    public static function managingCalls(): array
    {
        return [
            'missing()' => [fn (PartitionBuilder $builder) => $builder->missing()],
            'pending()' => [fn (PartitionBuilder $builder) => $builder->pending()],
            'deferred()' => [fn (PartitionBuilder $builder) => $builder->deferred()],
            'syncStatements()' => [fn (PartitionBuilder $builder) => $builder->syncStatements()],
            'sync()' => [fn (PartitionBuilder $builder) => $builder->sync()],
            'a dry run of sync()' => [fn (PartitionBuilder $builder) => $builder->dryRun()->sync()],
            'outdated()' => [fn (PartitionBuilder $builder) => $builder->outdated()],
            'outdated() before a date' => [fn (PartitionBuilder $builder) => $builder->outdated('2025-06-13')],
            'inRange()' => [fn (PartitionBuilder $builder) => $builder->inRange('2025-06-01', '2025-06-30')],
            'prune()' => [fn (PartitionBuilder $builder) => $builder->prune()],
            'dropBefore()' => [fn (PartitionBuilder $builder) => $builder->dropBefore('2025-06-13')],
            'dropBetween()' => [fn (PartitionBuilder $builder) => $builder->dropBetween('2025-06-01', '2025-06-30')],
            'maintain()' => [fn (PartitionBuilder $builder) => $builder->maintain()],
        ];
    }

    /**
     * @param  Closure(PartitionBuilder): mixed  $call
     */
    #[DataProvider('managingCalls')]
    public function test_every_call_that_plans_or_drops_refuses_the_table(Closure $call): void
    {
        $repository = $this->repository(self::TABLES['daily']);
        $builder = $this->builder($repository, 'monthly')->retain(1);

        try {
            $call($builder);
            $this->fail('Expected a StrategyMismatchException.');
        } catch (StrategyMismatchException $e) {
            $this->assertStringContainsString('holds daily partitions', $e->getMessage());
            $this->assertSame([], $repository->executed);
        }
    }

    public function test_weekly_and_monthly_names_are_told_apart_by_their_boundaries(): void
    {
        $repository = $this->repository(['p202506' => '739657', 'p202507' => '739664']);

        $this->assertSame(
            ['p202508'],
            $this->names($this->builder($repository, 'weekly')->between('2025-02-17', '2025-02-17'))
        );

        $this->expectException(StrategyMismatchException::class);
        $this->expectExceptionMessage('holds weekly partitions — [p202506] is one of them — but the builder is configured for monthly ones');

        $this->builder($repository, 'monthly')->retain(1)->prune();
    }

    public function test_a_monthly_table_is_not_pruned_as_weeks(): void
    {
        $repository = $this->repository(self::TABLES['monthly']);
        $builder = $this->builder($repository, 'weekly')->retain(1);

        try {
            $builder->prune();
            $this->fail('Expected a StrategyMismatchException.');
        } catch (StrategyMismatchException) {
            $this->assertSame([], $repository->executed);
        }
    }

    public function test_a_name_of_the_period_with_another_boundary_is_refused(): void
    {
        $repository = $this->repository(['p202501' => '739707', 'p202504' => '739798']);

        $this->expectException(StrategyMismatchException::class);
        $this->expectExceptionMessage(
            'Partition [p202501] of table [transactions] ends at 2025-04-01, while a monthly partition of that name '
            .'ends at 2025-02-01. The table was partitioned by another period than the builder is configured for.'
        );

        $this->builder($repository, 'monthly')->missing();
    }

    public function test_a_partition_named_after_its_upper_bound_is_refused(): void
    {
        $repository = $this->repository(['p20250612' => '739779', 'p20250613' => '739780']);

        $this->expectException(StrategyMismatchException::class);
        $this->expectExceptionMessage(
            'Partition [p20250612] of table [transactions] ends at 2025-06-12, while a daily partition of that name ends at 2025-06-13.'
        );

        $this->builder($repository, 'daily')->dropBetween('2025-06-12', '2025-06-12');
    }

    public function test_older_partitions_of_another_period_do_not_stop_the_builder(): void
    {
        $repository = $this->repository(['p2023' => '739251', 'p2024' => '739617'] + self::TABLES['monthly']);
        $builder = $this->builder($repository, 'monthly')->future(1);

        $this->assertSame(['p202507'], $this->names($builder));
        $this->assertSame(
            ['p202505'],
            $builder->outdated('2025-06-01')->map(fn (PartitionInfo $partition) => $partition->name)->all()
        );
    }

    public function test_the_newest_partition_decides_for_a_mixed_table(): void
    {
        $repository = $this->repository(['p2023' => '739251', 'p2024' => '739617'] + self::TABLES['monthly']);

        $this->expectException(StrategyMismatchException::class);
        $this->expectExceptionMessage('holds monthly partitions — [p202506] is one of them — but the builder is configured for yearly ones');

        $this->builder($repository, 'yearly')->missing();
    }

    public function test_partitions_named_by_hand_are_left_out_of_the_check(): void
    {
        $repository = $this->repository(['archive' => '739768', 'p202506' => '739798', 'current' => '739829']);
        $builder = $this->builder($repository, 'monthly')->future(2);

        $this->assertSame(['p202508'], $this->names($builder));
        $this->assertSame(
            ['p202506'],
            $builder->outdated('2025-07-01')->map(fn (PartitionInfo $partition) => $partition->name)->all()
        );
    }

    public function test_a_table_with_only_the_catch_all_partition_is_managed(): void
    {
        $builder = $this->builder($this->repository([]), 'monthly')->future(0);

        $this->assertSame(['p202506'], $this->names($builder));
    }

    public function test_another_prefix_hides_the_partitions_from_the_check(): void
    {
        $repository = $this->repository(['txn202505' => '739768', 'txn202506' => '739798']);

        $this->assertSame([], $this->names($this->builder($repository, 'daily')->future(0)));
    }

    public function test_the_period_is_recognised_under_the_prefix_of_the_builder(): void
    {
        $repository = $this->repository(['txn202505' => '739768', 'txn202506' => '739798']);

        $this->expectException(StrategyMismatchException::class);
        $this->expectExceptionMessage('holds monthly partitions — [txn202506] is one of them — but the builder is configured for daily ones');

        $this->builder($repository, 'daily')->prefix('txn')->missing();
    }

    public function test_a_range_columns_table_is_checked_by_its_dates(): void
    {
        $repository = $this->repository(
            ['p20250612' => "'2025-06-13'", 'p20250613' => "'2025-06-14'"],
            '`created_at`',
            'RANGE COLUMNS'
        );

        $this->assertSame(['p20250614'], $this->names($this->builder($repository, 'daily')->usingRangeColumns()->future(1)));

        $this->expectException(StrategyMismatchException::class);
        $this->expectExceptionMessage('holds daily partitions — [p20250613] is one of them — but the builder is configured for monthly ones');

        $this->builder($repository, 'monthly')->usingRangeColumns()->missing();
    }

    public function test_a_unix_timestamp_table_is_checked_by_its_epochs(): void
    {
        $repository = $this->repository(
            ['p20250612' => '1749772800', 'p20250613' => '1749859200'],
            'unix_timestamp(`created_at`)'
        );

        $this->assertSame(
            ['p20250614'],
            $this->names($this->builder($repository, 'daily')->usingUnixTimestamp()->timezone('UTC')->future(1))
        );

        $this->expectException(StrategyMismatchException::class);
        $this->expectExceptionMessage('holds daily partitions — [p20250613] is one of them — but the builder is configured for weekly ones');

        $this->builder($repository, 'weekly')->usingUnixTimestamp()->timezone('UTC')->missing();
    }

    public function test_an_epoch_cut_in_another_timezone_is_still_the_same_period(): void
    {
        $repository = $this->repository(['p202506' => '1751317200'], 'unix_timestamp(`created_at`)');

        $builder = $this->builder($repository, 'monthly')->usingUnixTimestamp()->timezone('UTC')->future(1);

        $this->assertSame(['p202507'], $this->names($builder));
    }

    public function test_a_custom_strategy_is_checked_against_the_known_periods(): void
    {
        $repository = $this->repository(self::TABLES['monthly']);

        $this->expectException(StrategyMismatchException::class);
        $this->expectExceptionMessage('holds monthly partitions — [p202506] is one of them — but the builder is configured for quarterly ones');

        $this->builder($repository, QuarterlyStrategy::class)->missing();
    }

    public function test_a_custom_strategy_manages_the_table_it_cut(): void
    {
        $repository = $this->repository(['p202501' => '739707', 'p202504' => '739798']);

        $this->assertSame(['p202507'], $this->names($this->builder($repository, QuarterlyStrategy::class)->future(1)));
    }
}

<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Unit;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Yurkovskiy\Partitions\Events\PartitioningRemoved;
use Yurkovskiy\Partitions\Events\PartitionsCreated;
use Yurkovskiy\Partitions\Events\PartitionsDropped;
use Yurkovskiy\Partitions\Events\PartitionsTruncated;
use Yurkovskiy\Partitions\Exceptions\AlreadyPartitionedException;
use Yurkovskiy\Partitions\Exceptions\ExpressionMismatchException;
use Yurkovskiy\Partitions\Exceptions\InvalidIdentifierException;
use Yurkovskiy\Partitions\Exceptions\InvalidRangeException;
use Yurkovskiy\Partitions\Exceptions\MaxPartitionConflictException;
use Yurkovskiy\Partitions\Exceptions\NotPartitionedException;
use Yurkovskiy\Partitions\Exceptions\UnknownStrategyException;
use Yurkovskiy\Partitions\PartitionBuilder;
use Yurkovskiy\Partitions\Schema\PendingPartition;
use Yurkovskiy\Partitions\Schema\TableInfo;
use Yurkovskiy\Partitions\Tests\Support\CountingDailyStrategy;
use Yurkovskiy\Partitions\Tests\Support\FakeDispatcher;
use Yurkovskiy\Partitions\Tests\Support\FakeLogger;
use Yurkovskiy\Partitions\Tests\Support\FakePartitionRepository;

class PartitionBuilderTest extends TestCase
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

    protected function builder(?FakePartitionRepository $repository = null): PartitionBuilder
    {
        return new PartitionBuilder($repository ?? new FakePartitionRepository);
    }

    public function test_the_table_size_is_read_once_and_forgotten_on_refresh(): void
    {
        $repository = new FakePartitionRepository([], new TableInfo(name: 'transactions', rows: 1000, dataLength: 2048, indexLength: 1024));

        $builder = $this->builder($repository)->table('transactions')->daily('created_at');

        self::assertSame(3072, $builder->tableInfo()?->totalLength());
        self::assertSame(1000, $builder->tableInfo()?->rows);
        self::assertSame(1, $repository->tableReads);

        $builder->refresh();

        self::assertNotNull($builder->tableInfo());
        self::assertSame(2, $repository->tableReads);
    }

    public function test_a_missing_table_reports_no_size(): void
    {
        $repository = new FakePartitionRepository;

        $builder = $this->builder($repository)->table('transactions')->daily('created_at');

        self::assertNull($builder->tableInfo());
        self::assertNull($builder->tableInfo());
        self::assertSame(1, $repository->tableReads);
    }

    public function test_it_builds_the_initial_partitioning_statement(): void
    {
        $sql = $this->builder()
            ->table('transactions')
            ->daily('created_at')
            ->between('2025-06-13', '2025-06-15')
            ->createStatement();

        $this->assertSame(<<<'SQL'
            ALTER TABLE `transactions`
            PARTITION BY RANGE (TO_DAYS(`created_at`)) (
                PARTITION `p20250613` VALUES LESS THAN (739781),
                PARTITION `p20250614` VALUES LESS THAN (739782),
                PARTITION `p20250615` VALUES LESS THAN (739783),
                PARTITION `pMax` VALUES LESS THAN MAXVALUE
            )
            SQL, $sql);
    }

    public function test_the_boundary_is_the_day_after_the_partition(): void
    {
        $pending = $this->builder()
            ->table('transactions')
            ->daily('created_at')
            ->between('2025-06-13', '2025-06-13')
            ->missing()
            ->first();

        $this->assertInstanceOf(PendingPartition::class, $pending);
        $this->assertSame('p20250613', $pending->name);
        $this->assertSame('2025-06-14', $pending->upperBound->toDateString());
        $this->assertSame('739781', $pending->boundary);
    }

    public function test_range_columns_uses_readable_boundaries(): void
    {
        $sql = $this->builder()
            ->table('transactions')
            ->monthly('created_at')
            ->usingRangeColumns()
            ->between('2025-06-01', '2025-07-01')
            ->createStatement();

        $this->assertSame(<<<'SQL'
            ALTER TABLE `transactions`
            PARTITION BY RANGE COLUMNS(`created_at`) (
                PARTITION `p202506` VALUES LESS THAN ('2025-07-01'),
                PARTITION `p202507` VALUES LESS THAN ('2025-08-01'),
                PARTITION `pMax` VALUES LESS THAN MAXVALUE
            )
            SQL, $sql);
    }

    public function test_tablespace_is_appended_to_every_partition(): void
    {
        $sql = $this->builder()
            ->table('transactions')
            ->daily('created_at')
            ->tablespace('innodb_file_per_table')
            ->between('2025-06-13', '2025-06-13')
            ->partitionByClause();

        $this->assertStringContainsString(
            'PARTITION `p20250613` VALUES LESS THAN (739781) TABLESPACE = innodb_file_per_table',
            $sql
        );
        $this->assertStringContainsString(
            'PARTITION `pMax` VALUES LESS THAN MAXVALUE TABLESPACE = innodb_file_per_table',
            $sql
        );
    }

    public function test_it_can_work_without_a_max_partition(): void
    {
        $sql = $this->builder()
            ->table('transactions')
            ->daily('created_at')
            ->withoutMaxPartition()
            ->between('2025-06-13', '2025-06-13')
            ->createStatement();

        $this->assertStringNotContainsString('MAXVALUE', $sql);
    }

    public function test_create_refuses_to_touch_a_partitioned_table(): void
    {
        $repository = FakePartitionRepository::withPartitions(['p20250613' => '739781']);

        $this->expectException(AlreadyPartitionedException::class);

        $this->builder($repository)->table('transactions')->daily('created_at')->create();
    }

    public function test_a_create_dry_run_refuses_a_partitioned_table_too(): void
    {
        $repository = FakePartitionRepository::withPartitions(['p20250613' => '739781']);
        $events = new FakeDispatcher;

        $builder = (new PartitionBuilder($repository, $events))->table('transactions')->daily('created_at')->dryRun();

        try {
            $builder->create();
            $this->fail('A dry run of create() was expected to throw.');
        } catch (AlreadyPartitionedException $exception) {
            $this->assertStringContainsString('[transactions]', $exception->getMessage());
        }

        $this->assertSame([], $repository->executed);
        $this->assertSame([], $events->dispatched);
    }

    public function test_a_create_dry_run_returns_the_sql_for_an_unpartitioned_table(): void
    {
        $repository = new FakePartitionRepository;

        $statements = $this->builder($repository)
            ->table('transactions')
            ->daily('created_at')
            ->between('2025-06-13', '2025-06-13')
            ->dryRun()
            ->create();

        $this->assertCount(1, $statements);
        $this->assertStringStartsWith('ALTER TABLE `transactions`', $statements->first());
        $this->assertSame([], $repository->executed);
    }

    public function test_sync_reorganizes_the_max_partition(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p20250613' => '739781',
            'pMax' => 'MAXVALUE',
        ]);

        $statements = $this->builder($repository)
            ->table('transactions')
            ->daily('created_at')
            ->to('2025-06-15')
            ->sync();

        $this->assertSame([<<<'SQL'
            ALTER TABLE `transactions`
            REORGANIZE PARTITION `pMax` INTO (
                PARTITION `p20250614` VALUES LESS THAN (739782),
                PARTITION `p20250615` VALUES LESS THAN (739783),
                PARTITION `pMax` VALUES LESS THAN MAXVALUE
            )
            SQL], $statements->all());
        $this->assertSame($statements->all(), $repository->executed);
    }

    public function test_sync_appends_partitions_when_there_is_no_max_partition(): void
    {
        $repository = FakePartitionRepository::withPartitions(['p20250613' => '739781']);

        $statements = $this->builder($repository)
            ->table('transactions')
            ->daily('created_at')
            ->to('2025-06-14')
            ->sync();

        $this->assertSame([<<<'SQL'
            ALTER TABLE `transactions`
            ADD PARTITION (
                PARTITION `p20250614` VALUES LESS THAN (739782)
            )
            SQL], $statements->all());
    }

    public function test_sync_is_a_no_op_when_everything_exists(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p20250613' => '739781',
            'p20250614' => '739782',
            'pMax' => 'MAXVALUE',
        ]);

        $statements = $this->builder($repository)
            ->table('transactions')
            ->daily('created_at')
            ->to('2025-06-14')
            ->sync();

        $this->assertTrue($statements->isEmpty());
        $this->assertSame([], $repository->executed);
    }

    public function test_partitions_below_the_highest_boundary_are_skipped(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p20250613' => '739781',
            'pMax' => 'MAXVALUE',
        ]);

        $missing = $this->builder($repository)
            ->table('transactions')
            ->daily('created_at')
            ->between('2025-06-01', '2025-06-15')
            ->missing()
            ->map(fn (PendingPartition $pending) => $pending->name)
            ->all();

        $this->assertSame(['p20250614', 'p20250615'], $missing);
    }

    public function test_future_periods_are_used_when_no_end_date_is_given(): void
    {
        $missing = $this->builder()
            ->table('transactions')
            ->daily('created_at')
            ->future(3)
            ->missing()
            ->map(fn (PendingPartition $pending) => $pending->name)
            ->all();

        $this->assertSame(['p20250613', 'p20250614', 'p20250615', 'p20250616'], $missing);
    }

    public function test_create_refuses_a_range_that_ends_before_it_starts(): void
    {
        $repository = new FakePartitionRepository;

        $this->expectException(InvalidRangeException::class);
        $this->expectExceptionMessage('starts at 2025-06-20 and ends at 2025-06-13');

        try {
            $this->builder($repository)
                ->table('transactions')
                ->daily('created_at')
                ->between('2025-06-20', '2025-06-13')
                ->create();
        } finally {
            $this->assertSame([], $repository->executed);
        }
    }

    public function test_sync_refuses_a_start_past_the_look_ahead(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p20250613' => '739781',
            'pMax' => 'MAXVALUE',
        ]);

        $this->expectException(InvalidRangeException::class);
        $this->expectExceptionMessage('it stops at 2025-06-16 — future(3), counted from today');

        try {
            $this->builder($repository)
                ->table('transactions')
                ->daily('created_at')
                ->from('2025-06-17')
                ->future(3)
                ->sync();
        } finally {
            $this->assertSame([], $repository->executed);
        }
    }

    public function test_a_range_is_compared_by_period_so_two_dates_of_one_month_cover_it(): void
    {
        $missing = $this->builder()
            ->table('transactions')
            ->monthly('created_at')
            ->between('2025-06-20', '2025-06-05')
            ->missing()
            ->map(fn (PendingPartition $pending) => $pending->name)
            ->all();

        $this->assertSame(['p202506'], $missing);
    }

    public function test_a_start_on_the_last_period_of_the_look_ahead_is_still_a_range(): void
    {
        $missing = $this->builder()
            ->table('transactions')
            ->daily('created_at')
            ->from('2025-06-16')
            ->future(3)
            ->missing()
            ->map(fn (PendingPartition $pending) => $pending->name)
            ->all();

        $this->assertSame(['p20250616'], $missing);
    }

    public function test_prune_drops_partitions_outside_the_retention(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p20250609' => '739777',
            'p20250610' => '739778',
            'p20250611' => '739779',
            'p20250612' => '739780',
            'p20250613' => '739781',
            'pMax' => 'MAXVALUE',
        ]);

        $statements = $this->builder($repository)
            ->table('transactions')
            ->daily('created_at')
            ->retain(2)
            ->prune();

        $this->assertSame([
            'ALTER TABLE `transactions` DROP PARTITION `p20250609`',
            'ALTER TABLE `transactions` DROP PARTITION `p20250610`',
        ], $statements->all());
    }

    public function test_retain_keeps_the_given_number_of_complete_periods_plus_the_current_one(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p20250609' => '739777',
            'p20250610' => '739778',
            'p20250611' => '739779',
            'p20250612' => '739780',
            'p20250613' => '739781',
            'pMax' => 'MAXVALUE',
        ]);

        $outdated = fn (int $periods) => $this->builder($repository)
            ->table('transactions')
            ->daily('created_at')
            ->retain($periods)
            ->outdated()
            ->pluck('name')
            ->all();

        self::assertSame(['p20250609', 'p20250610', 'p20250611', 'p20250612'], $outdated(0));
        self::assertSame(['p20250609', 'p20250610'], $outdated(2));
        self::assertSame([], $outdated(4));
    }

    public function test_partitions_can_be_dropped_in_chunks(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p20250609' => '739777',
            'p20250610' => '739778',
            'p20250611' => '739779',
            'pMax' => 'MAXVALUE',
        ]);

        $statements = $this->builder($repository)
            ->table('transactions')
            ->daily('created_at')
            ->chunk(10)
            ->dropBefore('2025-06-11');

        $this->assertSame(
            ['ALTER TABLE `transactions` DROP PARTITION `p20250609`, `p20250610`'],
            $statements->all()
        );
    }

    /**
     * @return array<string, array{?int, list<string>}>
     */
    public static function dropChunkSizes(): array
    {
        return [
            'one per statement' => [1, ['`p20250609`', '`p20250610`', '`p20250611`']],
            'two per statement' => [2, ['`p20250609`, `p20250610`', '`p20250611`']],
            'zero, all in one statement' => [0, ['`p20250609`, `p20250610`, `p20250611`']],
            'not set, all in one statement' => [null, ['`p20250609`, `p20250610`, `p20250611`']],
        ];
    }

    /**
     * @param  list<string>  $groups
     */
    #[DataProvider('dropChunkSizes')]
    public function test_the_chunk_sets_the_partitions_dropped_and_truncated_per_statement(?int $size, array $groups): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p20250609' => '739777',
            'p20250610' => '739778',
            'p20250611' => '739779',
            'p20250612' => '739780',
            'pMax' => 'MAXVALUE',
        ]);

        $builder = $this->builder($repository)->table('transactions')->daily('created_at')->chunk($size);
        $targets = $builder->outdated('2025-06-12');
        $statements = fn (string $verb) => array_map(
            fn (string $names) => "ALTER TABLE `transactions` {$verb} PARTITION {$names}",
            $groups
        );

        $this->assertSame($statements('DROP'), $builder->dropStatements($targets)->all());
        $this->assertSame($statements('TRUNCATE'), $builder->truncate($targets)->all());
    }

    public function test_drop_between_never_touches_the_max_partition_or_foreign_names(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p20250610' => '739778',
            'p_legacy' => '739779',
            'p20250611' => '739779',
            'pMax' => 'MAXVALUE',
        ]);

        $statements = $this->builder($repository)
            ->table('transactions')
            ->daily('created_at')
            ->chunk(10)
            ->dropBetween('2025-06-01', '2025-06-30');

        $this->assertSame(
            ['ALTER TABLE `transactions` DROP PARTITION `p20250610`, `p20250611`'],
            $statements->all()
        );
    }

    public function test_drop_between_normalises_swapped_bounds(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p20250610' => '739778',
            'p20250611' => '739779',
            'pMax' => 'MAXVALUE',
        ]);

        $statements = $this->builder($repository)
            ->table('transactions')
            ->daily('created_at')
            ->chunk(10)
            ->dropBetween('2025-06-30', '2025-06-01');

        $this->assertSame(
            ['ALTER TABLE `transactions` DROP PARTITION `p20250610`, `p20250611`'],
            $statements->all()
        );
    }

    public function test_dry_run_builds_the_sql_without_executing_it(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p20250613' => '739781',
            'pMax' => 'MAXVALUE',
        ]);

        $statements = $this->builder($repository)
            ->table('transactions')
            ->daily('created_at')
            ->dryRun()
            ->future(1)
            ->sync();

        $this->assertCount(1, $statements);
        $this->assertSame([], $repository->executed);
    }

    public function test_maintain_adds_and_prunes_in_one_call(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p20250601' => '739769',
            'p20250613' => '739781',
            'pMax' => 'MAXVALUE',
        ]);

        $result = $this->builder($repository)
            ->table('transactions')
            ->daily('created_at')
            ->future(1)
            ->retain(5)
            ->maintain();

        $this->assertCount(1, $result['added']);
        $this->assertCount(1, $result['dropped']);
        $this->assertStringContainsString('p20250614', $result['added']->first());
        $this->assertStringContainsString('p20250601', $result['dropped']->first());
    }

    public function test_it_rejects_unsafe_identifiers(): void
    {
        $this->expectException(InvalidIdentifierException::class);

        $this->builder()
            ->table('transactions`; DROP TABLE users; --')
            ->daily('created_at')
            ->createStatement();
    }

    public function test_it_rejects_unknown_strategies(): void
    {
        $this->expectException(UnknownStrategyException::class);

        $this->builder()->table('transactions')->strategy('hourly');
    }

    public function test_it_fills_the_periods_the_table_skipped(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p20250610' => '739778',
            'pMax' => 'MAXVALUE',
        ]);

        $missing = $this->builder($repository)
            ->table('transactions')
            ->daily('created_at')
            ->future(1)
            ->missing()
            ->map(fn (PendingPartition $pending) => $pending->name)
            ->all();

        $this->assertSame(
            ['p20250611', 'p20250612', 'p20250613', 'p20250614'],
            $missing
        );
    }

    public function test_gap_filling_can_be_turned_off(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p20250610' => '739778',
            'pMax' => 'MAXVALUE',
        ]);

        $missing = $this->builder($repository)
            ->table('transactions')
            ->daily('created_at')
            ->fillGaps(false)
            ->future(1)
            ->missing()
            ->map(fn (PendingPartition $pending) => $pending->name)
            ->all();

        $this->assertSame(['p20250613', 'p20250614'], $missing);
    }

    /**
     * @return array<string, array{string, string, string, int, list<string>}>
     */
    public static function tablesAheadOfToday(): array
    {
        return [
            'daily' => ['daily', 'p20250620', '739788', 10, ['p20250621', 'p20250622', 'p20250623']],
            'weekly' => ['weekly', 'p202526', '739797', 4, ['p202527', 'p202528']],
            'monthly' => ['monthly', 'p202508', '739860', 4, ['p202509', 'p202510']],
            'yearly' => ['yearly', 'p2026', '740347', 3, ['p2027', 'p2028']],
        ];
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('tablesAheadOfToday')]
    public function test_without_a_start_the_range_continues_after_the_last_partition(
        string $strategy,
        string $last,
        string $boundary,
        int $future,
        array $expected,
    ): void {
        $repository = FakePartitionRepository::withPartitions([$last => $boundary, 'pMax' => 'MAXVALUE']);

        foreach ([true, false] as $fillGaps) {
            $missing = $this->builder($repository)
                ->table('transactions')
                ->strategy($strategy, 'created_at')
                ->fillGaps($fillGaps)
                ->future($future)
                ->missing()
                ->map(fn (PendingPartition $pending) => $pending->name)
                ->all();

            $this->assertSame($expected, $missing);
        }
    }

    /**
     * @return array<string, array{string, string, string, list<string>, list<string>}>
     */
    public static function tablesBehindToday(): array
    {
        return [
            'daily' => [
                'daily', 'p20250609', '739777',
                ['p20250610', 'p20250611', 'p20250612', 'p20250613', 'p20250614'],
                ['p20250613', 'p20250614'],
            ],
            'weekly' => [
                'weekly', 'p202521', '739762',
                ['p202522', 'p202523', 'p202524', 'p202525'],
                ['p202524', 'p202525'],
            ],
            'monthly' => [
                'monthly', 'p202503', '739707',
                ['p202504', 'p202505', 'p202506', 'p202507'],
                ['p202506', 'p202507'],
            ],
            'yearly' => [
                'yearly', 'p2022', '738886',
                ['p2023', 'p2024', 'p2025', 'p2026'],
                ['p2025', 'p2026'],
            ],
        ];
    }

    /**
     * @param  list<string>  $continued
     * @param  list<string>  $fromToday
     */
    #[DataProvider('tablesBehindToday')]
    public function test_a_table_behind_today_continues_from_its_last_partition_unless_gap_filling_is_off(
        string $strategy,
        string $last,
        string $boundary,
        array $continued,
        array $fromToday,
    ): void {
        $repository = FakePartitionRepository::withPartitions([$last => $boundary, 'pMax' => 'MAXVALUE']);

        $builder = $this->builder($repository)->table('transactions')->strategy($strategy, 'created_at')->future(1);

        $names = fn (PartitionBuilder $builder) => $builder->missing()
            ->map(fn (PendingPartition $pending) => $pending->name)
            ->all();

        $this->assertSame($continued, $names($builder));
        $this->assertSame($fromToday, $names($builder->fillGaps(false)));
    }

    /**
     * @return array<string, array{string, string, string, string, array<string, mixed>, string, list<string>}>
     */
    public static function startsPastAGap(): array
    {
        return [
            'daily' => [
                'daily', 'p20250620', '739788', '2025-06-25',
                [
                    'first_skipped' => 'p20250621',
                    'last_skipped' => 'p20250624',
                    'skipped' => 4,
                    'holder' => 'p20250625',
                    'continue_at' => '2025-06-21',
                ],
                'The start date 2025-06-25 leaves 4 periods of table [transactions] without a partition of their own '
                .'— [p20250621] to [p20250624], after [p20250620]. Their rows land in [p20250625] and are dropped '
                .'together with it. Start at 2025-06-21, or leave the start date out, to give each period its partition.',
                ['p20250621', 'p20250622', 'p20250623', 'p20250624', 'p20250625'],
            ],
            'weekly' => [
                'weekly', 'p202526', '739797', '2025-07-16',
                [
                    'first_skipped' => 'p202527',
                    'last_skipped' => 'p202528',
                    'skipped' => 2,
                    'holder' => 'p202529',
                    'continue_at' => '2025-06-30',
                ],
                'The start date 2025-07-16 leaves 2 periods of table [transactions] without a partition of their own '
                .'— [p202527] to [p202528], after [p202526]. Their rows land in [p202529] and are dropped '
                .'together with it. Start at 2025-06-30, or leave the start date out, to give each period its partition.',
                ['p202527', 'p202528', 'p202529'],
            ],
            'monthly' => [
                'monthly', 'p202508', '739860', '2025-12-10',
                [
                    'first_skipped' => 'p202509',
                    'last_skipped' => 'p202511',
                    'skipped' => 3,
                    'holder' => 'p202512',
                    'continue_at' => '2025-09-01',
                ],
                'The start date 2025-12-10 leaves 3 periods of table [transactions] without a partition of their own '
                .'— [p202509] to [p202511], after [p202508]. Their rows land in [p202512] and are dropped '
                .'together with it. Start at 2025-09-01, or leave the start date out, to give each period its partition.',
                ['p202509', 'p202510', 'p202511', 'p202512'],
            ],
            'yearly' => [
                'yearly', 'p2026', '740347', '2028-05-05',
                [
                    'first_skipped' => 'p2027',
                    'last_skipped' => 'p2027',
                    'skipped' => 1,
                    'holder' => 'p2028',
                    'continue_at' => '2027-01-01',
                ],
                'The start date 2028-05-05 leaves 1 period of table [transactions] without a partition of its own '
                .'— [p2027], after [p2026]. Its rows land in [p2028] and are dropped '
                .'together with it. Start at 2027-01-01, or leave the start date out, to give each period its partition.',
                ['p2027', 'p2028'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $gap
     * @param  list<string>  $continued
     */
    #[DataProvider('startsPastAGap')]
    public function test_a_start_past_the_period_after_the_last_partition_is_used_as_given_and_logged(
        string $strategy,
        string $last,
        string $boundary,
        string $from,
        array $gap,
        string $message,
        array $continued,
    ): void {
        $repository = FakePartitionRepository::withPartitions([$last => $boundary, 'pMax' => 'MAXVALUE']);
        $logger = new FakeLogger;

        $builder = (new PartitionBuilder($repository, null, $logger))
            ->table('transactions')
            ->strategy($strategy, 'created_at')
            ->between($from, $from);

        $context = ['table' => 'transactions', 'from' => $from, 'last' => $last] + $gap;

        $this->assertSame($context, $builder->gap()?->toArray());
        $this->assertSame($message, $builder->gap()?->message());
        $this->assertSame(
            [$gap['holder']],
            $builder->missing()->map(fn (PendingPartition $pending) => $pending->name)->all()
        );
        $this->assertSame([], $logger->records);

        $builder->sync();

        $this->assertCount(1, $repository->executed);
        $this->assertStringContainsString("PARTITION `{$gap['holder']}` VALUES LESS THAN", $repository->executed[0]);
        $this->assertStringNotContainsString("`{$gap['first_skipped']}`", $repository->executed[0]);
        $this->assertSame([['level' => 'warning', 'message' => $message, 'context' => $context]], $logger->records);
    }

    /**
     * @param  array<string, mixed>  $gap
     * @param  list<string>  $continued
     */
    #[DataProvider('startsPastAGap')]
    public function test_the_start_the_warning_names_leaves_no_gap(
        string $strategy,
        string $last,
        string $boundary,
        string $from,
        array $gap,
        string $message,
        array $continued,
    ): void {
        $repository = FakePartitionRepository::withPartitions([$last => $boundary, 'pMax' => 'MAXVALUE']);
        $logger = new FakeLogger;

        $builder = (new PartitionBuilder($repository, null, $logger))
            ->table('transactions')
            ->strategy($strategy, 'created_at')
            ->between((string) $gap['continue_at'], $from);

        $this->assertNull($builder->gap());
        $this->assertSame(
            $continued,
            $builder->missing()->map(fn (PendingPartition $pending) => $pending->name)->all()
        );

        $builder->sync();

        $this->assertCount(1, $repository->executed);
        $this->assertSame([], $logger->records);
    }

    public function test_without_an_explicit_start_there_is_no_gap_to_warn_about(): void
    {
        $repository = FakePartitionRepository::withPartitions(['p20250605' => '739773', 'pMax' => 'MAXVALUE']);
        $logger = new FakeLogger;

        $builder = (new PartitionBuilder($repository, null, $logger))
            ->table('transactions')
            ->daily('created_at')
            ->to('2025-06-14');

        $this->assertNull($builder->gap());
        $this->assertCount(9, $builder->missing());

        $builder->sync();

        $this->assertSame([], $logger->records);
    }

    public function test_a_start_under_the_last_partition_skips_nothing(): void
    {
        $repository = FakePartitionRepository::withPartitions(['p20250620' => '739788', 'pMax' => 'MAXVALUE']);

        $builder = $this->builder($repository)
            ->table('transactions')
            ->daily('created_at')
            ->between('2025-06-10', '2025-06-22');

        $this->assertNull($builder->gap());
        $this->assertSame(
            ['p20250621', 'p20250622'],
            $builder->missing()->map(fn (PendingPartition $pending) => $pending->name)->all()
        );
    }

    public function test_with_gap_filling_off_a_start_past_the_last_partition_is_used_as_given_in_silence(): void
    {
        $repository = FakePartitionRepository::withPartitions(['p20250620' => '739788', 'pMax' => 'MAXVALUE']);
        $logger = new FakeLogger;

        $builder = (new PartitionBuilder($repository, null, $logger))
            ->table('transactions')
            ->daily('created_at')
            ->fillGaps(false)
            ->between('2025-06-25', '2025-06-26');

        $this->assertNull($builder->gap());
        $this->assertSame(
            ['p20250625', 'p20250626'],
            $builder->missing()->map(fn (PendingPartition $pending) => $pending->name)->all()
        );

        $builder->sync();

        $this->assertCount(1, $repository->executed);
        $this->assertSame([], $logger->records);
    }

    public function test_a_dry_run_describes_the_gap_and_logs_nothing(): void
    {
        $repository = FakePartitionRepository::withPartitions(['p20250620' => '739788', 'pMax' => 'MAXVALUE']);
        $logger = new FakeLogger;

        $builder = (new PartitionBuilder($repository, null, $logger))
            ->table('transactions')
            ->daily('created_at')
            ->between('2025-06-25', '2025-06-26')
            ->dryRun();

        $statements = $builder->sync();

        $this->assertCount(1, $statements);
        $this->assertSame(4, $builder->gap()?->skipped);
        $this->assertSame([], $repository->executed);
        $this->assertSame([], $logger->records);
    }

    public function test_a_start_past_a_gap_goes_without_a_logger(): void
    {
        $repository = FakePartitionRepository::withPartitions(['p20250620' => '739788', 'pMax' => 'MAXVALUE']);

        $this->builder($repository)
            ->table('transactions')
            ->daily('created_at')
            ->between('2025-06-25', '2025-06-26')
            ->sync();

        $this->assertSame([<<<'SQL'
            ALTER TABLE `transactions`
            REORGANIZE PARTITION `pMax` INTO (
                PARTITION `p20250625` VALUES LESS THAN (739793),
                PARTITION `p20250626` VALUES LESS THAN (739794),
                PARTITION `pMax` VALUES LESS THAN MAXVALUE
            )
            SQL], $repository->executed);
    }

    public function test_a_start_past_a_gap_is_logged_on_a_table_without_a_max_partition(): void
    {
        $repository = FakePartitionRepository::withPartitions(['p20250620' => '739788']);
        $logger = new FakeLogger;

        (new PartitionBuilder($repository, null, $logger))
            ->table('transactions')
            ->daily('created_at')
            ->withoutMaxPartition()
            ->between('2025-06-25', '2025-06-26')
            ->sync();

        $this->assertSame([<<<'SQL'
            ALTER TABLE `transactions`
            ADD PARTITION (
                PARTITION `p20250625` VALUES LESS THAN (739793),
                PARTITION `p20250626` VALUES LESS THAN (739794)
            )
            SQL], $repository->executed);
        $this->assertCount(1, $logger->records);
        $this->assertStringContainsString('[p20250621] to [p20250624], after [p20250620]', $logger->records[0]['message']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function boundariesOfTheTwentiethOfJune(): array
    {
        return [
            'to_days' => ['to_days', '739788'],
            'columns' => ['columns', "'2025-06-21'"],
            'unix_timestamp' => ['unix_timestamp', '1750464000'],
        ];
    }

    #[DataProvider('boundariesOfTheTwentiethOfJune')]
    public function test_a_gap_is_measured_from_the_last_boundary_whatever_the_expression(
        string $expression,
        string $boundary,
    ): void {
        $repository = FakePartitionRepository::withPartitions(['p20250620' => $boundary, 'pMax' => 'MAXVALUE']);

        $builder = fn () => $this->builder($repository)
            ->table('transactions')
            ->daily('created_at')
            ->expression($expression)
            ->timezone('UTC');

        $this->assertNull($builder()->between('2025-06-21', '2025-06-22')->gap());

        $gap = $builder()->between('2025-06-22', '2025-06-22')->gap();

        $this->assertSame(1, $gap?->skipped);
        $this->assertSame('p20250621', $gap?->firstSkipped);
        $this->assertSame('p20250622', $gap?->holder);
    }

    public function test_a_table_holding_its_max_partition_alone_has_no_gap_to_skip(): void
    {
        $repository = FakePartitionRepository::withPartitions(['pMax' => 'MAXVALUE']);

        $builder = $this->builder($repository)
            ->table('transactions')
            ->daily('created_at')
            ->between('2025-06-25', '2025-06-26');

        $this->assertNull($builder->gap());
        $this->assertSame(
            ['p20250625', 'p20250626'],
            $builder->missing()->map(fn (PendingPartition $pending) => $pending->name)->all()
        );
    }

    public function test_boundaries_are_compared_by_date_not_by_timezone(): void
    {
        $timezone = date_default_timezone_get();
        date_default_timezone_set('America/New_York');

        try {
            $repository = FakePartitionRepository::withPartitions([
                'p_legacy' => '739781',
                'pMax' => 'MAXVALUE',
            ]);

            $missing = $this->builder($repository)
                ->table('transactions')
                ->daily('created_at')
                ->from('2025-06-13')
                ->future(2)
                ->missing()
                ->map(fn (PendingPartition $pending) => $pending->name)
                ->all();

            $this->assertSame(['p20250614', 'p20250615'], $missing);
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    public function test_the_partition_list_is_read_once_per_operation(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p20250613' => '739781',
            'pMax' => 'MAXVALUE',
        ]);

        $builder = $this->builder($repository)->table('transactions')->daily('created_at')->future(1);

        $builder->sync();

        $this->assertSame(1, $repository->reads);

        $builder->existing();

        $this->assertSame(2, $repository->reads);
    }

    public function test_changing_the_target_discards_the_cached_list(): void
    {
        $repository = FakePartitionRepository::withPartitions(['p20250613' => '739781']);

        $builder = $this->builder($repository)->table('transactions')->daily('created_at');
        $builder->existing();
        $builder->table('other_logs');
        $builder->existing();

        $this->assertSame(2, $repository->reads);
    }

    public function test_it_reports_missing_partitions_for_a_date(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p20250613' => '739781',
            'pMax' => 'MAXVALUE',
        ]);

        $builder = $this->builder($repository)->table('transactions')->daily('created_at');

        $this->assertTrue($builder->has('2025-06-13 08:00:00'));
        $this->assertFalse($builder->has('2025-06-14'));
        $this->assertSame('p20250614', $builder->partitionNameFor('2025-06-14 23:59:59'));
        $this->assertTrue($builder->hasMaxPartition());
    }

    protected function mismatchedRepository(): FakePartitionRepository
    {
        return FakePartitionRepository::withPartitions(
            [
                'p20250609' => '1749427200',
                'p20250610' => '1749513600',
                'p20250611' => '1749600000',
                'p20250612' => '1749686400',
                'p20250613' => '1749772800',
                'pMax' => 'MAXVALUE',
            ],
            method: 'RANGE',
            expression: 'unix_timestamp(`other`)',
        );
    }

    public function test_prune_refuses_a_table_partitioned_differently(): void
    {
        $repository = $this->mismatchedRepository();

        $builder = $this->builder($repository)
            ->table('transactions')
            ->daily('created_at')
            ->retain(2);

        $this->expectException(ExpressionMismatchException::class);
        $this->expectExceptionMessage(
            'Table [transactions] is partitioned by [RANGE (unix_timestamp(other))] '
            .'but the builder is configured for [RANGE (to_days(created_at))]'
        );

        try {
            $builder->prune();
        } finally {
            $this->assertSame([], $repository->executed);
        }
    }

    public function test_drop_before_refuses_a_table_partitioned_differently(): void
    {
        $repository = $this->mismatchedRepository();

        $builder = $this->builder($repository)->table('transactions')->daily('created_at');

        try {
            $builder->dropBefore('2025-06-11');
            $this->fail('Expected an ExpressionMismatchException.');
        } catch (ExpressionMismatchException) {
            $this->assertSame([], $repository->executed);
        }
    }

    public function test_drop_between_refuses_a_table_partitioned_differently(): void
    {
        $repository = $this->mismatchedRepository();

        $builder = $this->builder($repository)->table('transactions')->daily('created_at');

        try {
            $builder->dropBetween('2025-06-01', '2025-06-30');
            $this->fail('Expected an ExpressionMismatchException.');
        } catch (ExpressionMismatchException) {
            $this->assertSame([], $repository->executed);
        }
    }

    public function test_sync_refuses_a_table_partitioned_differently(): void
    {
        $repository = $this->mismatchedRepository();

        $builder = $this->builder($repository)->table('transactions')->daily('created_at');

        try {
            $builder->sync();
            $this->fail('Expected an ExpressionMismatchException.');
        } catch (ExpressionMismatchException) {
            $this->assertSame([], $repository->executed);
        }
    }

    public function test_maintain_refuses_a_table_partitioned_differently(): void
    {
        $repository = $this->mismatchedRepository();

        $builder = $this->builder($repository)
            ->table('transactions')
            ->daily('created_at')
            ->retain(2);

        try {
            $builder->maintain();
            $this->fail('Expected an ExpressionMismatchException.');
        } catch (ExpressionMismatchException) {
            $this->assertSame([], $repository->executed);
        }
    }

    public function test_the_guard_also_catches_a_matching_expression_on_another_column(): void
    {
        $repository = FakePartitionRepository::withPartitions(
            [
                'p20250609' => '739777',
                'p20250613' => '739781',
                'pMax' => 'MAXVALUE',
            ],
            expression: 'to_days(`other`)',
        );

        $builder = $this->builder($repository)
            ->table('transactions')
            ->daily('created_at')
            ->retain(2);

        try {
            $builder->prune();
            $this->fail('Expected an ExpressionMismatchException.');
        } catch (ExpressionMismatchException) {
            $this->assertSame([], $repository->executed);
        }
    }

    public function test_the_guard_lets_a_matching_table_through_on_the_destructive_paths(): void
    {
        $repository = FakePartitionRepository::withPartitions(
            [
                'p20250609' => '739777',
                'p20250610' => '739778',
                'p20250613' => '739781',
                'pMax' => 'MAXVALUE',
            ],
            expression: 'to_days(`created_at`)',
        );

        $statements = $this->builder($repository)
            ->table('transactions')
            ->daily('created_at')
            ->retain(2)
            ->prune();

        $this->assertSame([
            'ALTER TABLE `transactions` DROP PARTITION `p20250609`',
            'ALTER TABLE `transactions` DROP PARTITION `p20250610`',
        ], $statements->all());
    }

    public function test_the_guard_does_not_read_the_table_when_there_is_no_retention(): void
    {
        $repository = $this->mismatchedRepository();

        $statements = $this->builder($repository)
            ->table('transactions')
            ->daily('created_at')
            ->prune();

        $this->assertTrue($statements->isEmpty());
        $this->assertSame(0, $repository->reads);
        $this->assertSame([], $repository->executed);
    }

    /**
     * @return array<string, string|null>
     */
    protected function eighteenMonthGap(bool $withMaxPartition = true): array
    {
        $partitions = ['p202312' => '739251'];

        return $withMaxPartition ? $partitions + ['pMax' => 'MAXVALUE'] : $partitions;
    }

    /**
     * @param  iterable<int, string>  $statements
     * @return array<int, array<int, string>>
     */
    protected function partitionNames(iterable $statements): array
    {
        $names = [];

        foreach ($statements as $sql) {
            preg_match_all('/PARTITION `(\w+)`/', $sql, $matches);
            $names[] = $matches[1];
        }

        return $names;
    }

    public function test_additions_are_split_into_chunks(): void
    {
        $repository = FakePartitionRepository::withPartitions($this->eighteenMonthGap());

        $statements = $this->builder($repository)
            ->table('transactions')
            ->monthly('created_at')
            ->to('2025-06-01')
            ->maxGaps(null)
            ->syncChunk(6)
            ->sync();

        $this->assertCount(3, $statements);
        $this->assertSame([
            ['pMax', 'p202401', 'p202402', 'p202403', 'p202404', 'p202405', 'p202406', 'pMax'],
            ['pMax', 'p202407', 'p202408', 'p202409', 'p202410', 'p202411', 'p202412', 'pMax'],
            ['pMax', 'p202501', 'p202502', 'p202503', 'p202504', 'p202505', 'p202506', 'pMax'],
        ], $this->partitionNames($statements));

        foreach ($statements as $sql) {
            $this->assertStringEndsWith("    PARTITION `pMax` VALUES LESS THAN MAXVALUE\n)", $sql);
        }

        $this->assertSame($statements->all(), $repository->executed);
    }

    public function test_the_first_chunk_starts_where_the_table_stopped(): void
    {
        $repository = FakePartitionRepository::withPartitions($this->eighteenMonthGap());

        $statements = $this->builder($repository)
            ->table('transactions')
            ->monthly('created_at')
            ->to('2025-06-01')
            ->maxGaps(null)
            ->syncChunk(6)
            ->syncStatements();

        $this->assertSame(<<<'SQL'
            ALTER TABLE `transactions`
            REORGANIZE PARTITION `pMax` INTO (
                PARTITION `p202401` VALUES LESS THAN (739282),
                PARTITION `p202402` VALUES LESS THAN (739311),
                PARTITION `p202403` VALUES LESS THAN (739342),
                PARTITION `p202404` VALUES LESS THAN (739372),
                PARTITION `p202405` VALUES LESS THAN (739403),
                PARTITION `p202406` VALUES LESS THAN (739433),
                PARTITION `pMax` VALUES LESS THAN MAXVALUE
            )
            SQL, $statements->first());
    }

    /**
     * @return array<string, array{?int, list<int>}>
     */
    public static function syncChunkSizes(): array
    {
        return [
            'not set, all in one statement' => [null, [18]],
            'zero, all in one statement' => [0, [18]],
            'one per statement' => [1, array_fill(0, 18, 1)],
            'two per statement' => [2, array_fill(0, 9, 2)],
        ];
    }

    /**
     * @param  list<int>  $sizes
     */
    #[DataProvider('syncChunkSizes')]
    public function test_the_sync_chunk_sets_the_partitions_added_per_statement(?int $size, array $sizes): void
    {
        $repository = FakePartitionRepository::withPartitions($this->eighteenMonthGap());

        $statements = $this->builder($repository)
            ->table('transactions')
            ->monthly('created_at')
            ->to('2025-06-01')
            ->maxGaps(null)
            ->syncChunk($size)
            ->syncStatements();

        $this->assertSame($sizes, array_map(
            fn (array $names) => count(array_diff($names, ['pMax'])),
            $this->partitionNames($statements)
        ));
    }

    public function test_the_drop_chunk_leaves_the_additions_in_one_statement(): void
    {
        $repository = FakePartitionRepository::withPartitions($this->eighteenMonthGap());

        $statements = $this->builder($repository)
            ->table('transactions')
            ->monthly('created_at')
            ->to('2025-06-01')
            ->maxGaps(null)
            ->chunk(1)
            ->syncStatements();

        $this->assertCount(1, $statements);
    }

    public function test_additions_are_chunked_without_a_max_partition_too(): void
    {
        $repository = FakePartitionRepository::withPartitions($this->eighteenMonthGap(withMaxPartition: false));

        $statements = $this->builder($repository)
            ->table('transactions')
            ->monthly('created_at')
            ->to('2025-06-01')
            ->withoutMaxPartition()
            ->maxGaps(null)
            ->syncChunk(9)
            ->sync();

        $this->assertCount(2, $statements);
        $this->assertStringStartsWith("ALTER TABLE `transactions`\nADD PARTITION (", $statements->first());
        $this->assertSame([
            ['p202401', 'p202402', 'p202403', 'p202404', 'p202405', 'p202406', 'p202407', 'p202408', 'p202409'],
            ['p202410', 'p202411', 'p202412', 'p202501', 'p202502', 'p202503', 'p202504', 'p202505', 'p202506'],
        ], $this->partitionNames($statements));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function failingChunks(): array
    {
        return [
            'the first statement' => [1],
            'a statement in the middle' => [2],
            'the last statement' => [3],
        ];
    }

    #[DataProvider('failingChunks')]
    public function test_after_a_failed_chunk_the_same_builder_sends_only_what_is_still_missing(int $failing): void
    {
        $repository = FakePartitionRepository::withPartitions($this->eighteenMonthGap());

        $builder = $this->builder($repository)
            ->table('transactions')
            ->monthly('created_at')
            ->to('2025-06-01')
            ->maxGaps(null)
            ->syncChunk(6);

        $planned = $builder->syncStatements()->all();
        $repository->failingStatement = $failing;

        try {
            $builder->sync();
            $this->fail('sync() was expected to throw.');
        } catch (RuntimeException) {
        }

        $this->assertSame(array_slice($planned, 0, $failing - 1), $repository->executed);

        $repository->partitions = FakePartitionRepository::withPartitions($this->applied($repository))->partitions;

        $this->assertSame(array_slice($planned, $failing - 1), $builder->sync()->all());
        $this->assertSame($planned, $repository->executed);
    }

    public function test_a_failed_statement_discards_the_cached_list(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p20250601' => '739768',
            'p20250602' => '739769',
            'p20250603' => '739770',
            'pMax' => 'MAXVALUE',
        ]);
        $repository->failingStatement = 2;

        $builder = $this->builder($repository)->table('transactions')->daily('created_at')->chunk(1);

        try {
            $builder->drop($builder->existing()->take(3));
            $this->fail('drop() was expected to throw.');
        } catch (RuntimeException) {
        }

        $builder->existing();

        $this->assertSame(2, $repository->reads);
    }

    /**
     * @return array<string, string>
     */
    protected function applied(FakePartitionRepository $repository): array
    {
        $partitions = ['p202312' => '739251'];

        foreach ($repository->executed as $sql) {
            preg_match_all('/PARTITION `(\w+)` VALUES LESS THAN \((\d+)\)/', $sql, $matches);
            $partitions += array_combine($matches[1], $matches[2]);
        }

        return $partitions + ['pMax' => 'MAXVALUE'];
    }

    public function test_the_gap_limit_fills_the_oldest_periods_first(): void
    {
        $repository = FakePartitionRepository::withPartitions($this->eighteenMonthGap());

        $builder = $this->builder($repository)
            ->table('transactions')
            ->monthly('created_at')
            ->to('2025-06-01')
            ->maxGaps(6);

        $this->assertSame(18, $builder->missing()->count());
        $this->assertSame(
            ['p202401', 'p202402', 'p202403', 'p202404', 'p202405', 'p202406'],
            $builder->pending()->map(fn (PendingPartition $partition) => $partition->name)->all()
        );
        $this->assertSame(12, $builder->deferred()->count());
        $this->assertSame('p202407', $builder->deferred()->first()?->name);

        $statements = $builder->sync();

        $this->assertCount(1, $statements);
        $this->assertSame(
            ['pMax', 'p202401', 'p202402', 'p202403', 'p202404', 'p202405', 'p202406', 'pMax'],
            $this->partitionNames($statements)[0]
        );
    }

    public function test_twelve_gaps_are_filled_per_run_by_default(): void
    {
        $repository = FakePartitionRepository::withPartitions($this->eighteenMonthGap());

        $builder = $this->builder($repository)
            ->table('transactions')
            ->monthly('created_at')
            ->to('2025-06-01');

        $this->assertSame(12, $builder->pending()->count());
        $this->assertSame(6, $builder->deferred()->count());
    }

    public function test_the_gap_limit_can_be_lifted(): void
    {
        $repository = FakePartitionRepository::withPartitions($this->eighteenMonthGap());

        $builder = $this->builder($repository)
            ->table('transactions')
            ->monthly('created_at')
            ->to('2025-06-01')
            ->maxGaps(0);

        $this->assertSame(18, $builder->pending()->count());
        $this->assertTrue($builder->deferred()->isEmpty());
    }

    public function test_a_plan_splits_the_missing_partitions_at_the_gap_limit(): void
    {
        $repository = FakePartitionRepository::withPartitions($this->eighteenMonthGap());

        $plan = $this->builder($repository)
            ->table('transactions')
            ->monthly('created_at')
            ->to('2025-06-01')
            ->maxGaps(6)
            ->plan();

        $this->assertSame(
            ['p202401', 'p202402', 'p202403', 'p202404', 'p202405', 'p202406'],
            $plan->pending->map(fn (PendingPartition $pending) => $pending->name)->all()
        );
        $this->assertSame(12, $plan->deferred->count());
        $this->assertSame('p202407', $plan->deferred->first()?->name);
    }

    public function test_syncing_plans_the_partitions_once(): void
    {
        CountingDailyStrategy::$plans = 0;
        $repository = FakePartitionRepository::withPartitions(['p20250613' => '739781', 'pMax' => 'MAXVALUE']);

        $this->builder($repository)
            ->table('transactions')
            ->strategy(CountingDailyStrategy::class, 'created_at')
            ->future(2)
            ->sync();

        $this->assertSame(1, CountingDailyStrategy::$plans);
        $this->assertCount(1, $repository->executed);
    }

    public function test_syncing_a_plan_adds_what_it_lists_without_planning_again(): void
    {
        CountingDailyStrategy::$plans = 0;
        $repository = FakePartitionRepository::withPartitions(['p20250613' => '739781', 'pMax' => 'MAXVALUE']);

        $builder = $this->builder($repository)
            ->table('transactions')
            ->strategy(CountingDailyStrategy::class, 'created_at')
            ->future(2);

        $statements = $builder->sync($builder->plan());

        $this->assertSame(1, CountingDailyStrategy::$plans);
        $this->assertSame([
            'ALTER TABLE `transactions`
REORGANIZE PARTITION `pMax` INTO (
    PARTITION `p20250614` VALUES LESS THAN (739782),
    PARTITION `p20250615` VALUES LESS THAN (739783),
    PARTITION `pMax` VALUES LESS THAN MAXVALUE
)',
        ], $statements->all());
        $this->assertSame($statements->all(), $repository->executed);
    }

    public function test_creating_plans_the_partitions_once(): void
    {
        CountingDailyStrategy::$plans = 0;
        $repository = new FakePartitionRepository;

        $this->builder($repository)
            ->table('transactions')
            ->strategy(CountingDailyStrategy::class, 'created_at')
            ->future(2)
            ->create();

        $this->assertSame(1, CountingDailyStrategy::$plans);
        $this->assertCount(1, $repository->executed);
    }

    public function test_the_created_event_only_reports_the_partitions_that_were_added(): void
    {
        $repository = FakePartitionRepository::withPartitions($this->eighteenMonthGap());
        $events = new FakeDispatcher;

        (new PartitionBuilder($repository, $events))
            ->table('transactions')
            ->monthly('created_at')
            ->to('2025-06-01')
            ->maxGaps(6)
            ->sync();

        $event = $events->firstOf(PartitionsCreated::class);

        $this->assertInstanceOf(PartitionsCreated::class, $event);
        $this->assertSame(
            ['p202401', 'p202402', 'p202403', 'p202404', 'p202405', 'p202406'],
            $event->partitions
        );
    }

    public function test_truncating_partitions_reports_what_it_emptied(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p20250611' => '739779',
            'p20250612' => '739780',
            'p20250613' => '739781',
            'pMax' => 'MAXVALUE',
        ]);
        $events = new FakeDispatcher;

        $builder = (new PartitionBuilder($repository, $events))->table('transactions')->daily('created_at');

        $statements = $builder->truncate($builder->outdated('2025-06-13'));

        $this->assertSame([
            'ALTER TABLE `transactions` TRUNCATE PARTITION `p20250611`',
            'ALTER TABLE `transactions` TRUNCATE PARTITION `p20250612`',
        ], $statements->all());
        $this->assertSame($statements->all(), $repository->executed);

        $event = $events->firstOf(PartitionsTruncated::class);

        $this->assertInstanceOf(PartitionsTruncated::class, $event);
        $this->assertSame('transactions', $event->table);
        $this->assertNull($event->connection);
        $this->assertSame(['p20250611', 'p20250612'], $event->partitions);
        $this->assertSame($statements->all(), $event->statements);
    }

    public function test_dropping_the_given_partitions_reports_what_it_dropped(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p20250611' => '739779',
            'p20250612' => '739780',
            'p20250613' => '739781',
            'pMax' => 'MAXVALUE',
        ]);
        $events = new FakeDispatcher;

        $builder = (new PartitionBuilder($repository, $events))->table('transactions')->daily('created_at');

        $statements = $builder->drop($builder->outdated('2025-06-13')->all());

        $this->assertSame([
            'ALTER TABLE `transactions` DROP PARTITION `p20250611`',
            'ALTER TABLE `transactions` DROP PARTITION `p20250612`',
        ], $statements->all());
        $this->assertSame($statements->all(), $repository->executed);

        $event = $events->firstOf(PartitionsDropped::class);

        $this->assertInstanceOf(PartitionsDropped::class, $event);
        $this->assertSame(['p20250611', 'p20250612'], $event->partitions);
        $this->assertSame($statements->all(), $event->statements);
    }

    public function test_dropping_nothing_fires_no_event(): void
    {
        $repository = FakePartitionRepository::withPartitions(['p20250613' => '739781']);
        $events = new FakeDispatcher;

        $statements = (new PartitionBuilder($repository, $events))
            ->table('transactions')
            ->daily('created_at')
            ->drop([]);

        $this->assertTrue($statements->isEmpty());
        $this->assertSame([], $repository->executed);
        $this->assertSame([], $events->dispatched);
    }

    public function test_truncating_nothing_fires_no_event(): void
    {
        $repository = FakePartitionRepository::withPartitions(['p20250613' => '739781']);
        $events = new FakeDispatcher;

        $statements = (new PartitionBuilder($repository, $events))
            ->table('transactions')
            ->daily('created_at')
            ->truncate([]);

        $this->assertTrue($statements->isEmpty());
        $this->assertSame([], $repository->executed);
        $this->assertSame([], $events->dispatched);
    }

    public function test_a_truncate_dry_run_executes_nothing_and_fires_nothing(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p20250612' => '739780',
            'p20250613' => '739781',
        ]);
        $events = new FakeDispatcher;

        $builder = (new PartitionBuilder($repository, $events))
            ->table('transactions')
            ->daily('created_at')
            ->dryRun();

        $statements = $builder->truncate($builder->existing());

        $this->assertCount(2, $statements);
        $this->assertSame([], $repository->executed);
        $this->assertSame([], $events->dispatched);
    }

    public function test_removing_partitioning_reports_the_partitions_it_dissolved(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p20250613' => '739781',
            'pMax' => 'MAXVALUE',
        ]);
        $events = new FakeDispatcher;

        $statements = (new PartitionBuilder($repository, $events))
            ->table('transactions')
            ->daily('created_at')
            ->removePartitioning();

        $this->assertSame(['ALTER TABLE `transactions` REMOVE PARTITIONING'], $statements->all());
        $this->assertSame($statements->all(), $repository->executed);

        $event = $events->firstOf(PartitioningRemoved::class);

        $this->assertInstanceOf(PartitioningRemoved::class, $event);
        $this->assertSame('transactions', $event->table);
        $this->assertSame(['p20250613', 'pMax'], $event->partitions);
        $this->assertSame($statements->all(), $event->statements);
    }

    public function test_removing_partitioning_from_an_unpartitioned_table_throws(): void
    {
        $repository = new FakePartitionRepository;
        $events = new FakeDispatcher;

        $builder = (new PartitionBuilder($repository, $events))->table('transactions')->daily('created_at');

        try {
            $builder->removePartitioning();
            $this->fail('removePartitioning() was expected to throw.');
        } catch (NotPartitionedException $exception) {
            $this->assertStringContainsString('has nothing to remove', $exception->getMessage());
        }

        $this->assertSame([], $repository->executed);
        $this->assertSame([], $events->dispatched);
    }

    public function test_a_remove_partitioning_dry_run_refuses_an_unpartitioned_table_too(): void
    {
        $repository = new FakePartitionRepository;
        $events = new FakeDispatcher;

        $builder = (new PartitionBuilder($repository, $events))->table('transactions')->daily('created_at')->dryRun();

        try {
            $builder->removePartitioning();
            $this->fail('A dry run of removePartitioning() was expected to throw.');
        } catch (NotPartitionedException $exception) {
            $this->assertStringContainsString('has nothing to remove', $exception->getMessage());
        }

        $this->assertSame([], $repository->executed);
        $this->assertSame([], $events->dispatched);
    }

    public function test_a_remove_partitioning_dry_run_returns_the_sql_for_a_partitioned_table(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p20250613' => '739781',
            'pMax' => 'MAXVALUE',
        ]);
        $events = new FakeDispatcher;

        $statements = (new PartitionBuilder($repository, $events))
            ->table('transactions')
            ->daily('created_at')
            ->dryRun()
            ->removePartitioning();

        $this->assertSame(['ALTER TABLE `transactions` REMOVE PARTITIONING'], $statements->all());
        $this->assertSame([], $repository->executed);
        $this->assertSame([], $events->dispatched);
    }

    public function test_sync_refuses_to_run_without_the_max_partition_the_table_already_has(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p20250613' => '739781',
            'pMax' => 'MAXVALUE',
        ]);

        $builder = $this->builder($repository)
            ->table('transactions')
            ->daily('created_at')
            ->withoutMaxPartition()
            ->to('2025-06-15');

        try {
            $builder->sync();
            $this->fail('sync() was expected to throw.');
        } catch (MaxPartitionConflictException $exception) {
            $this->assertStringContainsString('has the MAXVALUE partition [pMax]', $exception->getMessage());
            $this->assertStringContainsString('withMaxPartition()', $exception->getMessage());
        }

        $this->assertSame([], $repository->executed);
    }

    public function test_the_max_partition_conflict_surfaces_in_dry_run_too(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p20250613' => '739781',
            'p_overflow' => 'MAXVALUE',
        ]);

        $this->expectException(MaxPartitionConflictException::class);
        $this->expectExceptionMessage('has the MAXVALUE partition [p_overflow]');

        $this->builder($repository)
            ->table('transactions')
            ->daily('created_at')
            ->withoutMaxPartition()
            ->to('2025-06-15')
            ->dryRun()
            ->syncStatements();
    }

    public function test_withoutmaxpartition_is_honoured_when_the_table_has_no_max_partition(): void
    {
        $repository = FakePartitionRepository::withPartitions(['p20250613' => '739781']);

        $statements = $this->builder($repository)
            ->table('transactions')
            ->daily('created_at')
            ->withoutMaxPartition()
            ->to('2025-06-14')
            ->sync();

        $this->assertSame([<<<'SQL'
            ALTER TABLE `transactions`
            ADD PARTITION (
                PARTITION `p20250614` VALUES LESS THAN (739782)
            )
            SQL], $statements->all());
    }

    public function test_a_table_name_gets_the_prefix_of_the_connection(): void
    {
        $repository = FakePartitionRepository::withPartitions(['p20250613' => '739781', 'pMax' => 'MAXVALUE']);
        $repository->tablePrefix = 'app_';

        $builder = $this->builder($repository)->table('transactions')->daily('created_at')->future(1);

        self::assertStringStartsWith("ALTER TABLE `app_transactions`\n", (string) $builder->syncStatements()->first());
        self::assertSame([[null, 'app_transactions']], $repository->requested);
        self::assertSame('transactions', $builder->getTable());
    }

    public function test_the_table_prefix_goes_after_the_schema_of_a_qualified_name(): void
    {
        $repository = FakePartitionRepository::withPartitions(['p20250613' => '739781', 'pMax' => 'MAXVALUE']);
        $repository->tablePrefix = 'app_';

        $builder = $this->builder($repository)->table('reporting.transactions')->daily('created_at')->future(1);

        self::assertStringStartsWith("ALTER TABLE `reporting`.`app_transactions`\n", (string) $builder->syncStatements()->first());
        self::assertSame([[null, 'reporting.app_transactions']], $repository->requested);
    }

    public function test_a_model_reaches_the_same_prefixed_table_as_its_name(): void
    {
        $model = new class extends Model
        {
            protected $table = 'transactions';

            protected $connection = 'archive';
        };

        $repository = new FakePartitionRepository;
        $repository->tablePrefixes = ['archive' => 'app_'];

        $this->builder($repository)->table($model)->existing();
        $this->builder($repository)->table($model::class)->existing();
        $this->builder($repository)->connection('archive')->table('transactions')->existing();

        self::assertSame([
            ['archive', 'app_transactions'],
            ['archive', 'app_transactions'],
            ['archive', 'app_transactions'],
        ], $repository->requested);
    }

    public function test_the_table_prefix_follows_the_connection_the_builder_runs_on(): void
    {
        $repository = new FakePartitionRepository;
        $repository->tablePrefix = 'app_';
        $repository->tablePrefixes = ['archive' => 'old_'];

        $builder = $this->builder($repository)->table('transactions');
        $builder->existing();
        $builder->connection('archive')->existing();

        self::assertSame([[null, 'app_transactions'], ['archive', 'old_transactions']], $repository->requested);
    }

    public function test_events_name_the_table_as_it_was_given(): void
    {
        $repository = FakePartitionRepository::withPartitions(['p20250613' => '739781', 'pMax' => 'MAXVALUE']);
        $repository->tablePrefix = 'app_';
        $events = new FakeDispatcher;

        (new PartitionBuilder($repository, $events))->table('transactions')->daily('created_at')->future(1)->sync();

        $event = $events->firstOf(PartitionsCreated::class);

        self::assertInstanceOf(PartitionsCreated::class, $event);
        self::assertSame('transactions', $event->table);
        self::assertStringStartsWith("ALTER TABLE `app_transactions`\n", $event->statements[0]);
    }
}

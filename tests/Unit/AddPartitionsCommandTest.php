<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Unit;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use PHPUnit\Framework\Attributes\DataProvider;
use Yurkovskiy\Partitions\Console\AddPartitionsCommand;
use Yurkovskiy\Partitions\Exceptions\InvalidRangeException;
use Yurkovskiy\Partitions\Exceptions\StrategyMismatchException;
use Yurkovskiy\Partitions\PartitionManager;
use Yurkovskiy\Partitions\Schema\TableInfo;
use Yurkovskiy\Partitions\Tests\Support\CommandTestCase;
use Yurkovskiy\Partitions\Tests\Support\CountingDailyStrategy;
use Yurkovskiy\Partitions\Tests\Support\FakeLogger;
use Yurkovskiy\Partitions\Tests\Support\FakePartitionRepository;

class AddPartitionsCommandTest extends CommandTestCase
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

    /**
     * @param  array<string, mixed>  $config
     */
    protected function command(
        FakePartitionRepository $repository,
        array $config = [],
        ?FakeLogger $logger = null,
    ): AddPartitionsCommand {
        return $this->withManager(new AddPartitionsCommand, new PartitionManager($repository, null, $config, $logger));
    }

    protected function gapWarning(): string
    {
        return 'The start date 2025-06-25 leaves 4 periods of table [transactions] without a partition of their own '
            .'— [p20250621] to [p20250624], after [p20250620]. Their rows land in [p20250625] and are dropped '
            .'together with it. Start at 2025-06-21, or leave the start date out, to give each period its partition.';
    }

    protected function endingAt(string $partition, string $boundary): FakePartitionRepository
    {
        return FakePartitionRepository::withPartitions([
            $partition => $boundary,
            'pMax' => 'MAXVALUE',
        ], expression: 'to_days(`created_at`)');
    }

    /**
     * @return array<string, array{array<string, mixed>, bool}>
     */
    public static function runsWithoutCreate(): array
    {
        return [
            'interactive' => [[], true],
            'non-interactive' => [[], false],
            'forced' => [['--force' => true], false],
            'dry run' => [['--dry-run' => true], true],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    #[DataProvider('runsWithoutCreate')]
    public function test_an_unpartitioned_table_is_refused_without_create(array $options, bool $interactive): void
    {
        $repository = new FakePartitionRepository;

        $tester = $this->runCommand(
            $this->command($repository),
            ['table' => 'transactions', '--column' => 'created_at', ...$options],
            interactive: $interactive,
        );

        $output = $tester->getDisplay();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Table [transactions] is not partitioned yet. Pass --create to partition it.', $output);
        self::assertStringContainsString('rebuilds the whole table', $output);
        self::assertStringNotContainsString('Rebuild [transactions]', $output);
        self::assertStringNotContainsString('PARTITION BY RANGE', $output);
        self::assertSame([], $repository->executed);
    }

    public function test_adding_plans_the_partitions_once(): void
    {
        CountingDailyStrategy::$plans = 0;
        $repository = $this->endingAt('p20250610', '739778');

        $tester = $this->runCommand($this->command($repository, ['tables' => [
            'transactions' => ['column' => 'created_at', 'strategy' => CountingDailyStrategy::class, 'future' => 2, 'max_gaps' => 1],
        ]]), ['table' => 'transactions']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Adding 1 of 5 missing partitions to [transactions]:', $tester->getDisplay());
        self::assertSame(1, CountingDailyStrategy::$plans);
        self::assertCount(1, $repository->executed);
    }

    public function test_partitioning_an_unpartitioned_table_asks_for_confirmation(): void
    {
        $repository = new FakePartitionRepository;

        $tester = $this->runCommand(
            $this->command($repository),
            ['table' => 'transactions', '--column' => 'created_at', '--create' => true],
            inputs: ['no'],
        );

        $output = $tester->getDisplay();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('is not partitioned yet', $output);
        self::assertStringContainsString('rebuilds the whole table', $output);
        self::assertStringContainsString('Rebuild [transactions] and partition it?', $output);
        self::assertStringContainsString('Aborted.', $output);
        self::assertSame([], $repository->executed);
    }

    public function test_confirming_rebuilds_the_table(): void
    {
        $repository = new FakePartitionRepository;

        $tester = $this->runCommand(
            $this->command($repository),
            ['table' => 'transactions', '--column' => 'created_at', '--create' => true],
            inputs: ['yes'],
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertCount(1, $repository->executed);
        self::assertStringStartsWith('ALTER TABLE `transactions`', $repository->executed[0]);
        self::assertStringContainsString('PARTITION BY RANGE (TO_DAYS(`created_at`))', $repository->executed[0]);
    }

    public function test_force_skips_the_confirmation(): void
    {
        $repository = new FakePartitionRepository;

        $tester = $this->runCommand(
            $this->command($repository),
            ['table' => 'transactions', '--column' => 'created_at', '--create' => true, '--force' => true],
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringNotContainsString('Rebuild [transactions]', $tester->getDisplay());
        self::assertCount(1, $repository->executed);
    }

    public function test_a_non_interactive_run_with_create_does_not_ask(): void
    {
        $repository = new FakePartitionRepository;

        $tester = $this->runCommand(
            $this->command($repository),
            ['table' => 'transactions', '--column' => 'created_at', '--create' => true],
            interactive: false,
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertCount(1, $repository->executed);
    }

    public function test_a_dry_run_neither_asks_nor_executes(): void
    {
        $repository = new FakePartitionRepository;

        $tester = $this->runCommand(
            $this->command($repository),
            ['table' => 'transactions', '--column' => 'created_at', '--create' => true, '--dry-run' => true],
        );

        $output = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringNotContainsString('Rebuild [transactions]', $output);
        self::assertStringContainsString('Dry run', $output);
        self::assertSame([], $repository->executed);
    }

    public function test_it_reports_what_the_rebuild_has_to_move(): void
    {
        $repository = new FakePartitionRepository([], new TableInfo(
            name: 'transactions',
            rows: 1234567,
            dataLength: 268435456,
            indexLength: 67108864,
        ));

        $tester = $this->runCommand(
            $this->command($repository),
            ['table' => 'transactions', '--column' => 'created_at', '--create' => true],
            inputs: ['no'],
        );

        $output = $tester->getDisplay();

        self::assertStringContainsString('~1,234,567 rows', $output);
        self::assertStringContainsString('320 MB on disk', $output);
    }

    public function test_the_planned_partitions_are_listed_before_the_question(): void
    {
        $repository = new FakePartitionRepository;

        $tester = $this->runCommand(
            $this->command($repository),
            ['table' => 'transactions', '--column' => 'created_at', '--create' => true, '--future' => '1'],
            inputs: ['no'],
        );

        $output = $tester->getDisplay();

        self::assertStringContainsString('Partitioning [transactions] into 2 partitions:', $output);
        self::assertStringContainsString('p20250613', $output);
        self::assertStringContainsString('p20250614', $output);
    }

    public function test_adding_partitions_to_a_partitioned_table_still_does_not_ask(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p20250613' => '739781',
            'pMax' => 'MAXVALUE',
        ], expression: 'to_days(`created_at`)');

        $tester = $this->runCommand(
            $this->command($repository),
            ['table' => 'transactions', '--column' => 'created_at', '--future' => '1'],
        );

        $output = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringNotContainsString('Rebuild [transactions]', $output);
        self::assertStringContainsString('Adding 1 partitions to [transactions]:', $output);
        self::assertCount(1, $repository->executed);
    }

    public function test_without_a_start_date_the_run_continues_after_the_last_partition(): void
    {
        $repository = $this->endingAt('p20250620', '739788');

        $tester = $this->runCommand($this->command($repository), ['table' => 'transactions', '--future' => '10']);

        $output = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Adding 3 partitions to [transactions]:', $output);
        self::assertStringNotContainsString('p20250613', $output);
        self::assertSame([<<<'SQL'
            ALTER TABLE `transactions`
            REORGANIZE PARTITION `pMax` INTO (
                PARTITION `p20250621` VALUES LESS THAN (739789),
                PARTITION `p20250622` VALUES LESS THAN (739790),
                PARTITION `p20250623` VALUES LESS THAN (739791),
                PARTITION `pMax` VALUES LESS THAN MAXVALUE
            )
            SQL], $repository->executed);
    }

    public function test_the_table_argument_gets_the_prefix_of_the_connection(): void
    {
        $repository = $this->endingAt('p20250613', '739781');
        $repository->tablePrefix = 'app_';

        $tester = $this->runCommand($this->command($repository), ['table' => 'transactions', '--future' => '1']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('partitions to [transactions]:', $tester->getDisplay());
        self::assertSame(['app_transactions'], array_values(array_unique(array_column($repository->requested, 1))));
        self::assertCount(1, $repository->executed);
        self::assertStringStartsWith("ALTER TABLE `app_transactions`\n", $repository->executed[0]);
    }

    public function test_a_table_that_fell_behind_is_continued_from_its_last_partition_not_from_today(): void
    {
        $repository = $this->endingAt('p20250609', '739777');

        $tester = $this->runCommand($this->command($repository), ['table' => 'transactions', '--future' => '1']);

        self::assertStringContainsString('Adding 5 partitions to [transactions]:', $tester->getDisplay());
        self::assertSame([<<<'SQL'
            ALTER TABLE `transactions`
            REORGANIZE PARTITION `pMax` INTO (
                PARTITION `p20250610` VALUES LESS THAN (739778),
                PARTITION `p20250611` VALUES LESS THAN (739779),
                PARTITION `p20250612` VALUES LESS THAN (739780),
                PARTITION `p20250613` VALUES LESS THAN (739781),
                PARTITION `p20250614` VALUES LESS THAN (739782),
                PARTITION `pMax` VALUES LESS THAN MAXVALUE
            )
            SQL], $repository->executed);
    }

    public function test_with_gap_filling_off_a_table_that_fell_behind_starts_today(): void
    {
        $repository = $this->endingAt('p20250609', '739777');

        $tester = $this->runCommand(
            $this->command($repository, ['tables' => ['transactions' => ['fill_gaps' => false]]]),
            ['table' => 'transactions', '--future' => '1'],
        );

        self::assertStringContainsString('Adding 2 partitions to [transactions]:', $tester->getDisplay());
        self::assertSame([<<<'SQL'
            ALTER TABLE `transactions`
            REORGANIZE PARTITION `pMax` INTO (
                PARTITION `p20250613` VALUES LESS THAN (739781),
                PARTITION `p20250614` VALUES LESS THAN (739782),
                PARTITION `pMax` VALUES LESS THAN MAXVALUE
            )
            SQL], $repository->executed);
    }

    public function test_with_gap_filling_off_the_run_still_continues_after_a_partition_ahead_of_today(): void
    {
        $repository = $this->endingAt('p20250620', '739788');

        $tester = $this->runCommand(
            $this->command($repository, ['tables' => ['transactions' => ['fill_gaps' => false]]]),
            ['table' => 'transactions', '--future' => '9'],
        );

        self::assertStringContainsString('Adding 2 partitions to [transactions]:', $tester->getDisplay());
        self::assertStringContainsString('→ p20250621 (< 739789)', $tester->getDisplay());
        self::assertStringContainsString('→ p20250622 (< 739790)', $tester->getDisplay());
        self::assertCount(1, $repository->executed);
    }

    public function test_the_look_ahead_is_counted_from_today_so_a_covered_horizon_adds_nothing(): void
    {
        $repository = $this->endingAt('p20250630', '739798');

        $tester = $this->runCommand($this->command($repository), ['table' => 'transactions', '--future' => '10']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('All partitions for [transactions] already exist.', $tester->getDisplay());
        self::assertSame([], $repository->executed);
    }

    public function test_a_start_date_before_the_last_partition_adds_nothing_under_it(): void
    {
        $repository = $this->endingAt('p20250620', '739788');

        $tester = $this->runCommand(
            $this->command($repository),
            ['table' => 'transactions', '--from' => '2025-06-01', '--to' => '2025-06-22'],
        );

        self::assertStringContainsString('Adding 2 partitions to [transactions]:', $tester->getDisplay());
        self::assertStringNotContainsString('without a partition of', $tester->getDisplay());
        self::assertSame([<<<'SQL'
            ALTER TABLE `transactions`
            REORGANIZE PARTITION `pMax` INTO (
                PARTITION `p20250621` VALUES LESS THAN (739789),
                PARTITION `p20250622` VALUES LESS THAN (739790),
                PARTITION `pMax` VALUES LESS THAN MAXVALUE
            )
            SQL], $repository->executed);
    }

    public function test_a_start_date_on_the_period_after_the_last_partition_is_used_as_given(): void
    {
        $repository = $this->endingAt('p20250620', '739788');

        $tester = $this->runCommand(
            $this->command($repository),
            ['table' => 'transactions', '--from' => '2025-06-21', '--to' => '2025-06-22'],
        );

        self::assertStringContainsString('Adding 2 partitions to [transactions]:', $tester->getDisplay());
        self::assertStringNotContainsString('without a partition of', $tester->getDisplay());
        self::assertSame([<<<'SQL'
            ALTER TABLE `transactions`
            REORGANIZE PARTITION `pMax` INTO (
                PARTITION `p20250621` VALUES LESS THAN (739789),
                PARTITION `p20250622` VALUES LESS THAN (739790),
                PARTITION `pMax` VALUES LESS THAN MAXVALUE
            )
            SQL], $repository->executed);
    }

    public function test_a_start_date_past_a_gap_is_used_as_given_with_a_warning(): void
    {
        $repository = $this->endingAt('p20250620', '739788');
        $logger = new FakeLogger;

        $tester = $this->runCommand(
            $this->command($repository, [], $logger),
            ['table' => 'transactions', '--from' => '2025-06-25', '--to' => '2025-06-26'],
        );

        $output = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Adding 2 partitions to [transactions]:', $output);
        self::assertStringContainsString($this->gapWarning(), $output);
        self::assertLessThan(strpos($output, 'ALTER TABLE'), strpos($output, $this->gapWarning()));
        self::assertSame([<<<'SQL'
            ALTER TABLE `transactions`
            REORGANIZE PARTITION `pMax` INTO (
                PARTITION `p20250625` VALUES LESS THAN (739793),
                PARTITION `p20250626` VALUES LESS THAN (739794),
                PARTITION `pMax` VALUES LESS THAN MAXVALUE
            )
            SQL], $repository->executed);
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame($this->gapWarning(), $logger->records[0]['message']);
    }

    public function test_a_dry_run_warns_about_a_start_date_past_a_gap_and_logs_nothing(): void
    {
        $repository = $this->endingAt('p20250620', '739788');
        $logger = new FakeLogger;

        $tester = $this->runCommand(
            $this->command($repository, [], $logger),
            ['table' => 'transactions', '--from' => '2025-06-25', '--to' => '2025-06-26', '--dry-run' => true],
        );

        $output = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString($this->gapWarning(), $output);
        self::assertStringContainsString('PARTITION `p20250625` VALUES LESS THAN (739793)', $output);
        self::assertSame([], $repository->executed);
        self::assertSame([], $logger->records);
    }

    public function test_with_gap_filling_off_a_start_date_past_a_gap_is_used_as_given_in_silence(): void
    {
        $repository = $this->endingAt('p20250620', '739788');
        $logger = new FakeLogger;

        $tester = $this->runCommand(
            $this->command($repository, ['tables' => ['transactions' => ['fill_gaps' => false]]], $logger),
            ['table' => 'transactions', '--from' => '2025-06-25', '--to' => '2025-06-26'],
        );

        self::assertStringContainsString('Adding 2 partitions to [transactions]:', $tester->getDisplay());
        self::assertStringNotContainsString('without a partition of', $tester->getDisplay());
        self::assertSame([<<<'SQL'
            ALTER TABLE `transactions`
            REORGANIZE PARTITION `pMax` INTO (
                PARTITION `p20250625` VALUES LESS THAN (739793),
                PARTITION `p20250626` VALUES LESS THAN (739794),
                PARTITION `pMax` VALUES LESS THAN MAXVALUE
            )
            SQL], $repository->executed);
        self::assertSame([], $logger->records);
    }

    public function test_a_range_that_ends_before_it_starts_is_refused(): void
    {
        $repository = $this->endingAt('p20250620', '739788');

        $this->expectException(InvalidRangeException::class);
        $this->expectExceptionMessage(
            'The range for table [transactions] starts at 2025-06-25 and ends at 2025-06-21, before it starts'
        );

        try {
            $this->runCommand(
                $this->command($repository),
                ['table' => 'transactions', '--from' => '2025-06-25', '--to' => '2025-06-21'],
            );
        } finally {
            self::assertSame([], $repository->executed);
        }
    }

    public function test_a_start_past_the_look_ahead_is_refused(): void
    {
        $repository = $this->endingAt('p20250620', '739788');

        $this->expectException(InvalidRangeException::class);
        $this->expectExceptionMessage(
            'starts at 2025-06-25, but with no end date it stops at 2025-06-23 — future(10), counted from today'
        );

        try {
            $this->runCommand(
                $this->command($repository),
                ['table' => 'transactions', '--from' => '2025-06-25', '--future' => '10'],
            );
        } finally {
            self::assertSame([], $repository->executed);
        }
    }

    public function test_an_inverted_range_does_not_rebuild_an_unpartitioned_table(): void
    {
        $repository = new FakePartitionRepository;

        $this->expectException(InvalidRangeException::class);

        try {
            $this->runCommand(
                $this->command($repository),
                ['table' => 'transactions', '--from' => '2025-06-25', '--to' => '2025-06-21', '--create' => true, '--force' => true],
            );
        } finally {
            self::assertSame([], $repository->executed);
        }
    }

    public function test_an_end_date_under_the_last_partition_without_a_start_adds_nothing(): void
    {
        $repository = $this->endingAt('p20250620', '739788');

        $tester = $this->runCommand($this->command($repository), ['table' => 'transactions', '--to' => '2025-06-15']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('All partitions for [transactions] already exist.', $tester->getDisplay());
        self::assertSame([], $repository->executed);
    }

    public function test_the_help_says_where_a_run_starts_and_what_the_look_ahead_counts_from(): void
    {
        $definition = $this->command(new FakePartitionRepository)->getDefinition();

        self::assertSame(
            'First period to cover (YYYY-MM-DD); by default the one after the last existing partition, or today when the table has none',
            $definition->getOption('from')->getDescription()
        );
        self::assertSame(
            'Periods to keep created ahead of today when --to is omitted, counted from today rather than from the last partition',
            $definition->getOption('future')->getDescription()
        );
    }

    public function test_the_help_says_an_unpartitioned_table_needs_create(): void
    {
        self::assertSame(
            'Partition a table that is not partitioned yet; without it such a table is refused',
            $this->command(new FakePartitionRepository)->getDefinition()->getOption('create')->getDescription()
        );
    }

    public function test_adding_monthly_partitions_to_a_daily_table_is_refused(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p20250612' => '739780',
            'p20250613' => '739781',
            'pMax' => 'MAXVALUE',
        ], expression: 'to_days(`created_at`)');

        $this->expectException(StrategyMismatchException::class);
        $this->expectExceptionMessage('Table [transactions] holds daily partitions');

        try {
            $this->runCommand(
                $this->command($repository),
                ['table' => 'transactions', '--strategy' => 'monthly', '--future' => '3'],
            );
        } finally {
            self::assertSame([], $repository->executed);
        }
    }

    public function test_the_daily_fallback_does_not_reach_a_monthly_table(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p202505' => '739768',
            'p202506' => '739798',
            'pMax' => 'MAXVALUE',
        ], expression: 'to_days(`created_at`)');

        $this->expectException(StrategyMismatchException::class);
        $this->expectExceptionMessage('Set the strategy to monthly');

        try {
            $this->runCommand($this->command($repository), ['table' => 'transactions', '--to' => '2025-07-10']);
        } finally {
            self::assertSame([], $repository->executed);
        }
    }

    public function test_a_mismatched_period_is_refused_in_a_dry_run_too(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p2024' => '739617',
            'p2025' => '739982',
            'pMax' => 'MAXVALUE',
        ], expression: 'to_days(`created_at`)');

        $this->expectException(StrategyMismatchException::class);
        $this->expectExceptionMessage('holds yearly partitions');

        $this->runCommand(
            $this->command($repository),
            ['table' => 'transactions', '--strategy' => 'weekly', '--dry-run' => true],
        );
    }

    public function test_create_on_an_already_partitioned_table_fails(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p20250613' => '739781',
        ], expression: 'to_days(`created_at`)');

        $tester = $this->runCommand(
            $this->command($repository),
            ['table' => 'transactions', '--column' => 'created_at', '--create' => true],
        );

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('is already partitioned', $tester->getDisplay());
        self::assertSame([], $repository->executed);
    }

    public function test_the_gap_limit_bounds_the_run_and_reports_the_rest(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p202312' => '739251',
            'pMax' => 'MAXVALUE',
        ], expression: 'to_days(`created_at`)');

        $tester = $this->runCommand($this->command($repository), [
            'table' => 'transactions',
            '--strategy' => 'monthly',
            '--to' => '2025-06-01',
            '--max-gaps' => '6',
        ]);

        $output = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Adding 6 of 18 missing partitions to [transactions]:', $output);
        self::assertStringContainsString('p202406', $output);
        self::assertStringNotContainsString('p202407 (<', $output);
        self::assertStringContainsString('12 partitions are left for the next run (p202407 first)', $output);
        self::assertCount(1, $repository->executed);
    }

    public function test_a_longer_look_ahead_is_added_in_one_run_on_an_up_to_date_table(): void
    {
        $repository = $this->endingAt('p20250613', '739781');

        $tester = $this->runCommand($this->command($repository), ['table' => 'transactions', '--future' => '30']);

        $output = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Adding 30 partitions to [transactions]:', $output);
        self::assertStringContainsString('p20250713', $output);
        self::assertStringNotContainsString('are left for the next run', $output);
        self::assertCount(1, $repository->executed);
    }

    public function test_additions_can_be_chunked_from_the_command_line(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p202312' => '739251',
            'pMax' => 'MAXVALUE',
        ], expression: 'to_days(`created_at`)');

        $tester = $this->runCommand($this->command($repository), [
            'table' => 'transactions',
            '--strategy' => 'monthly',
            '--to' => '2025-06-01',
            '--max-gaps' => '0',
            '--chunk' => '6',
        ]);

        $output = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Adding 18 partitions to [transactions]:', $output);
        self::assertStringNotContainsString('are left for the next run', $output);
        self::assertCount(3, $repository->executed);
    }

    public function test_twelve_gaps_are_filled_per_run_by_default(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p202312' => '739251',
            'pMax' => 'MAXVALUE',
        ], expression: 'to_days(`created_at`)');

        $tester = $this->runCommand($this->command($repository), [
            'table' => 'transactions',
            '--strategy' => 'monthly',
            '--to' => '2025-06-01',
        ]);

        self::assertStringContainsString('Adding 12 of 18 missing partitions to [transactions]:', $tester->getDisplay());
        self::assertStringContainsString('6 partitions are left for the next run (p202501 first)', $tester->getDisplay());
    }
}

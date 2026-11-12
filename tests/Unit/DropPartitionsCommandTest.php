<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Unit;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use PHPUnit\Framework\Attributes\DataProvider;
use Yurkovskiy\Partitions\Console\DropPartitionsCommand;
use Yurkovskiy\Partitions\Events\PartitionsDropped;
use Yurkovskiy\Partitions\Exceptions\DropsEveryPartitionException;
use Yurkovskiy\Partitions\Exceptions\StrategyMismatchException;
use Yurkovskiy\Partitions\PartitionBuilder;
use Yurkovskiy\Partitions\PartitionManager;
use Yurkovskiy\Partitions\Tests\Support\CommandTestCase;
use Yurkovskiy\Partitions\Tests\Support\FakeDispatcher;
use Yurkovskiy\Partitions\Tests\Support\FakePartitionRepository;

class DropPartitionsCommandTest extends CommandTestCase
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
        ?FakeDispatcher $events = null,
    ): DropPartitionsCommand {
        return $this->withManager(new DropPartitionsCommand, new PartitionManager($repository, $events, $config));
    }

    protected function partitioned(): FakePartitionRepository
    {
        return FakePartitionRepository::withPartitions([
            'p20250610' => '739778',
            'p20250611' => '739779',
            'p20250612' => '739780',
            'p20250613' => '739781',
            'pMax' => 'MAXVALUE',
        ], expression: 'to_days(`created_at`)');
    }

    public function test_an_unpartitioned_table_is_left_alone(): void
    {
        $repository = new FakePartitionRepository;

        $tester = $this->runCommand(
            $this->command($repository),
            ['table' => 'transactions', '--column' => 'created_at', '--retain' => '1', '--force' => true],
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Table [transactions] is not partitioned.', $tester->getDisplay());
        self::assertSame([], $repository->executed);
    }

    public function test_without_a_retention_window_nothing_matches(): void
    {
        $repository = $this->partitioned();

        $tester = $this->runCommand(
            $this->command($repository),
            ['table' => 'transactions', '--column' => 'created_at', '--force' => true],
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No partitions of [transactions] match the given range.', $tester->getDisplay());
        self::assertSame([], $repository->executed);
    }

    public function test_the_retention_option_prunes_and_reports_what_is_deleted(): void
    {
        $repository = $this->partitioned();

        $tester = $this->runCommand(
            $this->command($repository),
            ['table' => 'transactions', '--column' => 'created_at', '--retain' => '1', '--force' => true],
        );

        $output = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Dropping 2 partitions from [transactions]:', $output);
        self::assertStringContainsString('p20250610, p20250611', $output);
        self::assertStringContainsString('~20 rows, 3 KB on disk will be deleted.', $output);
        self::assertSame([
            'ALTER TABLE `transactions` DROP PARTITION `p20250610`',
            'ALTER TABLE `transactions` DROP PARTITION `p20250611`',
        ], $repository->executed);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedNumbers(): array
    {
        return [
            'a word for --retain' => ['retain', 'abc'],
            'a negative --retain' => ['retain', '-1'],
            'a word for --chunk' => ['chunk', 'x'],
        ];
    }

    #[DataProvider('malformedNumbers')]
    public function test_a_numeric_option_that_is_not_a_whole_number_drops_nothing(string $option, string $value): void
    {
        $repository = $this->partitioned();

        $tester = $this->runCommand($this->command($repository, ['tables' => ['transactions' => [
            'retain' => 2,
        ]]]), ['table' => 'transactions', "--$option" => $value, '--force' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(
            "The --$option option takes a whole number, zero or above; [$value] given.",
            $tester->getDisplay()
        );
        self::assertSame([], $repository->executed);
    }

    public function test_a_malformed_retention_is_refused_on_an_unpartitioned_table_as_well(): void
    {
        $tester = $this->runCommand(
            $this->command(new FakePartitionRepository),
            ['table' => 'transactions', '--retain' => 'abc', '--force' => true],
        );

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringNotContainsString('is not partitioned', $tester->getDisplay());
    }

    public function test_the_configured_retention_is_used_without_options(): void
    {
        $repository = $this->partitioned();

        $tester = $this->runCommand($this->command($repository, ['tables' => ['payments' => [
            'table' => 'transactions',
            'column' => 'created_at',
            'retain' => 2,
        ]]]), ['table' => 'payments', '--force' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Dropping 1 partitions from [transactions]:', $tester->getDisplay());
        self::assertSame(
            ['ALTER TABLE `transactions` DROP PARTITION `p20250610`'],
            $repository->executed
        );
    }

    public function test_the_retention_option_overrides_the_configured_one(): void
    {
        $repository = $this->partitioned();

        $this->runCommand($this->command($repository, ['tables' => ['payments' => [
            'table' => 'transactions',
            'column' => 'created_at',
            'retain' => 2,
        ]]]), ['table' => 'payments', '--retain' => '1', '--force' => true]);

        self::assertSame([
            'ALTER TABLE `transactions` DROP PARTITION `p20250610`',
            'ALTER TABLE `transactions` DROP PARTITION `p20250611`',
        ], $repository->executed);
    }

    public function test_before_drops_everything_older_than_the_given_date(): void
    {
        $repository = $this->partitioned();

        $this->runCommand(
            $this->command($repository),
            ['table' => 'transactions', '--column' => 'created_at', '--before' => '2025-06-12', '--force' => true],
        );

        self::assertSame([
            'ALTER TABLE `transactions` DROP PARTITION `p20250610`',
            'ALTER TABLE `transactions` DROP PARTITION `p20250611`',
        ], $repository->executed);
    }

    public function test_a_range_drops_the_partitions_it_covers(): void
    {
        $repository = $this->partitioned();

        $tester = $this->runCommand($this->command($repository), [
            'table' => 'transactions',
            '--column' => 'created_at',
            '--from' => '2025-06-11',
            '--to' => '2025-06-12',
            '--force' => true,
        ]);

        self::assertStringContainsString('Dropping 2 partitions from [transactions]:', $tester->getDisplay());
        self::assertStringContainsString('p20250611, p20250612', $tester->getDisplay());
        self::assertSame(
            [
                'ALTER TABLE `transactions` DROP PARTITION `p20250611`',
                'ALTER TABLE `transactions` DROP PARTITION `p20250612`',
            ],
            $repository->executed
        );
    }

    /**
     * @return array<string, array{array<string, string|bool>, bool}>
     */
    public static function halfRanges(): array
    {
        return [
            'a start alone' => [['--from' => '2025-06-11'], true],
            'an end alone' => [['--to' => '2025-06-11'], true],
            'a forced start' => [['--from' => '2025-06-11', '--force' => true], true],
            'a non-interactive end' => [['--to' => '2025-06-11'], false],
            'a start in a dry run' => [['--from' => '2025-06-11', '--dry-run' => true], true],
            'a start next to --before' => [['--from' => '2025-06-11', '--before' => '2025-06-12'], true],
        ];
    }

    /**
     * @param  array<string, string|bool>  $options
     */
    #[DataProvider('halfRanges')]
    public function test_a_range_needs_both_ends(array $options, bool $interactive): void
    {
        $repository = $this->partitioned();
        $events = new FakeDispatcher;

        $tester = $this->runCommand(
            $this->command($repository, events: $events),
            ['table' => 'transactions', '--column' => 'created_at', ...$options],
            interactive: $interactive,
        );

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(
            'The --from and --to options go together: pass both ends of the range to drop.',
            $tester->getDisplay()
        );
        self::assertStringNotContainsString('Dropping', $tester->getDisplay());
        self::assertSame([], $repository->executed);
        self::assertNull($events->firstOf(PartitionsDropped::class));
    }

    public function test_a_half_range_is_refused_on_an_unpartitioned_table_as_well(): void
    {
        $tester = $this->runCommand(
            $this->command(new FakePartitionRepository),
            ['table' => 'transactions', '--from' => '2025-06-11', '--force' => true],
        );

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('The --from and --to options go together', $tester->getDisplay());
        self::assertStringNotContainsString('is not partitioned', $tester->getDisplay());
    }

    public function test_the_help_says_the_range_needs_both_ends(): void
    {
        $definition = $this->command(new FakePartitionRepository)->getDefinition();

        self::assertSame(
            'Drop the partitions covering this range — start, inclusive; needs --to',
            $definition->getOption('from')->getDescription()
        );
        self::assertSame(
            'Drop the partitions covering this range — end, inclusive; needs --from',
            $definition->getOption('to')->getDescription()
        );
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function selections(): array
    {
        return [
            'a retention window' => [['--retain' => '1']],
            'a date to drop before' => [['--before' => '2025-06-01']],
            'a range' => [['--from' => '2025-01-01', '--to' => '2025-06-30']],
        ];
    }

    /**
     * @param  array<string, string>  $selection
     */
    #[DataProvider('selections')]
    public function test_a_monthly_table_is_not_dropped_by_weeks(array $selection): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p202504' => '739737',
            'p202505' => '739768',
            'p202506' => '739798',
            'pMax' => 'MAXVALUE',
        ], expression: 'to_days(`created_at`)');

        $this->expectException(StrategyMismatchException::class);
        $this->expectExceptionMessage('Table [transactions] holds monthly partitions');

        try {
            $this->runCommand(
                $this->command($repository),
                ['table' => 'transactions', '--strategy' => 'weekly', '--force' => true] + $selection,
            );
        } finally {
            self::assertSame([], $repository->executed);
        }
    }

    public function test_a_daily_table_is_not_dropped_by_months(): void
    {
        $repository = $this->partitioned();

        $this->expectException(StrategyMismatchException::class);
        $this->expectExceptionMessage('but the builder is configured for monthly ones');

        try {
            $this->runCommand($this->command($repository, ['tables' => ['transactions' => [
                'strategy' => 'monthly',
                'retain' => 0,
            ]]]), ['table' => 'transactions', '--force' => true]);
        } finally {
            self::assertSame([], $repository->executed);
        }
    }

    public function test_it_asks_before_deleting_the_data(): void
    {
        $repository = $this->partitioned();

        $tester = $this->runCommand(
            $this->command($repository),
            ['table' => 'transactions', '--column' => 'created_at', '--retain' => '1'],
            inputs: ['no'],
        );

        $output = $tester->getDisplay();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Drop these partitions?', $output);
        self::assertStringContainsString('Aborted.', $output);
        self::assertSame([], $repository->executed);
    }

    public function test_confirming_drops_the_partitions(): void
    {
        $repository = $this->partitioned();
        $events = new FakeDispatcher;

        $tester = $this->runCommand(
            $this->command($repository, events: $events),
            ['table' => 'transactions', '--column' => 'created_at', '--retain' => '1'],
            inputs: ['yes'],
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertCount(2, $repository->executed);
        self::assertSame(['p20250610', 'p20250611'], $events->firstOf(PartitionsDropped::class)?->partitions);
    }

    /**
     * @return array<string, array{array<string, string>, array<int, string>}>
     */
    public static function selectionsThatMoveWithTheClock(): array
    {
        return [
            'a retention window' => [['--retain' => '1'], ['p20250610', 'p20250611']],
            'a relative date to drop before' => [['--before' => 'today'], ['p20250610', 'p20250611', 'p20250612']],
        ];
    }

    /**
     * @param  array<string, string>  $selection
     * @param  array<int, string>  $shown
     */
    #[DataProvider('selectionsThatMoveWithTheClock')]
    public function test_only_the_partitions_shown_for_confirmation_are_dropped(array $selection, array $shown): void
    {
        CarbonImmutable::setTestNow('2025-06-13 23:59:00');

        $repository = FakePartitionRepository::withPartitions([
            'p20250610' => '739778',
            'p20250611' => '739779',
            'p20250612' => '739780',
            'p20250613' => '739781',
            'p20250614' => '739782',
            'pMax' => 'MAXVALUE',
        ], expression: 'to_days(`created_at`)');
        $events = new FakeDispatcher;

        $command = $this->withManager(new class extends DropPartitionsCommand
        {
            protected function confirmed(PartitionBuilder $builder, string $question): bool
            {
                CarbonImmutable::setTestNow('2025-06-14 00:01:00');

                return parent::confirmed($builder, $question);
            }
        }, new PartitionManager($repository, $events));

        $tester = $this->runCommand(
            $command,
            ['table' => 'transactions', '--column' => 'created_at'] + $selection,
            inputs: ['yes'],
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('  '.implode(', ', $shown)."\n", $tester->getDisplay());
        self::assertSame(
            array_map(fn (string $name) => "ALTER TABLE `transactions` DROP PARTITION `$name`", $shown),
            $repository->executed
        );
        self::assertSame($shown, $events->firstOf(PartitionsDropped::class)?->partitions);
    }

    public function test_a_non_interactive_run_does_not_ask(): void
    {
        $repository = $this->partitioned();

        $tester = $this->runCommand(
            $this->command($repository),
            ['table' => 'transactions', '--column' => 'created_at', '--retain' => '1'],
            interactive: false,
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringNotContainsString('Drop these partitions?', $tester->getDisplay());
        self::assertCount(2, $repository->executed);
    }

    public function test_a_dry_run_neither_asks_nor_executes(): void
    {
        $repository = $this->partitioned();
        $events = new FakeDispatcher;

        $tester = $this->runCommand(
            $this->command($repository, events: $events),
            ['table' => 'transactions', '--column' => 'created_at', '--retain' => '1', '--dry-run' => true],
        );

        $output = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringNotContainsString('Drop these partitions?', $output);
        self::assertStringContainsString('ALTER TABLE `transactions` DROP PARTITION `p20250610`;', $output);
        self::assertStringContainsString('ALTER TABLE `transactions` DROP PARTITION `p20250611`;', $output);
        self::assertStringContainsString('Dry run — nothing was executed.', $output);
        self::assertSame([], $repository->executed);
        self::assertSame([], $events->dispatched);
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function dropChunks(): array
    {
        return [
            'one per statement' => ['1', [
                'ALTER TABLE `transactions` DROP PARTITION `p20250610`',
                'ALTER TABLE `transactions` DROP PARTITION `p20250611`',
            ]],
            'two per statement' => ['2', ['ALTER TABLE `transactions` DROP PARTITION `p20250610`, `p20250611`']],
            'zero, all in one statement' => ['0', ['ALTER TABLE `transactions` DROP PARTITION `p20250610`, `p20250611`']],
        ];
    }

    /**
     * @param  list<string>  $statements
     */
    #[DataProvider('dropChunks')]
    public function test_the_chunk_option_groups_the_drop_statements(string $chunk, array $statements): void
    {
        $repository = $this->partitioned();

        $this->runCommand($this->command($repository), [
            'table' => 'transactions',
            '--column' => 'created_at',
            '--retain' => '1',
            '--chunk' => $chunk,
            '--force' => true,
        ]);

        self::assertSame($statements, $repository->executed);
    }

    public function test_the_catch_all_partition_is_never_dropped(): void
    {
        $repository = $this->partitioned();

        $this->runCommand($this->command($repository), [
            'table' => 'transactions',
            '--column' => 'created_at',
            '--before' => '2030-01-01',
            '--force' => true,
        ]);

        self::assertCount(4, $repository->executed);
        self::assertStringNotContainsString('pMax', implode(' ', $repository->executed));
        self::assertStringContainsString('`p20250613`', $repository->executed[3]);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function selectionsOfTheWholeTable(): iterable
    {
        yield '--before' => [['--before' => '2026-01-01']];
        yield '--from and --to' => [['--from' => '2025-06-01', '--to' => '2025-06-30']];
        yield '--before with --force' => [['--before' => '2026-01-01', '--force' => true]];
        yield '--before in a dry run' => [['--before' => '2026-01-01', '--dry-run' => true]];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    #[DataProvider('selectionsOfTheWholeTable')]
    public function test_a_selection_of_every_partition_stops_before_the_confirmation(array $options): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p20250610' => '739778',
            'p20250611' => '739779',
            'p20250612' => '739780',
        ], expression: 'to_days(`created_at`)');
        $command = $this->command($repository, ['max_partition' => false]);

        try {
            $this->runCommand($command, ['table' => 'transactions', ...$options]);

            self::fail('The command went on to drop every partition.');
        } catch (DropsEveryPartitionException $exception) {
            self::assertStringContainsString('are all that table [transactions] has', $exception->getMessage());
        }

        self::assertSame([], $repository->executed);
    }
}

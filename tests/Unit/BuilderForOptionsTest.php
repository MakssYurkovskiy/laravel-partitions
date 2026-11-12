<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Unit;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use PHPUnit\Framework\Attributes\DataProvider;
use Yurkovskiy\Partitions\Console\AddPartitionsCommand;
use Yurkovskiy\Partitions\Exceptions\InvalidTableConfigException;
use Yurkovskiy\Partitions\PartitionManager;
use Yurkovskiy\Partitions\Tests\Support\CommandTestCase;
use Yurkovskiy\Partitions\Tests\Support\FakePartitionRepository;
use Yurkovskiy\Partitions\Tests\Support\QuarterlyStrategy;

class BuilderForOptionsTest extends CommandTestCase
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
    protected function command(array $config = [], ?FakePartitionRepository $repository = null): AddPartitionsCommand
    {
        return $this->withManager(new AddPartitionsCommand, new PartitionManager(
            $repository ?? new FakePartitionRepository,
            null,
            $config,
        ));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $config
     */
    protected function sqlFor(array $arguments, array $config = [], ?FakePartitionRepository $repository = null): string
    {
        $tester = $this->runCommand(
            $this->command($config, $repository),
            $arguments + ['table' => 'transactions', '--create' => true, '--dry-run' => true],
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        return $tester->getDisplay();
    }

    public function test_an_unconfigured_table_falls_back_to_daily_on_created_at(): void
    {
        $sql = $this->sqlFor(['--future' => '0']);

        self::assertStringContainsString('PARTITION BY RANGE (TO_DAYS(`created_at`))', $sql);
        self::assertStringContainsString('PARTITION `p20250613`', $sql);
    }

    public function test_an_unconfigured_table_is_described_by_the_options_alone(): void
    {
        $repository = new FakePartitionRepository;

        $sql = $this->sqlFor([
            '--strategy' => 'monthly',
            '--column' => 'processed_at',
            '--expression' => 'columns',
            '--prefix' => 'txn',
            '--tablespace' => 'transactions_space',
            '--connection' => 'archive',
            '--future' => '0',
        ], repository: $repository);

        self::assertStringContainsString('PARTITION BY RANGE COLUMNS(`processed_at`)', $sql);
        self::assertStringContainsString("PARTITION `txn202506` VALUES LESS THAN ('2025-07-01') TABLESPACE = `transactions_space`", $sql);
        self::assertSame([['archive', 'transactions']], $repository->requested);
    }

    public function test_a_shorthand_strategy_can_be_passed_as_an_option(): void
    {
        $sql = $this->sqlFor(['--strategy' => 'M', '--column' => 'processed_at', '--future' => '0']);

        self::assertStringContainsString('PARTITION `p202506` VALUES LESS THAN (739798)', $sql);
    }

    public function test_a_key_whose_entry_is_not_an_array_does_not_fall_back_to_the_defaults(): void
    {
        $this->expectException(InvalidTableConfigException::class);
        $this->expectExceptionMessage('Table [transactions] in config/partitions.php has to be an array of settings, string given.');

        $this->sqlFor(['--future' => '0'], ['tables' => ['transactions' => 'monthly']]);
    }

    public function test_a_shorthand_strategy_can_be_configured(): void
    {
        $sql = $this->sqlFor(['--future' => '0'], ['tables' => ['transactions' => [
            'strategy' => 'month',
            'column' => 'processed_at',
        ]]]);

        self::assertStringContainsString('PARTITION `p202506` VALUES LESS THAN (739798)', $sql);
    }

    public function test_a_custom_strategy_class_can_be_passed_as_an_option(): void
    {
        $sql = $this->sqlFor(['--strategy' => QuarterlyStrategy::class, '--future' => '0']);

        self::assertStringContainsString('PARTITION `p202504` VALUES LESS THAN (739798)', $sql);
    }

    public function test_a_configured_table_is_taken_from_the_config(): void
    {
        $sql = $this->sqlFor(['--future' => '0'], ['tables' => ['transactions' => [
            'strategy' => 'monthly',
            'column' => 'processed_at',
            'prefix' => 'txn',
            'expression' => 'columns',
        ]]]);

        self::assertStringContainsString('PARTITION BY RANGE COLUMNS(`processed_at`)', $sql);
        self::assertStringContainsString('PARTITION `txn202506`', $sql);
    }

    public function test_the_config_key_can_name_another_table(): void
    {
        $tester = $this->runCommand($this->command(['tables' => ['payments' => [
            'table' => 'transactions',
            'future' => 0,
        ]]]), ['table' => 'payments', '--create' => true, '--dry-run' => true]);

        self::assertStringContainsString('Partitioning [transactions] into 1 partitions:', $tester->getDisplay());
        self::assertStringContainsString('ALTER TABLE `transactions`', $tester->getDisplay());
    }

    public function test_the_options_override_the_configured_table(): void
    {
        $repository = new FakePartitionRepository;

        $sql = $this->sqlFor([
            '--strategy' => 'yearly',
            '--column' => 'settled_at',
            '--expression' => 'to_days',
            '--prefix' => 'y',
            '--tablespace' => 'other_space',
            '--connection' => 'archive',
            '--future' => '0',
        ], ['tables' => ['transactions' => [
            'strategy' => 'monthly',
            'column' => 'processed_at',
            'prefix' => 'txn',
            'expression' => 'columns',
            'tablespace' => 'transactions_space',
            'connection' => 'reports',
        ]]], $repository);

        self::assertStringContainsString('PARTITION BY RANGE (TO_DAYS(`settled_at`))', $sql);
        self::assertStringContainsString('PARTITION `y2025` VALUES LESS THAN (739982) TABLESPACE = `other_space`', $sql);
        self::assertStringNotContainsString('transactions_space', $sql);
        self::assertSame([['archive', 'transactions']], $repository->requested);
    }

    public function test_overriding_only_the_strategy_keeps_the_configured_column(): void
    {
        $sql = $this->sqlFor(['--strategy' => 'yearly', '--future' => '0'], ['tables' => ['transactions' => [
            'strategy' => 'monthly',
            'column' => 'processed_at',
        ]]]);

        self::assertStringContainsString('PARTITION BY RANGE (TO_DAYS(`processed_at`))', $sql);
        self::assertStringContainsString('PARTITION `p2025`', $sql);
    }

    public function test_overriding_only_the_column_keeps_the_configured_strategy(): void
    {
        $sql = $this->sqlFor(['--column' => 'settled_at', '--future' => '0'], ['tables' => ['transactions' => [
            'strategy' => 'monthly',
            'column' => 'processed_at',
        ]]]);

        self::assertStringContainsString('PARTITION BY RANGE (TO_DAYS(`settled_at`))', $sql);
        self::assertStringContainsString('PARTITION `p202506`', $sql);
    }

    public function test_an_empty_option_is_not_an_override(): void
    {
        $sql = $this->sqlFor([
            '--strategy' => '',
            '--column' => '',
            '--prefix' => '',
            '--tablespace' => '',
            '--future' => '0',
        ], ['tables' => ['transactions' => [
            'strategy' => 'monthly',
            'column' => 'processed_at',
            'prefix' => 'txn',
            'tablespace' => 'transactions_space',
        ]]]);

        self::assertStringContainsString('PARTITION BY RANGE (TO_DAYS(`processed_at`))', $sql);
        self::assertStringContainsString(
            'PARTITION `txn202506` VALUES LESS THAN (739798) TABLESPACE = `transactions_space`',
            $sql
        );
    }

    public function test_a_config_key_with_a_dot_is_a_configured_table(): void
    {
        $tester = $this->runCommand($this->command(['tables' => ['analytics.transactions' => [
            'strategy' => 'monthly',
            'column' => 'processed_at',
            'future' => 0,
        ]]]), ['table' => 'analytics.transactions', '--create' => true, '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('PARTITION BY RANGE (TO_DAYS(`processed_at`))', $tester->getDisplay());
        self::assertStringContainsString('PARTITION `p202506` VALUES LESS THAN (739798)', $tester->getDisplay());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedNumbers(): array
    {
        return [
            'a word for --max-gaps' => ['max-gaps', 'twelve'],
            'a word for --chunk' => ['chunk', 'x'],
            'a padded --chunk' => ['chunk', ' 4'],
            'a fraction for --future' => ['future', '1.5'],
            'a negative --future' => ['future', '-1'],
            'a --future too long for an integer' => ['future', '99999999999999999999'],
        ];
    }

    #[DataProvider('malformedNumbers')]
    public function test_a_numeric_option_that_is_not_a_whole_number_stops_the_command(string $option, string $value): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p20250612' => '739780',
            'pMax' => 'MAXVALUE',
        ], expression: 'to_days(`created_at`)');

        $tester = $this->runCommand($this->command([], $repository), [
            'table' => 'transactions',
            "--$option" => $value,
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(
            "The --$option option takes a whole number, zero or above; [$value] given.",
            $tester->getDisplay()
        );
        self::assertSame([], $repository->executed);
    }

    public function test_a_malformed_number_is_refused_in_a_dry_run_too(): void
    {
        $tester = $this->runCommand($this->command(), [
            'table' => 'transactions',
            '--max-gaps' => 'twelve',
            '--dry-run' => true,
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringNotContainsString('ALTER TABLE', $tester->getDisplay());
    }

    public function test_the_chunk_and_gap_options_override_the_configured_ones(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p202312' => '739251',
            'pMax' => 'MAXVALUE',
        ], expression: 'to_days(`created_at`)');

        $tester = $this->runCommand($this->command(['tables' => ['transactions' => [
            'strategy' => 'monthly',
            'max_gaps' => 2,
            'sync_chunk' => 1,
        ]]], $repository), [
            'table' => 'transactions',
            '--to' => '2025-06-01',
            '--max-gaps' => '12',
            '--chunk' => '4',
        ]);

        self::assertStringContainsString('Adding 12 of 18 missing partitions to [transactions]:', $tester->getDisplay());
        self::assertCount(3, $repository->executed);
    }

    public function test_the_dry_run_option_keeps_the_configured_table_untouched(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p202505' => '739768',
            'pMax' => 'MAXVALUE',
        ], expression: 'to_days(`created_at`)');

        $tester = $this->runCommand($this->command(['tables' => ['transactions' => [
            'strategy' => 'monthly',
            'future' => 0,
        ]]], $repository), ['table' => 'transactions', '--dry-run' => true]);

        self::assertStringContainsString('Dry run — nothing was executed.', $tester->getDisplay());
        self::assertSame([], $repository->executed);
    }
}

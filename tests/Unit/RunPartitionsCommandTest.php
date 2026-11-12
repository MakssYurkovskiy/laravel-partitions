<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Unit;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use PHPUnit\Framework\Attributes\DataProvider;
use Yurkovskiy\Partitions\Console\RunPartitionsCommand;
use Yurkovskiy\Partitions\Exceptions\UnknownTableException;
use Yurkovskiy\Partitions\PartitionManager;
use Yurkovskiy\Partitions\Schema\TableInfo;
use Yurkovskiy\Partitions\Tests\Support\CommandTestCase;
use Yurkovskiy\Partitions\Tests\Support\CountingDailyStrategy;
use Yurkovskiy\Partitions\Tests\Support\FakePartitionRepository;

class RunPartitionsCommandTest extends CommandTestCase
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
    protected function command(FakePartitionRepository $repository, array $config = []): RunPartitionsCommand
    {
        return $this->withManager(new RunPartitionsCommand, new PartitionManager($repository, null, $config));
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

    public function test_it_warns_when_no_table_is_configured(): void
    {
        $repository = $this->partitioned();

        $tester = $this->runCommand($this->command($repository));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No tables configured in config/partitions.php.', $tester->getDisplay());
        self::assertSame([], $repository->executed);
    }

    public function test_an_unknown_table_argument_throws(): void
    {
        $this->expectException(UnknownTableException::class);
        $this->expectExceptionMessage('audit');

        $this->runCommand(
            $this->command($this->partitioned(), ['tables' => ['transactions' => []]]),
            ['table' => 'audit'],
        );
    }

    public function test_it_maintains_every_configured_table(): void
    {
        $repository = $this->partitioned();

        $tester = $this->runCommand($this->command($repository, ['tables' => [
            'transactions' => ['column' => 'created_at', 'future' => 1, 'retain' => 1],
            'audit' => ['table' => 'audit_logs', 'column' => 'created_at', 'future' => 0],
        ]]));

        $output = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('transactions', $output);
        self::assertStringContainsString('audit_logs', $output);
        self::assertMatchesRegularExpression('/transactions\s+\|\s+transactions\s+\|\s+1\s+\|\s+2\s+\|\s+3/', $output);
        self::assertMatchesRegularExpression('/audit\s+\|\s+audit_logs\s+\|\s+0\s+\|\s+0\s+\|\s+0/', $output);
        self::assertSame([
            'ALTER TABLE `transactions`
REORGANIZE PARTITION `pMax` INTO (
    PARTITION `p20250614` VALUES LESS THAN (739782),
    PARTITION `pMax` VALUES LESS THAN MAXVALUE
)',
            'ALTER TABLE `transactions` DROP PARTITION `p20250610`',
            'ALTER TABLE `transactions` DROP PARTITION `p20250611`',
        ], $repository->executed);
    }

    public function test_a_single_table_can_be_named(): void
    {
        $repository = $this->partitioned();

        $tester = $this->runCommand($this->command($repository, ['tables' => [
            'transactions' => ['column' => 'created_at', 'future' => 1],
            'audit' => ['table' => 'audit_logs', 'column' => 'created_at', 'retain' => 1],
        ]]), ['table' => 'transactions']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringNotContainsString('audit_logs', $tester->getDisplay());
        self::assertCount(1, $repository->executed);
        self::assertStringContainsString('REORGANIZE PARTITION', $repository->executed[0]);
    }

    public function test_a_run_plans_each_table_once(): void
    {
        CountingDailyStrategy::$plans = 0;
        $repository = $this->partitioned();

        $tester = $this->runCommand($this->command($repository, ['tables' => [
            'transactions' => ['column' => 'created_at', 'strategy' => CountingDailyStrategy::class, 'future' => 1, 'max_gaps' => 6],
        ]]));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(1, CountingDailyStrategy::$plans);
        self::assertCount(1, $repository->executed);
    }

    public function test_an_unpartitioned_table_fails_the_run(): void
    {
        $repository = new FakePartitionRepository([], new TableInfo('transactions'));

        $tester = $this->runCommand($this->command($repository, ['tables' => [
            'transactions' => ['column' => 'created_at'],
        ]]));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertMatchesRegularExpression(
            '/transactions\s+\|\s+transactions\s+\|\s+—\s+\|\s+—\s+\|\s+not partitioned/',
            $tester->getDisplay()
        );
        self::assertSame([], $repository->executed);
    }

    public function test_a_table_that_does_not_exist_fails_the_run(): void
    {
        $repository = new FakePartitionRepository;

        $tester = $this->runCommand($this->command($repository, ['tables' => [
            'transactions' => ['table' => 'transactoins'],
        ]]));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertMatchesRegularExpression(
            '/transactions\s+\|\s+transactoins\s+\|\s+—\s+\|\s+—\s+\|\s+table does not exist/',
            $tester->getDisplay()
        );
        self::assertSame([], $repository->executed);
    }

    #[DataProvider('brokenEntries')]
    public function test_an_entry_that_is_not_an_array_is_reported_and_the_rest_is_maintained(mixed $entry, string $type): void
    {
        $repository = $this->partitioned();

        $tester = $this->runCommand($this->command($repository, ['tables' => [
            'transactions' => $entry,
            'audit' => ['table' => 'audit_logs', 'future' => 1],
        ]]));

        $output = $tester->getDisplay();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertMatchesRegularExpression(
            '/transactions\s+\|\s+transactions\s+\|\s+—\s+\|\s+—\s+\|\s+Table \[transactions\] in config\/partitions\.php has to be an array of settings, '.$type.' given\./',
            $output
        );
        self::assertMatchesRegularExpression('/audit\s+\|\s+audit_logs\s+\|\s+1\s+\|\s+0\s+\|\s+1/', $output);
        self::assertCount(1, $repository->executed);
        self::assertStringContainsString('ALTER TABLE `audit_logs`', $repository->executed[0]);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function brokenEntries(): array
    {
        return [
            'a strategy name' => ['daily', 'string'],
            'null' => [null, 'null'],
        ];
    }

    public function test_a_lone_broken_entry_fails_the_run(): void
    {
        $repository = $this->partitioned();

        $tester = $this->runCommand($this->command($repository, ['tables' => ['transactions' => 'daily']]));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringNotContainsString('No tables configured', $tester->getDisplay());
        self::assertStringContainsString('has to be an array of settings, string given.', $tester->getDisplay());
        self::assertSame([], $repository->executed);
    }

    public function test_a_named_broken_entry_is_reported_as_broken(): void
    {
        $repository = $this->partitioned();

        $tester = $this->runCommand(
            $this->command($repository, ['tables' => ['transactions' => null, 'audit' => []]]),
            ['table' => 'transactions'],
        );

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringNotContainsString('is not configured', $tester->getDisplay());
        self::assertStringContainsString('has to be an array of settings, null given.', $tester->getDisplay());
        self::assertSame([], $repository->executed);
    }

    public function test_a_failing_table_does_not_stop_the_others(): void
    {
        $repository = $this->partitioned();

        $tester = $this->runCommand($this->command($repository, ['tables' => [
            'broken' => ['table' => 'transactions', 'column' => 'created_at', 'expression' => 'columns'],
            'transactions' => ['column' => 'created_at', 'future' => 1],
        ]]));

        $output = $tester->getDisplay();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('broken', $output);
        self::assertStringContainsString('is partitioned by', $output);
        self::assertStringContainsString('transactions', $output);
        self::assertCount(1, $repository->executed);
        self::assertStringContainsString('PARTITION `p20250614`', $repository->executed[0]);
    }

    public function test_a_table_whose_config_cannot_be_built_does_not_stop_the_others(): void
    {
        $repository = $this->partitioned();

        $tester = $this->runCommand($this->command($repository, ['tables' => [
            'logs' => ['table' => 'app_logs', 'timezone' => 'Mars/Base'],
            'audit' => ['strategy' => 'hourly'],
            'unnamed' => ['table' => '', 'strategy' => 'hourly'],
            'transactions' => ['future' => 1],
        ]]));

        $output = $tester->getDisplay();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertMatchesRegularExpression(
            '/logs\s+\|\s+app_logs\s+\|\s+—\s+\|\s+—\s+\|\s+Invalid timezone \[Mars\/Base\]/',
            $output
        );
        self::assertMatchesRegularExpression(
            '/audit\s+\|\s+audit\s+\|\s+—\s+\|\s+—\s+\|\s+Unknown partition strategy \[hourly\]/',
            $output
        );
        self::assertMatchesRegularExpression(
            '/unnamed\s+\|\s+unnamed\s+\|\s+—\s+\|\s+—\s+\|\s+Unknown partition strategy \[hourly\]/',
            $output
        );
        self::assertMatchesRegularExpression('/transactions\s+\|\s+transactions\s+\|\s+1\s+\|\s+0\s+\|\s+1/', $output);
        self::assertCount(1, $repository->executed);
        self::assertStringContainsString('PARTITION `p20250614`', $repository->executed[0]);
    }

    public function test_a_named_table_whose_config_cannot_be_built_is_reported(): void
    {
        $repository = $this->partitioned();

        $tester = $this->runCommand($this->command($repository, ['tables' => [
            'logs' => ['timezone' => 'Mars/Base'],
        ]]), ['table' => 'logs', '--dry-run' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertMatchesRegularExpression('/logs\s+\|\s+logs\s+\|\s+—\s+\|\s+—\s+\|\s+Invalid timezone/', $tester->getDisplay());
        self::assertSame([], $repository->executed);
    }

    public function test_a_table_configured_for_another_period_is_reported_and_left_alone(): void
    {
        $repository = $this->partitioned();

        $tester = $this->runCommand($this->command($repository, ['tables' => [
            'monthly' => ['table' => 'transactions', 'strategy' => 'monthly', 'future' => 3, 'retain' => 0],
            'transactions' => ['future' => 1],
        ]]));

        $output = $tester->getDisplay();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Table [transactions] holds daily partitions', $output);
        self::assertStringContainsString('but the builder is configured for monthly ones', $output);
        self::assertCount(1, $repository->executed);
        self::assertStringContainsString('PARTITION `p20250614`', $repository->executed[0]);
    }

    public function test_the_gap_limit_is_reported_per_table(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p202312' => '739251',
            'pMax' => 'MAXVALUE',
        ], expression: 'to_days(`created_at`)');

        $tester = $this->runCommand($this->command($repository, ['tables' => [
            'transactions' => ['column' => 'created_at', 'strategy' => 'monthly', 'future' => 0, 'max_gaps' => 3],
        ]]));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('3 (+15 left)', $tester->getDisplay());
        self::assertCount(1, $repository->executed);
    }

    public function test_a_dry_run_executes_nothing(): void
    {
        $repository = $this->partitioned();

        $tester = $this->runCommand($this->command($repository, ['tables' => [
            'transactions' => ['column' => 'created_at', 'future' => 1, 'retain' => 1],
        ]]), ['--dry-run' => true]);

        $output = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Dry run — nothing was executed.', $output);
        self::assertSame([], $repository->executed);
    }

    public function test_a_dry_run_reports_the_session_timezone_a_real_run_would_refuse(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p20250612' => '1749772800',
            'p20250613' => '1749859200',
            'pMax' => 'MAXVALUE',
        ], expression: 'unix_timestamp(`created_at`)');
        $repository->sessionTimezone = 'Etc/GMT-3';

        $tester = $this->runCommand($this->command($repository, ['tables' => [
            'transactions' => ['column' => 'created_at', 'expression' => 'unix_timestamp', 'timezone' => 'UTC', 'verify_timezone' => true, 'future' => 1],
        ]]), ['--dry-run' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('are computed in [UTC]', $tester->getDisplay());
        self::assertCount(1, $repository->timestampQueries);
        self::assertSame([], $repository->executed);
    }
}

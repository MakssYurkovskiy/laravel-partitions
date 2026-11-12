<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Unit;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Yurkovskiy\Partitions\Console\ListPartitionsCommand;
use Yurkovskiy\Partitions\Exceptions\ExpressionMismatchException;
use Yurkovskiy\Partitions\Exceptions\StrategyMismatchException;
use Yurkovskiy\Partitions\PartitionManager;
use Yurkovskiy\Partitions\Schema\TableInfo;
use Yurkovskiy\Partitions\Tests\Support\CommandTestCase;
use Yurkovskiy\Partitions\Tests\Support\FakePartitionRepository;

class ListPartitionsCommandTest extends CommandTestCase
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
    protected function command(FakePartitionRepository $repository, array $config = []): ListPartitionsCommand
    {
        return $this->withManager(new ListPartitionsCommand, new PartitionManager($repository, null, $config));
    }

    protected function partitioned(): FakePartitionRepository
    {
        return FakePartitionRepository::withPartitions([
            'p20250611' => '739779',
            'p20250612' => '739780',
            'pMax' => 'MAXVALUE',
        ], expression: 'to_days(`created_at`)');
    }

    public function test_a_table_that_does_not_exist_is_not_called_unpartitioned(): void
    {
        $tester = $this->runCommand($this->command(new FakePartitionRepository), ['table' => 'transactoins']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Table [transactoins] does not exist.', $tester->getDisplay());
        self::assertStringNotContainsString('is not partitioned', $tester->getDisplay());
    }

    public function test_an_unpartitioned_table_is_reported_as_such(): void
    {
        $tester = $this->runCommand(
            $this->command(new FakePartitionRepository([], new TableInfo(name: 'transactions'))),
            ['table' => 'transactions', '--column' => 'created_at'],
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Table [transactions] is not partitioned.', $tester->getDisplay());
    }

    public function test_it_lists_the_partitions_with_their_boundaries_and_size(): void
    {
        $tester = $this->runCommand(
            $this->command($this->partitioned()),
            ['table' => 'transactions', '--column' => 'created_at'],
        );

        $output = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('p20250611', $output);
        self::assertStringContainsString('739779', $output);
        self::assertStringContainsString('2025-06-11', $output);
        self::assertStringContainsString('MAXVALUE', $output);
        self::assertStringContainsString('1.5 KB', $output);
        self::assertStringContainsString('3 partitions, ~30 rows, 4.5 KB on disk.', $output);
    }

    public function test_the_catch_all_partition_covers_no_period(): void
    {
        $tester = $this->runCommand(
            $this->command($this->partitioned()),
            ['table' => 'transactions', '--column' => 'created_at'],
        );

        self::assertMatchesRegularExpression('/pMax\s+\|\s+MAXVALUE\s+\|\s+—/', $tester->getDisplay());
    }

    public function test_the_missing_partitions_are_listed_on_request(): void
    {
        $tester = $this->runCommand(
            $this->command($this->partitioned()),
            ['table' => 'transactions', '--column' => 'created_at', '--missing' => true],
        );

        $output = $tester->getDisplay();

        self::assertStringContainsString('Missing', $output);
        self::assertStringContainsString('p20250612', $output);
        self::assertStringContainsString('p20250613', $output);
        self::assertStringContainsString('p20250620', $output);
        self::assertStringNotContainsString('p20250621', $output);
    }

    public function test_nothing_is_missing_when_the_range_is_covered(): void
    {
        $covered = [];

        foreach (range(13, 20) as $day) {
            $covered["p202506$day"] = (string) (739768 + $day);
        }

        $repository = FakePartitionRepository::withPartitions(
            $covered + ['pMax' => 'MAXVALUE'],
            expression: 'to_days(`created_at`)'
        );

        $tester = $this->runCommand(
            $this->command($repository),
            ['table' => 'transactions', '--column' => 'created_at', '--missing' => true],
        );

        self::assertStringContainsString('No missing partitions for the configured range.', $tester->getDisplay());
    }

    public function test_a_configured_table_is_read_from_its_own_connection(): void
    {
        $repository = $this->partitioned();

        $tester = $this->runCommand($this->command($repository, ['tables' => ['payments' => [
            'table' => 'transactions',
            'column' => 'created_at',
            'connection' => 'archive',
        ]]]), ['table' => 'payments']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('3 partitions', $tester->getDisplay());
        self::assertSame(['archive'], collect($repository->requested)->pluck(0)->unique()->all());
        self::assertSame(['transactions'], collect($repository->requested)->pluck(1)->unique()->all());
    }

    public function test_listing_runs_no_ddl(): void
    {
        $repository = $this->partitioned();

        $this->runCommand(
            $this->command($repository),
            ['table' => 'transactions', '--column' => 'created_at', '--missing' => true],
        );

        self::assertSame([], $repository->executed);
    }

    public function test_asking_for_the_missing_ones_refuses_a_mismatched_expression(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p20250611' => "'2025-06-12'",
            'pMax' => 'MAXVALUE',
        ], method: 'RANGE COLUMNS', expression: '`created_at`');

        $this->expectException(ExpressionMismatchException::class);

        $this->runCommand(
            $this->command($repository),
            ['table' => 'transactions', '--column' => 'created_at', '--missing' => true],
        );
    }

    public function test_asking_for_the_missing_ones_refuses_another_period(): void
    {
        $this->expectException(StrategyMismatchException::class);
        $this->expectExceptionMessage('holds daily partitions');

        $this->runCommand(
            $this->command($this->partitioned()),
            ['table' => 'transactions', '--strategy' => 'yearly', '--missing' => true],
        );
    }

    public function test_the_partitions_of_another_period_can_still_be_listed(): void
    {
        $tester = $this->runCommand(
            $this->command($this->partitioned()),
            ['table' => 'transactions', '--strategy' => 'yearly'],
        );

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('p20250612', $tester->getDisplay());
    }
}

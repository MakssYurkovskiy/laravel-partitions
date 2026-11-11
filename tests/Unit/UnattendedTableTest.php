<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Unit;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Yurkovskiy\Partitions\Console\RunPartitionsCommand;
use Yurkovskiy\Partitions\PartitionBuilder;
use Yurkovskiy\Partitions\PartitionManager;
use Yurkovskiy\Partitions\Schema\PartitionInfo;
use Yurkovskiy\Partitions\Schema\PendingPartition;
use Yurkovskiy\Partitions\Tests\Support\CommandTestCase;
use Yurkovskiy\Partitions\Tests\Support\StatefulPartitionRepository;

class UnattendedTableTest extends CommandTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-11-12 03:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function abandoned(): StatefulPartitionRepository
    {
        $partitions = [];

        for ($day = CarbonImmutable::parse('2026-06-01'); $day->format('Y-m-d') <= '2026-07-22'; $day = $day->addDay()) {
            $partitions['p'.$day->format('Ymd')] = (string) (719528 + intdiv($day->addDay()->getTimestamp(), 86400));
        }

        $partitions['pMax'] = 'MAXVALUE';

        return StatefulPartitionRepository::withPartitions($partitions, expression: 'to_days(`created_at`)');
    }

    protected function builder(StatefulPartitionRepository $repository): PartitionBuilder
    {
        return (new PartitionBuilder($repository))
            ->table('transactions')
            ->daily('created_at')
            ->future(7)
            ->retain(90)
            ->maxGaps(12);
    }

    /**
     * @return list<string>
     */
    protected function days(string $from, string $to): array
    {
        $names = [];

        for ($day = CarbonImmutable::parse($from); $day->format('Y-m-d') <= $to; $day = $day->addDay()) {
            $names[] = 'p'.$day->format('Ymd');
        }

        return $names;
    }

    public function test_maintain_fills_from_the_retention_cutoff_and_keeps_what_it_created(): void
    {
        $repository = $this->abandoned();

        $this->builder($repository)->maintain();

        self::assertSame([...$this->days('2026-08-14', '2026-08-25'), 'pMax'], $repository->names());
    }

    public function test_the_next_run_continues_from_the_last_boundary(): void
    {
        $repository = $this->abandoned();

        $this->builder($repository)->maintain();

        CarbonImmutable::setTestNow('2026-11-13 03:00:00');

        $this->builder($repository)->maintain();

        self::assertSame([...$this->days('2026-08-15', '2026-09-06'), 'pMax'], $repository->names());
    }

    public function test_a_table_catches_up_run_after_run(): void
    {
        $repository = $this->abandoned();

        for ($night = 0; $night < 12; $night++) {
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-12 03:00:00')->addDays($night));

            $this->builder($repository)->maintain();
        }

        self::assertSame([...$this->days('2026-08-25', '2026-11-30'), 'pMax'], $repository->names());
        self::assertTrue($this->builder($repository)->plan()->deferred->isEmpty());
    }

    public function test_a_dry_run_reports_the_drops_of_a_real_run(): void
    {
        $dry = $this->builder($this->abandoned())->dryRun()->maintain();
        $real = $this->builder($this->abandoned())->maintain();

        self::assertCount(52, $real['dropped']);
        self::assertSame($real['dropped']->all(), $dry['dropped']->all());
        self::assertSame($real['added']->all(), $dry['added']->all());
    }

    public function test_the_run_command_counts_the_drops_it_makes(): void
    {
        $config = ['tables' => ['transactions' => ['column' => 'created_at', 'retain' => 90]]];

        $repository = $this->abandoned();
        $dry = $this->runCommand(
            new RunPartitionsCommand(new PartitionManager($repository, null, $config)),
            ['--dry-run' => true],
        );

        self::assertSame(Command::SUCCESS, $dry->getStatusCode());
        self::assertMatchesRegularExpression('/12 \(\+86 left\)\s+\|\s+52\s+\|/', $dry->getDisplay());
        self::assertSame([], $repository->executed);

        $real = $this->runCommand(new RunPartitionsCommand(new PartitionManager($repository, null, $config)));

        self::assertSame(Command::SUCCESS, $real->getStatusCode());
        self::assertMatchesRegularExpression('/12 \(\+86 left\)\s+\|\s+52\s+\|/', $real->getDisplay());
        self::assertCount(52, array_filter($repository->executed, fn (string $sql) => str_contains($sql, 'DROP PARTITION')));
        self::assertSame([...$this->days('2026-08-14', '2026-08-25'), 'pMax'], $repository->names());
    }

    public function test_sync_skips_the_periods_retention_has_already_expired(): void
    {
        $missing = $this->builder($this->abandoned())
            ->missing()
            ->map(fn (PendingPartition $pending) => $pending->name)
            ->all();

        self::assertSame($this->days('2026-08-14', '2026-11-19'), $missing);
    }

    public function test_without_a_retention_every_skipped_period_is_planned(): void
    {
        $missing = $this->builder($this->abandoned())
            ->retain(null)
            ->missing()
            ->map(fn (PendingPartition $pending) => $pending->name)
            ->all();

        self::assertSame($this->days('2026-07-23', '2026-11-19'), $missing);
    }

    public function test_an_explicit_start_is_not_moved_to_the_retention_cutoff(): void
    {
        $missing = $this->builder($this->abandoned())
            ->from('2026-08-01')
            ->missing()
            ->map(fn (PendingPartition $pending) => $pending->name)
            ->all();

        self::assertSame($this->days('2026-08-01', '2026-11-19'), $missing);
    }

    public function test_prune_keeps_the_last_bounded_partition(): void
    {
        $repository = $this->abandoned();

        $outdated = $this->builder($repository)
            ->outdated()
            ->map(fn (PartitionInfo $partition) => $partition->name)
            ->all();

        self::assertSame($this->days('2026-06-01', '2026-07-21'), $outdated);

        $this->builder($repository)->prune();

        self::assertSame(['p20260722', 'pMax'], $repository->names());
    }

    public function test_the_run_after_a_lone_prune_still_continues_from_the_last_boundary(): void
    {
        $repository = $this->abandoned();

        $this->builder($repository)->prune();
        $this->builder($repository)->maintain();

        self::assertSame([...$this->days('2026-08-14', '2026-08-25'), 'pMax'], $repository->names());
    }

    public function test_an_explicit_date_still_drops_the_last_bounded_partition(): void
    {
        $repository = $this->abandoned();

        $this->builder($repository)->dropBefore('2026-08-01');

        self::assertSame(['pMax'], $repository->names());
    }
}

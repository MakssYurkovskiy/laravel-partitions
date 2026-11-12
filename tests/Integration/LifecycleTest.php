<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use Yurkovskiy\Partitions\Events\PartitionsCreated;
use Yurkovskiy\Partitions\Events\PartitionsDropped;
use Yurkovskiy\Partitions\Exceptions\ExpressionMismatchException;
use Yurkovskiy\Partitions\Exceptions\NotPartitionedException;
use Yurkovskiy\Partitions\Exceptions\StrategyMismatchException;
use Yurkovskiy\Partitions\Exceptions\TimezoneMismatchException;
use Yurkovskiy\Partitions\Tests\Support\IntegrationTestCase;

class LifecycleTest extends IntegrationTestCase
{
    protected const PERIODS = [
        'daily' => [
            'created' => ['p20261112', 'p20261113', 'p20261114'],
            'added' => ['p20261115', 'p20261116'],
            'before' => '2026-11-14',
        ],
        'weekly' => [
            'created' => ['p202646', 'p202647', 'p202648'],
            'added' => ['p202649', 'p202650'],
            'before' => '2026-11-23',
        ],
        'monthly' => [
            'created' => ['p202611', 'p202612', 'p202701'],
            'added' => ['p202702', 'p202703'],
            'before' => '2027-01-01',
        ],
        'yearly' => [
            'created' => ['p2026', 'p2027', 'p2028'],
            'added' => ['p2029', 'p2030'],
            'before' => '2028-01-01',
        ],
    ];

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function layouts(): iterable
    {
        foreach (['to_days' => 'datetime', 'columns' => 'datetime', 'unix_timestamp' => 'timestamp'] as $expression => $type) {
            foreach (array_keys(self::PERIODS) as $strategy) {
                yield "$strategy by $expression" => [$strategy, $expression, $type];
            }
        }
    }

    #[DataProvider('layouts')]
    public function test_a_table_is_partitioned_extended_and_pruned(string $strategy, string $expression, string $type): void
    {
        $period = self::PERIODS[$strategy];
        $this->createTable('transactions', $type);

        $this->builder('transactions')->strategy($strategy)->expression($expression)->future(2)->create();

        $this->assertSame([...$period['created'], 'pMax'], $this->partitionsOf('transactions'));

        $builder = $this->builder('transactions')->strategy($strategy)->expression($expression)->future(4);

        $this->assertCount(1, $builder->sync());
        $this->assertSame([...$period['created'], ...$period['added'], 'pMax'], $this->partitionsOf('transactions'));
        $this->assertCount(0, $builder->sync());

        $this->assertCount(2, $builder->dropBefore($period['before']));
        $this->assertSame(
            [$period['created'][2], ...$period['added'], 'pMax'],
            $this->partitionsOf('transactions')
        );

        $this->assertSame(
            [PartitionsCreated::class, PartitionsCreated::class, PartitionsDropped::class],
            array_map(fn (object $event) => $event::class, $this->events->dispatched)
        );
    }

    public function test_a_table_without_a_max_partition_grows_by_add_partition(): void
    {
        $this->createTable('transactions');

        $this->builder('transactions')->daily()->withoutMaxPartition()->future(1)->create();
        $statements = $this->builder('transactions')->daily()->withoutMaxPartition()->future(3)->sync();

        $this->assertStringContainsString('ADD PARTITION', (string) $statements->first());
        $this->assertSame(['p20261112', 'p20261113', 'p20261114', 'p20261115'], $this->partitionsOf('transactions'));
    }

    public function test_a_plain_table_is_refused_before_the_server_refuses_it(): void
    {
        $this->createTable('transactions');

        $this->expectException(NotPartitionedException::class);

        $this->builder('transactions')->daily()->future(1)->sync();
    }

    public function test_reorganising_the_max_partition_moves_its_rows_into_the_new_partitions(): void
    {
        $this->createTable('transactions');
        $this->builder('transactions')->daily()->future(0)->create();

        $this->connection()->table('transactions')->insert([
            ['id' => 1, 'created_at' => '2026-11-12 08:00:00'],
            ['id' => 2, 'created_at' => '2026-11-13 08:00:00'],
            ['id' => 3, 'created_at' => '2026-11-14 08:00:00'],
            ['id' => 4, 'created_at' => '2026-12-01 08:00:00'],
        ]);

        $this->assertSame(3, $this->rowsIn('transactions', 'pMax'));

        $this->builder('transactions')->daily()->future(2)->sync();

        $this->assertSame(1, $this->rowsIn('transactions', 'p20261113'));
        $this->assertSame(1, $this->rowsIn('transactions', 'p20261114'));
        $this->assertSame(1, $this->rowsIn('transactions', 'pMax'));

        $builder = $this->builder('transactions')->daily();
        $builder->drop($builder->inRange('2026-11-13', '2026-11-13'));

        $this->assertSame(3, $this->connection()->table('transactions')->count());
    }

    public function test_tablespace_truncate_and_remove_partitioning_run(): void
    {
        $this->createTable('transactions');

        $builder = $this->builder('transactions')->monthly()->tablespace('innodb_file_per_table')->future(1);
        $builder->create();

        $this->connection()->table('transactions')->insert(['id' => 1, 'created_at' => '2026-11-12 08:00:00']);

        $builder->truncate($builder->inRange('2026-11-01', '2026-11-30'));

        $this->assertSame(0, $this->connection()->table('transactions')->count());
        $this->assertSame(['p202611', 'p202612', 'pMax'], $this->partitionsOf('transactions'));

        $builder->removePartitioning();

        $this->assertSame([], $this->partitionsOf('transactions'));
    }

    public function test_subpartitions_are_read_as_one_partition_each(): void
    {
        $this->track('transactions');
        $this->connection()->statement('drop table if exists `transactions`');
        $this->connection()->statement(
            'create table `transactions` (id bigint not null, created_at datetime not null, primary key (id, created_at)) '
            .'partition by range (to_days(created_at)) subpartition by hash (id) subpartitions 2 ('
            .'partition p20261111 values less than (740297), '
            .'partition p20261112 values less than (740298), '
            .'partition pMax values less than maxvalue)'
        );

        $this->assertSame(['p20261111', 'p20261112', 'pMax'], $this->partitionsOf('transactions'));

        $this->builder('transactions')->daily()->future(2)->sync();

        $this->assertSame(
            ['p20261111', 'p20261112', 'p20261113', 'p20261114', 'pMax'],
            $this->partitionsOf('transactions')
        );
    }

    public function test_a_connection_prefix_and_a_schema_name_reach_the_same_table(): void
    {
        $this->createTable('transactions', connection: 'prefixed');

        $this->builder('transactions', 'prefixed')->daily()->future(1)->create();

        $this->assertSame(['p20261112', 'p20261113', 'pMax'], $this->partitionsOf('transactions', 'prefixed'));
        $this->assertSame(['p20261112', 'p20261113', 'pMax'], $this->partitionsOf('app_transactions'));
        $this->assertSame('app_transactions', $this->builder('transactions', 'prefixed')->tableInfo()?->name);

        $qualified = $this->connection()->getDatabaseName().'.app_transactions';

        $this->builder($qualified)->daily()->future(2)->sync();

        $this->assertSame(['p20261112', 'p20261113', 'p20261114', 'pMax'], $this->partitionsOf($qualified));
    }

    public function test_the_session_timezone_is_checked_against_the_boundaries(): void
    {
        $this->createTable('transactions', 'timestamp');

        $this->builder('transactions', 'shifted')
            ->daily()
            ->usingUnixTimestamp()
            ->verifyTimezone()
            ->future(1)
            ->create();

        $boundaries = $this->builder('transactions', 'shifted')->existing()->pluck('description', 'name')->all();

        $this->assertSame('1794517200', $boundaries['p20261112']);

        $this->expectException(TimezoneMismatchException::class);

        $this->builder('transactions', 'shifted')
            ->daily()
            ->usingUnixTimestamp()
            ->timezone('UTC')
            ->verifyTimezone()
            ->future(3)
            ->sync();
    }

    public function test_boundaries_past_2038_are_written_and_read_back(): void
    {
        $this->createTable('transactions', 'timestamp');

        $this->builder('transactions', 'shifted')
            ->yearly()
            ->usingUnixTimestamp()
            ->verifyTimezone()
            ->between('2037-01-01', '2039-01-01')
            ->create();

        $this->assertSame(['p2037', 'p2038', 'p2039', 'pMax'], $this->partitionsOf('transactions'));
        $this->assertSame(
            ['p2040'],
            $this->builder('transactions', 'shifted')
                ->yearly()
                ->usingUnixTimestamp()
                ->between('2037-01-01', '2040-01-01')
                ->missing()
                ->pluck('name')
                ->all()
        );
    }

    #[DataProvider('layouts')]
    public function test_another_period_is_refused_from_the_metadata_of_the_server(string $strategy, string $expression, string $type): void
    {
        $this->createTable('transactions', $type);
        $this->builder('transactions')->strategy($strategy)->expression($expression)->future(2)->create();

        $this->expectException(StrategyMismatchException::class);

        $this->builder('transactions')
            ->strategy($strategy === 'monthly' ? 'daily' : 'monthly')
            ->expression($expression)
            ->missing();
    }

    #[DataProvider('layouts')]
    public function test_another_expression_is_refused_from_the_metadata_of_the_server(string $strategy, string $expression, string $type): void
    {
        $this->createTable('transactions', $type);
        $this->builder('transactions')->strategy($strategy)->expression($expression)->future(2)->create();

        $this->expectException(ExpressionMismatchException::class);

        $this->builder('transactions')
            ->strategy($strategy)
            ->expression($expression === 'to_days' ? 'columns' : 'to_days')
            ->missing();
    }
}

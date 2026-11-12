<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Unit;

use Illuminate\Database\PostgresConnection;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Yurkovskiy\Partitions\Exceptions\AlreadyPartitionedException;
use Yurkovskiy\Partitions\Exceptions\UnsupportedDriverException;
use Yurkovskiy\Partitions\PartitionBuilder;
use Yurkovskiy\Partitions\Repositories\MySqlPartitionRepository;
use Yurkovskiy\Partitions\Tests\Support\FakeConnectionResolver;
use Yurkovskiy\Partitions\Tests\Support\RecordingConnection;
use Yurkovskiy\Partitions\Tests\Support\ReplicatedConnection;

class MySqlPartitionRepositoryTest extends TestCase
{
    public function test_it_folds_subpartition_rows_into_one_partition_each(): void
    {
        $connection = (new RecordingConnection)->returning([
            $this->row('p20250613', '739780', 1, rows: 10, data: 1024, index: 512),
            $this->row('p20250613', '739780', 1, rows: 20, data: 2048, index: 256),
            $this->row('p20250614', '739781', 2, rows: 5, data: 512, index: 128),
            $this->row('p20250614', '739781', 2, rows: 7, data: 256, index: 64),
        ]);

        $partitions = $this->repository($connection)->partitions(null, 'transactions');

        $this->assertSame(['p20250613', 'p20250614'], $partitions->pluck('name')->all());
        $this->assertSame([30, 12], $partitions->pluck('rows')->all());
        $this->assertSame([3072, 768], $partitions->pluck('dataLength')->all());
        $this->assertSame([768, 192], $partitions->pluck('indexLength')->all());
        $this->assertSame([1, 2], $partitions->pluck('ordinal')->all());
        $this->assertSame(['739780', '739781'], $partitions->pluck('description')->all());
        $this->assertSame('RANGE', $partitions->first()->method);
    }

    public function test_it_leaves_a_table_without_subpartitions_untouched(): void
    {
        $connection = (new RecordingConnection)->returning([
            $this->row('p20250613', '739780', 1, rows: 10, data: 1024, index: 512),
            $this->row('p20250614', '739781', 2, rows: 20, data: 2048, index: 256),
            $this->row('pMax', 'MAXVALUE', 3, rows: 0, data: 16384, index: 0),
        ]);

        $partitions = $this->repository($connection)->partitions(null, 'transactions');

        $this->assertSame(['p20250613', 'p20250614', 'pMax'], $partitions->pluck('name')->all());
        $this->assertSame([10, 20, 0], $partitions->pluck('rows')->all());
        $this->assertSame([1536, 2304, 16384], $partitions->map->totalLength()->all());
        $this->assertTrue($partitions->last()->isMax());
    }

    public function test_it_reads_the_partitions_in_ordinal_order(): void
    {
        $connection = new RecordingConnection;

        $this->repository($connection)->partitions(null, 'transactions');

        $this->assertStringContainsString(
            'order by `PARTITION_ORDINAL_POSITION` asc, `SUBPARTITION_ORDINAL_POSITION` asc',
            $connection->queries[0][0]
        );
        $this->assertStringContainsString('`PARTITION_NAME` is not null', $connection->queries[0][0]);
    }

    public function test_it_scopes_the_lookup_to_the_schema_and_the_table(): void
    {
        $connection = new RecordingConnection('testdb');
        $repository = $this->repository($connection);

        $repository->partitions(null, 'transactions');
        $repository->partitions(null, 'reporting.transactions');

        $this->assertSame(['testdb', 'transactions'], $connection->queries[0][1]);
        $this->assertSame(['reporting', 'transactions'], $connection->queries[1][1]);
    }

    public function test_it_accepts_a_mariadb_connection(): void
    {
        $connection = new RecordingConnection('testdb', ['driver' => 'mariadb']);

        $this->assertCount(0, $this->repository($connection)->partitions(null, 'transactions'));
    }

    public function test_it_accepts_a_mysql_connection_that_reports_no_driver(): void
    {
        $connection = new RecordingConnection('testdb', []);

        $this->assertCount(0, $this->repository($connection)->partitions(null, 'transactions'));
    }

    public function test_it_rejects_a_connection_that_is_not_mysql(): void
    {
        $repository = new MySqlPartitionRepository(new FakeConnectionResolver([
            'reports' => new PostgresConnection(fn () => null, 'reports', '', ['driver' => 'pgsql']),
        ], 'reports'));

        $this->expectException(UnsupportedDriverException::class);
        $this->expectExceptionMessage('connection [reports] uses the [pgsql] driver');

        $repository->partitions('reports', 'transactions');
    }

    public function test_it_rejects_a_non_mysql_connection_on_every_call(): void
    {
        $repository = new MySqlPartitionRepository(new FakeConnectionResolver([
            'pgsql' => new PostgresConnection(fn () => null, 'reports', '', ['driver' => 'pgsql']),
        ], 'pgsql'));

        $calls = [
            'partitions' => fn () => $repository->partitions(null, 'transactions'),
            'tableInfo' => fn () => $repository->tableInfo(null, 'transactions'),
            'tablePrefix' => fn () => $repository->tablePrefix(null),
            'connectionTimezone' => fn () => $repository->connectionTimezone(null),
            'unixTimestamps' => fn () => $repository->unixTimestamps(null, ['2025-06-14 00:00:00']),
            'statement' => fn () => $repository->statement(null, 'alter table `transactions` add partition (...)'),
        ];

        foreach ($calls as $method => $call) {
            try {
                $call();
                $this->fail(sprintf('%s() accepted a pgsql connection.', $method));
            } catch (UnsupportedDriverException $e) {
                $this->assertStringContainsString('[pgsql] driver', $e->getMessage());
            }
        }
    }

    public function test_it_names_the_connection_class_when_the_driver_is_unknown(): void
    {
        $repository = new MySqlPartitionRepository(new FakeConnectionResolver([
            'mysql' => new PostgresConnection(fn () => null, 'reports'),
        ]));

        $this->expectException(UnsupportedDriverException::class);
        $this->expectExceptionMessage(sprintf('the [%s] driver', PostgresConnection::class));

        $repository->partitions(null, 'transactions');
    }

    public function test_it_reads_the_table_itself_from_information_schema(): void
    {
        $connection = (new RecordingConnection('testdb'))->returning([(object) [
            'TABLE_NAME' => 'transactions',
            'TABLE_ROWS' => 1234,
            'DATA_LENGTH' => 2048,
            'INDEX_LENGTH' => 1024,
            'ENGINE' => 'InnoDB',
        ]]);

        $table = $this->repository($connection)->tableInfo(null, 'reporting.transactions');

        $this->assertSame('transactions', $table?->name);
        $this->assertSame(1234, $table->rows);
        $this->assertSame(3072, $table->totalLength());
        $this->assertSame('InnoDB', $table->engine);
        $this->assertStringContainsString('information_schema`.`TABLES', $connection->queries[0][0]);
        $this->assertSame(['reporting', 'transactions'], $connection->queries[0][1]);
    }

    public function test_the_connection_prefix_stays_out_of_information_schema(): void
    {
        $connection = new RecordingConnection('testdb');
        $connection->setTablePrefix('app_');
        $repository = $this->repository($connection);

        $repository->partitions(null, 'transactions');
        $repository->tableInfo(null, 'transactions');

        $this->assertStringContainsString('from `information_schema`.`PARTITIONS` where', $connection->queries[0][0]);
        $this->assertStringContainsString('from `information_schema`.`TABLES` where', $connection->queries[1][0]);
        $this->assertStringNotContainsString('app_', $connection->queries[0][0]);
        $this->assertStringNotContainsString('app_', $connection->queries[1][0]);
    }

    public function test_the_table_prefix_is_the_one_of_the_connection(): void
    {
        $prefixed = new RecordingConnection('testdb');
        $prefixed->setTablePrefix('app_');

        $this->assertSame('app_', $this->repository($prefixed)->tablePrefix(null));
        $this->assertSame('', $this->repository(new RecordingConnection('testdb'))->tablePrefix(null));
    }

    public function test_a_table_information_schema_does_not_know_reads_as_null(): void
    {
        $connection = new RecordingConnection('testdb');

        $this->assertNull($this->repository($connection)->tableInfo(null, 'transactions'));
    }

    public function test_the_connection_timezone_comes_from_its_own_config(): void
    {
        $withTimezone = new RecordingConnection('testdb', ['driver' => 'mysql', 'timezone' => '+00:00']);
        $withoutTimezone = new RecordingConnection('testdb', ['driver' => 'mysql', 'timezone' => '']);

        $this->assertSame('+00:00', $this->repository($withTimezone)->connectionTimezone(null));
        $this->assertNull($this->repository($withoutTimezone)->connectionTimezone(null));
        $this->assertNull($this->repository(new RecordingConnection)->connectionTimezone(null));
    }

    public function test_it_asks_the_server_what_every_datetime_literal_means_in_one_query(): void
    {
        $connection = (new RecordingConnection('testdb'))->returning([
            (object) ['position' => 1, 'epoch' => '1749934800.000000'],
            (object) ['position' => 0, 'epoch' => '1749848400.000000'],
        ]);

        $epochs = $this->repository($connection)->unixTimestamps(null, [
            '2025-06-14 00:00:00',
            '2025-06-15 00:00:00',
            '2025-06-14 00:00:00',
        ]);

        $this->assertSame(['2025-06-15 00:00:00' => 1749934800, '2025-06-14 00:00:00' => 1749848400], $epochs);
        $this->assertCount(1, $connection->queries);
        $this->assertSame(
            'select 0 as position, unix_timestamp(?) as epoch union all select 1 as position, unix_timestamp(?) as epoch',
            $connection->queries[0][0]
        );
        $this->assertSame(['2025-06-14 00:00:00', '2025-06-15 00:00:00'], $connection->queries[0][1]);
    }

    public function test_a_server_that_answers_nothing_reports_no_timestamp(): void
    {
        $silent = new RecordingConnection('testdb');
        $vague = (new RecordingConnection('testdb'))->returning([['position' => 0, 'epoch' => null]]);

        $this->assertSame([], $this->repository($silent)->unixTimestamps(null, ['2025-06-14 00:00:00']));
        $this->assertSame([], $this->repository($vague)->unixTimestamps(null, ['2025-06-14 00:00:00']));
    }

    public function test_no_datetimes_cost_no_query(): void
    {
        $connection = new RecordingConnection('testdb');

        $this->assertSame([], $this->repository($connection)->unixTimestamps(null, []));
        $this->assertSame([], $connection->queries);
    }

    public function test_ddl_is_handed_to_the_connection_as_a_statement(): void
    {
        $connection = new RecordingConnection('testdb');

        $this->assertTrue($this->repository($connection)->statement(null, 'alter table `transactions` drop partition `p20250613`'));
        $this->assertSame(['alter table `transactions` drop partition `p20250613`'], $connection->statements);
    }

    public function test_every_call_goes_through_the_named_connection(): void
    {
        $connection = new RecordingConnection('testdb');
        $resolver = new FakeConnectionResolver(['archive' => $connection]);
        $repository = new MySqlPartitionRepository($resolver);

        $repository->partitions('archive', 'transactions');
        $repository->tableInfo('archive', 'transactions');
        $repository->statement('archive', 'alter table `transactions` remove partitioning');

        $this->assertSame(['archive', 'archive', 'archive'], $resolver->requested);
    }

    public function test_every_read_goes_to_the_write_connection(): void
    {
        $connection = new ReplicatedConnection(['p20250613' => '739781', 'pMax' => 'MAXVALUE']);
        $repository = new MySqlPartitionRepository(new FakeConnectionResolver(['mysql' => $connection]));

        $repository->partitions(null, 'transactions');
        $repository->tableInfo(null, 'transactions');
        $repository->unixTimestamps(null, ['2025-06-14 00:00:00']);

        $this->assertSame([], $connection->replicaReads);
        $this->assertCount(3, $connection->primaryReads);
    }

    public function test_a_retry_after_a_partial_failure_plans_from_the_primary(): void
    {
        $connection = new ReplicatedConnection(['p20250613' => '739781', 'pMax' => 'MAXVALUE']);
        $connection->failingStatement = 2;
        $repository = new MySqlPartitionRepository(new FakeConnectionResolver(['mysql' => $connection]));

        $sync = fn () => (new PartitionBuilder($repository))
            ->table('transactions')
            ->daily('created_at')
            ->to('2025-06-15')
            ->syncChunk(1)
            ->sync();

        try {
            $sync();
            $this->fail('The second statement was expected to fail.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Lock wait timeout', $e->getMessage());
            $this->assertCount(1, $connection->statements);
        }

        $retried = $sync();

        $this->assertCount(1, $retried);
        $this->assertStringContainsString('PARTITION `p20250615` VALUES LESS THAN', $retried[0]);
        $this->assertStringNotContainsString('`p20250614`', $retried[0]);
        $this->assertSame(['p20250613', 'p20250614', 'p20250615', 'pMax'], array_keys($connection->primary));
        $this->assertSame(['p20250613', 'pMax'], array_keys($connection->replica));
        $this->assertSame([], $connection->replicaReads);
    }

    public function test_a_table_the_replica_has_not_seen_partitioned_is_not_partitioned_again(): void
    {
        $connection = new ReplicatedConnection;
        $connection->primary = ['p20250613' => '739781', 'pMax' => 'MAXVALUE'];
        $repository = new MySqlPartitionRepository(new FakeConnectionResolver(['mysql' => $connection]));

        try {
            (new PartitionBuilder($repository))
                ->table('transactions')
                ->daily('created_at')
                ->from('2025-06-13')
                ->to('2025-06-15')
                ->create();
            $this->fail('The table is already partitioned on the primary.');
        } catch (AlreadyPartitionedException) {
            $this->assertSame([], $connection->statements);
        }
    }

    protected function repository(RecordingConnection $connection): MySqlPartitionRepository
    {
        return new MySqlPartitionRepository(new FakeConnectionResolver(['mysql' => $connection]));
    }

    protected function row(string $name, string $description, int $ordinal, int $rows, int $data, int $index): object
    {
        return (object) [
            'PARTITION_NAME' => $name,
            'PARTITION_DESCRIPTION' => $description,
            'PARTITION_ORDINAL_POSITION' => $ordinal,
            'PARTITION_METHOD' => 'RANGE',
            'PARTITION_EXPRESSION' => 'to_days(`created_at`)',
            'TABLE_ROWS' => $rows,
            'DATA_LENGTH' => $data,
            'INDEX_LENGTH' => $index,
        ];
    }
}

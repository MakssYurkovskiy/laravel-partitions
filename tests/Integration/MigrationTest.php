<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Integration;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use PHPUnit\Framework\Attributes\DataProvider;
use Yurkovskiy\Partitions\Exceptions\AlreadyPartitionedException;
use Yurkovskiy\Partitions\Exceptions\NotPartitionedException;
use Yurkovskiy\Partitions\Tests\Support\IntegrationTestCase;

class MigrationTest extends IntegrationTestCase
{
    /**
     * @return iterable<string, array{0: string|null}>
     */
    public static function connections(): iterable
    {
        yield 'default connection' => [null];
        yield 'connection with a prefix' => ['prefixed'];
    }

    #[DataProvider('connections')]
    public function test_a_created_table_is_partitioned_from_its_config_entry(?string $connection): void
    {
        $this->config = ['tables' => ['orders' => ['strategy' => 'monthly', 'future' => 2]]];
        $this->track('orders', $connection);
        $this->schema($connection)->dropIfExists('orders');

        $this->schema($connection)->create('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('id');
            $table->dateTime('created_at');
            $table->primary(['id', 'created_at']);
            $table->partitionByRange('created_at');
        });

        $this->assertSame(['p202611', 'p202612', 'p202701', 'pMax'], $this->partitionsOf('orders', $connection));
    }

    #[DataProvider('connections')]
    public function test_an_existing_table_is_partitioned_replaced_and_unpartitioned(?string $connection): void
    {
        $this->createTable('events', 'timestamp', $connection);

        $this->schema($connection)->table('events', function (Blueprint $table): void {
            $table->partitionByRange('created_at')->weekly()->usingUnixTimestamp()->verifyTimezone()->future(1);
        });

        $this->assertSame(['p202646', 'p202647', 'pMax'], $this->partitionsOf('events', $connection));

        $this->schema($connection)->table('events', function (Blueprint $table): void {
            $table->partitionByRange('created_at')->daily()->usingUnixTimestamp()->future(1)->replacePartitioning();
        });

        $this->assertSame(['p20261112', 'p20261113', 'pMax'], $this->partitionsOf('events', $connection));

        $this->schema($connection)->table('events', fn (Blueprint $table) => $table->removePartitioning());

        $this->assertSame([], $this->partitionsOf('events', $connection));
    }

    public function test_a_partitioned_table_is_not_repartitioned_without_saying_so(): void
    {
        $this->createTable('events');
        $this->builder('events')->daily()->future(1)->create();

        $this->expectException(AlreadyPartitionedException::class);

        $this->schema()->table('events', function (Blueprint $table): void {
            $table->partitionByRange('created_at')->monthly();
        });
    }

    public function test_removing_the_partitioning_of_a_plain_table_is_refused(): void
    {
        $this->createTable('events');

        $this->expectException(NotPartitionedException::class);

        $this->schema()->table('events', fn (Blueprint $table) => $table->removePartitioning());
    }

    private function schema(?string $connection = null): Builder
    {
        return $this->capsule->getConnection($connection)->getSchemaBuilder();
    }
}

<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Support;

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use Yurkovskiy\Partitions\PartitionBuilder;
use Yurkovskiy\Partitions\PartitionManager;
use Yurkovskiy\Partitions\Repositories\MySqlPartitionRepository;
use Yurkovskiy\Partitions\Schema\PartitionInfo;
use Yurkovskiy\Partitions\Schema\PartitionMacros;

abstract class IntegrationTestCase extends TestCase
{
    protected Capsule $capsule;

    protected MySqlPartitionRepository $repository;

    protected FakeDispatcher $events;

    /**
     * @var array<string, mixed>
     */
    protected array $config = [];

    /**
     * @var list<array{0: string|null, 1: string}>
     */
    protected array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('PARTITIONS_DB_HOST');

        if (! is_string($host) || $host === '') {
            $this->markTestSkipped('Set PARTITIONS_DB_HOST to run the suite against a MySQL or MariaDB server.');
        }

        CarbonImmutable::setTestNow('2026-11-12 10:00:00');

        $connection = [
            'driver' => $this->env('PARTITIONS_DB_DRIVER', 'mysql'),
            'host' => $host,
            'port' => (int) $this->env('PARTITIONS_DB_PORT', '3306'),
            'database' => $this->env('PARTITIONS_DB_DATABASE', 'partitions_test'),
            'username' => $this->env('PARTITIONS_DB_USERNAME', 'root'),
            'password' => $this->env('PARTITIONS_DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ];

        $this->capsule = new Capsule;
        $this->capsule->addConnection($connection);
        $this->capsule->addConnection(['prefix' => 'app_'] + $connection, 'prefixed');
        $this->capsule->addConnection(['timezone' => '+03:00'] + $connection, 'shifted');

        $this->repository = new MySqlPartitionRepository($this->capsule->getDatabaseManager());
        $this->events = new FakeDispatcher;

        Container::getInstance()->bind(PartitionManager::class, fn () => $this->manager());

        PartitionMacros::register();
    }

    protected function tearDown(): void
    {
        foreach ($this->created as [$connection, $table]) {
            $this->capsule->getConnection($connection)->getSchemaBuilder()->dropIfExists($table);
        }

        CarbonImmutable::setTestNow();
        Container::setInstance(null);

        parent::tearDown();
    }

    protected function manager(): PartitionManager
    {
        return new PartitionManager($this->repository, $this->events, $this->config);
    }

    protected function builder(string $table, ?string $connection = null): PartitionBuilder
    {
        return $this->manager()->table($table)->connection($connection);
    }

    protected function connection(?string $connection = null): ConnectionInterface
    {
        return $this->capsule->getConnection($connection);
    }

    protected function createTable(string $table, string $type = 'datetime', ?string $connection = null): void
    {
        $this->track($table, $connection);

        $schema = $this->capsule->getConnection($connection)->getSchemaBuilder();
        $schema->dropIfExists($table);
        $schema->create($table, function (Blueprint $blueprint) use ($type): void {
            $blueprint->unsignedBigInteger('id');
            $type === 'timestamp' ? $blueprint->timestamp('created_at') : $blueprint->dateTime('created_at');
            $blueprint->primary(['id', 'created_at']);
        });
    }

    protected function track(string $table, ?string $connection = null): void
    {
        $this->created[] = [$connection, $table];
    }

    /**
     * @return list<string>
     */
    protected function partitionsOf(string $table, ?string $connection = null): array
    {
        return array_values($this->builder($table, $connection)
            ->existing()
            ->map(fn (PartitionInfo $partition) => $partition->name)
            ->all());
    }

    protected function rowsIn(string $table, string $partition): int
    {
        return (int) $this->connection()->scalar(sprintf('select count(*) from `%s` partition (`%s`)', $table, $partition));
    }

    private function env(string $key, string $default): string
    {
        $value = getenv($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}

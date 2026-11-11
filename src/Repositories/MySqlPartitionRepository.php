<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Repositories;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Collection;
use Yurkovskiy\Partitions\Contracts\PartitionRepository;
use Yurkovskiy\Partitions\Exceptions\UnsupportedDriverException;
use Yurkovskiy\Partitions\Schema\PartitionInfo;
use Yurkovskiy\Partitions\Schema\TableInfo;
use Yurkovskiy\Partitions\Support\Identifier;

class MySqlPartitionRepository implements PartitionRepository
{
    /** @var array<int, string> */
    public const SUPPORTED_DRIVERS = ['mysql', 'mariadb'];

    public function __construct(protected ConnectionResolverInterface $resolver) {}

    public function partitions(?string $connection, string $table): Collection
    {
        $db = $this->connection($connection);

        $rows = $this->informationSchema($db, 'PARTITIONS')
            ->select([
                'PARTITION_NAME',
                'PARTITION_DESCRIPTION',
                'PARTITION_ORDINAL_POSITION',
                'PARTITION_METHOD',
                'PARTITION_EXPRESSION',
                'TABLE_ROWS',
                'DATA_LENGTH',
                'INDEX_LENGTH',
            ])
            ->where('TABLE_SCHEMA', Identifier::schemaName($table) ?? $db->getDatabaseName())
            ->where('TABLE_NAME', Identifier::tableName($table))
            ->whereNotNull('PARTITION_NAME')
            ->orderBy('PARTITION_ORDINAL_POSITION')
            ->orderBy('SUBPARTITION_ORDINAL_POSITION')
            ->get();

        return $this->foldSubpartitions($rows);
    }

    public function tableInfo(?string $connection, string $table): ?TableInfo
    {
        $db = $this->connection($connection);

        $row = $this->informationSchema($db, 'TABLES')
            ->select(['TABLE_NAME', 'TABLE_ROWS', 'DATA_LENGTH', 'INDEX_LENGTH', 'ENGINE'])
            ->where('TABLE_SCHEMA', Identifier::schemaName($table) ?? $db->getDatabaseName())
            ->where('TABLE_NAME', Identifier::tableName($table))
            ->first();

        return $row === null ? null : TableInfo::fromRow($row);
    }

    public function tablePrefix(?string $connection): string
    {
        $db = $this->connection($connection);

        return $db instanceof Connection ? $db->getTablePrefix() : '';
    }

    public function connectionTimezone(?string $connection): ?string
    {
        $db = $this->connection($connection);

        $timezone = method_exists($db, 'getConfig') ? $db->getConfig('timezone') : null;

        return is_string($timezone) && $timezone !== '' ? $timezone : null;
    }

    public function unixTimestamp(?string $connection, string $datetime): ?int
    {
        $row = $this->connection($connection)->selectOne('select unix_timestamp(?) as epoch', [$datetime]);

        $epoch = is_object($row) ? ($row->epoch ?? null) : (is_array($row) ? ($row['epoch'] ?? null) : null);

        return is_numeric($epoch) ? (int) $epoch : null;
    }

    public function statement(?string $connection, string $sql): bool
    {
        return $this->connection($connection)->statement($sql);
    }

    /**
     * @param  literal-string  $view
     */
    protected function informationSchema(ConnectionInterface $db, string $view): Builder
    {
        return (new Builder($db))->from(new Expression('`information_schema`.`'.$view.'`'));
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @return Collection<int, PartitionInfo>
     */
    protected function foldSubpartitions(Collection $rows): Collection
    {
        /** @var Collection<string, PartitionInfo> $folded */
        $folded = new Collection;

        foreach ($rows as $row) {
            $partition = PartitionInfo::fromRow($row);
            $seen = $folded->get($partition->name);

            $folded->put($partition->name, $seen instanceof PartitionInfo
                ? $seen->withSubpartition($partition)
                : $partition);
        }

        return $folded->values();
    }

    /**
     * @throws UnsupportedDriverException
     */
    protected function connection(?string $connection): ConnectionInterface
    {
        $db = $this->resolver->connection($connection);
        $driver = $this->driverName($db);

        if (! in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            throw new UnsupportedDriverException($connection, $driver);
        }

        return $db;
    }

    protected function driverName(ConnectionInterface $db): string
    {
        $driver = method_exists($db, 'getDriverName') ? $db->getDriverName() : null;

        if (is_string($driver) && $driver !== '') {
            return strtolower($driver);
        }

        return $db instanceof MySqlConnection ? 'mysql' : $db::class;
    }
}

<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Support;

use Illuminate\Support\Collection;
use RuntimeException;
use Yurkovskiy\Partitions\Contracts\PartitionRepository;
use Yurkovskiy\Partitions\Schema\PartitionInfo;
use Yurkovskiy\Partitions\Schema\TableInfo;

class FakePartitionRepository implements PartitionRepository
{
    /** @var array<int, string> */
    public array $executed = [];

    public int $reads = 0;

    /**
     * @var array<int, array{0: string|null, 1: string}>
     */
    public array $requested = [];

    public int $tableReads = 0;

    public string $tablePrefix = '';

    /** @var array<string, string> */
    public array $tablePrefixes = [];

    public ?string $connectionTimezone = null;

    public ?int $unixTimestamp = null;

    /** @var array<int, string> */
    public array $timestampQueries = [];

    public ?int $failingStatement = null;

    /**
     * @param  array<int, PartitionInfo>  $partitions
     */
    public function __construct(public array $partitions = [], public ?TableInfo $table = null) {}

    /**
     * @param  array<string, string|null>  $partitions
     * @param  string  $method  PARTITION_METHOD as information_schema reports it
     * @param  string|null  $expression  PARTITION_EXPRESSION as information_schema reports it
     */
    public static function withPartitions(array $partitions, string $method = 'RANGE', ?string $expression = null): static
    {
        $ordinal = 0;

        return new static(collect($partitions)
            ->map(fn (?string $description, string $name) => new PartitionInfo(
                name: $name,
                description: $description,
                ordinal: ++$ordinal,
                rows: 10,
                dataLength: 1024,
                indexLength: 512,
                method: $method,
                expression: $expression,
            ))
            ->values()
            ->all());
    }

    public function partitions(?string $connection, string $table): Collection
    {
        $this->reads++;
        $this->requested[] = [$connection, $table];

        return collect($this->partitions);
    }

    public function tableInfo(?string $connection, string $table): ?TableInfo
    {
        $this->tableReads++;

        return $this->table;
    }

    public function tablePrefix(?string $connection): string
    {
        return $this->tablePrefixes[$connection ?? ''] ?? $this->tablePrefix;
    }

    public function connectionTimezone(?string $connection): ?string
    {
        return $this->connectionTimezone;
    }

    public function unixTimestamp(?string $connection, string $datetime): ?int
    {
        $this->timestampQueries[] = $datetime;

        return $this->unixTimestamp;
    }

    public function statement(?string $connection, string $sql): bool
    {
        if ($this->failingStatement === count($this->executed) + 1) {
            $this->failingStatement = null;

            throw new RuntimeException('Lock wait timeout exceeded; try restarting transaction');
        }

        $this->executed[] = $sql;

        return true;
    }
}

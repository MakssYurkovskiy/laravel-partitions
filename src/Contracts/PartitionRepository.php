<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Contracts;

use Illuminate\Support\Collection;
use Yurkovskiy\Partitions\Schema\PartitionInfo;
use Yurkovskiy\Partitions\Schema\TableInfo;

interface PartitionRepository
{
    /**
     * @return Collection<int, PartitionInfo>
     */
    public function partitions(?string $connection, string $table): Collection;

    public function tableInfo(?string $connection, string $table): ?TableInfo;

    public function tablePrefix(?string $connection): string;

    public function connectionTimezone(?string $connection): ?string;

    public function unixTimestamp(?string $connection, string $datetime): ?int;

    public function statement(?string $connection, string $sql): bool;
}

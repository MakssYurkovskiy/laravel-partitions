<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Schema;

use Illuminate\Support\Collection;

final class PartitionPlan
{
    /**
     * @param  Collection<int, PendingPartition>  $pending
     * @param  Collection<int, PendingPartition>  $deferred
     */
    public function __construct(
        public readonly Collection $pending,
        public readonly Collection $deferred,
    ) {}
}

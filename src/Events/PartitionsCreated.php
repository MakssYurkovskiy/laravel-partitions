<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Events;

class PartitionsCreated
{
    /**
     * @param  array<int, string>  $partitions
     * @param  array<int, string>  $statements
     */
    public function __construct(
        public readonly string $table,
        public readonly ?string $connection,
        public readonly array $partitions,
        public readonly array $statements,
    ) {}
}

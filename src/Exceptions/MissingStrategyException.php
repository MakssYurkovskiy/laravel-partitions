<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Exceptions;

class MissingStrategyException extends PartitionException
{
    public function __construct(string $table)
    {
        parent::__construct(sprintf('No partition strategy set for table [%s].', $table));
    }
}

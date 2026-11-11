<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Exceptions;

use Yurkovskiy\Partitions\Contracts\PartitionStrategy;
use Yurkovskiy\Partitions\Schema\PartitionPeriod;

class UnknownStrategyException extends PartitionException
{
    public function __construct(string $strategy, ?string $table = null)
    {
        parent::__construct(sprintf(
            'Unknown partition strategy [%s]%s. Use %s or a class implementing %s.',
            $strategy,
            $table === null ? '' : sprintf(' for table [%s]', $table),
            implode(', ', PartitionPeriod::values()),
            PartitionStrategy::class
        ));
    }

    public static function forTable(mixed $strategy, string $table): self
    {
        return new self(
            is_scalar($strategy) ? var_export($strategy, true) : get_debug_type($strategy),
            $table
        );
    }
}

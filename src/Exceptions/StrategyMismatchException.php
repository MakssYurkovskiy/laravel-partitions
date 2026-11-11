<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Exceptions;

class StrategyMismatchException extends PartitionException
{
    public static function forPeriod(string $table, string $partition, string $found, string $expected): self
    {
        return new self(sprintf(
            'Table [%s] holds %s partitions — [%s] is one of them — but the builder is configured for %s ones. '
            .'Set the strategy to %s, or repartition the table before managing it.',
            $table,
            $found,
            $partition,
            $expected,
            $found
        ));
    }

    public static function forBoundary(string $table, string $partition, string $found, string $expected, string $strategy): self
    {
        return new self(sprintf(
            'Partition [%s] of table [%s] ends at %s, while a %s partition of that name ends at %s. '
            .'The table was partitioned by another period than the builder is configured for.',
            $partition,
            $table,
            $found,
            $strategy,
            $expected
        ));
    }
}

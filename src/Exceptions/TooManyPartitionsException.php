<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Exceptions;

class TooManyPartitionsException extends PartitionException
{
    public static function forRange(string $table, string $from, string $to, int $limit): self
    {
        return new self(sprintf(
            'The range for table [%s], %s to %s, covers more than %d periods, and %d partitions is all MySQL '
            .'allows in one table. Narrow the range, or partition by a longer period.',
            $table,
            $from,
            $to,
            $limit,
            $limit
        ));
    }

    public static function forMaxPartition(string $table, string $from, string $to, int $limit): self
    {
        return new self(sprintf(
            'The range for table [%s], %s to %s, covers %d periods, and with the MAXVALUE partition that is one '
            .'more than the %d partitions MySQL allows in one table. Narrow the range, partition by a longer '
            .'period, or leave the MAXVALUE partition out.',
            $table,
            $from,
            $to,
            $limit,
            $limit
        ));
    }

    public static function forTable(string $table, int $existing, int $adding, int $limit): self
    {
        return new self(sprintf(
            'Table [%s] has %d partitions, and the %d to add would make %d — MySQL allows %d in one table. '
            .'Nothing was added. Drop the partitions that are no longer needed, with partitions:drop or '
            .'dropBefore(), or partition by a longer period.',
            $table,
            $existing,
            $adding,
            $existing + $adding,
            $limit
        ));
    }

    public static function forLookAhead(string $table, int $future, int $limit): self
    {
        return new self(sprintf(
            'future(%d) for table [%s] looks further ahead than the %d partitions MySQL allows in one table. '
            .'Lower the look-ahead.',
            $future,
            $table,
            $limit
        ));
    }
}

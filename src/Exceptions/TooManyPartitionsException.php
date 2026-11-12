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

<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Exceptions;

class InvalidRangeException extends PartitionException
{
    public static function forDates(string $table, string $from, string $to): self
    {
        return new self(sprintf(
            'The range for table [%s] starts at %s and ends at %s, before it starts, so it covers no period. '
            .'Swap the two dates, or correct the one that is off.',
            $table,
            $from,
            $to
        ));
    }

    public static function forLookAhead(string $table, string $from, string $to, int $future): self
    {
        return new self(sprintf(
            'The range for table [%s] starts at %s, but with no end date it stops at %s — future(%d), '
            .'counted from today — so it covers no period. Give the range an end date past its start, '
            .'or raise the look-ahead.',
            $table,
            $from,
            $to,
            $future
        ));
    }
}

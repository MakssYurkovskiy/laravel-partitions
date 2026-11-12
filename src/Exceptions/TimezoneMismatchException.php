<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Exceptions;

class TimezoneMismatchException extends PartitionException
{
    public function __construct(string $table, string $timezone, string $datetime, int $minutes)
    {
        parent::__construct(sprintf(
            'UNIX_TIMESTAMP boundaries for table [%s] are computed in [%s], but the connection reads [%s] '
            .'%+d minutes away from it, so boundaries would be off by that much. Point partitions.timezone '
            .'and database.connections.*.timezone at the same zone.',
            $table,
            $timezone,
            $datetime,
            $minutes
        ));
    }
}

<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Exceptions;

class TimezoneMismatchException extends PartitionException
{
    public function __construct(string $table, string $timezone, int $minutes)
    {
        parent::__construct(sprintf(
            'UNIX_TIMESTAMP boundaries for table [%s] are computed in [%s], but the connection reads datetimes '
            .'%+d minutes away from it, so every boundary would be off by that much. Point partitions.timezone '
            .'and database.connections.*.timezone at the same zone.',
            $table,
            $timezone,
            $minutes
        ));
    }
}

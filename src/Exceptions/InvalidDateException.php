<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Exceptions;

use Throwable;

class InvalidDateException extends PartitionException
{
    public function __construct(string $date, ?Throwable $previous = null)
    {
        parent::__construct(sprintf(
            'Invalid date [%s]. Use a date PHP can read, e.g. "2025-06-13", or pass a DateTimeInterface.',
            $date
        ), 0, $previous);
    }
}

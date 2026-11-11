<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Exceptions;

class InvalidTimezoneException extends PartitionException
{
    public function __construct(string $timezone)
    {
        parent::__construct(sprintf(
            'Invalid timezone [%s]. Use an identifier PHP knows, e.g. "UTC", "Europe/Kyiv" or "+03:00".',
            $timezone
        ));
    }
}

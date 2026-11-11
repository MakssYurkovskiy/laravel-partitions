<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Exceptions;

class MissingTableException extends PartitionException
{
    public function __construct()
    {
        parent::__construct('No table set for the partition builder.');
    }
}

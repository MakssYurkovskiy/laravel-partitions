<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Exceptions;

class MissingDefinitionException extends PartitionException
{
    public function __construct()
    {
        parent::__construct('The partitionBy command carries no partition definition.');
    }
}

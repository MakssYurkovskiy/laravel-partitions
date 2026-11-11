<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Exceptions;

class UnknownTableException extends PartitionException
{
    public function __construct(string $key)
    {
        parent::__construct(sprintf('Table [%s] is not configured in config/partitions.php.', $key));
    }
}

<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Exceptions;

use Illuminate\Support\Arr;
use Yurkovskiy\Partitions\Schema\PartitionExpression;

class UnknownExpressionException extends PartitionException
{
    public function __construct(string $expression)
    {
        parent::__construct(sprintf(
            'Unknown partition expression [%s]. Use %s.',
            $expression,
            Arr::join(PartitionExpression::values(), ', ', ' or ')
        ));
    }
}

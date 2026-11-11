<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Exceptions;

use Yurkovskiy\Partitions\Contracts\PartitionStrategy;

class InvalidIdentifierException extends PartitionException
{
    public function __construct(string $identifier, ?PartitionStrategy $strategy = null)
    {
        parent::__construct($strategy === null
            ? sprintf(
                'Invalid SQL identifier [%s]: only letters, digits, underscores and $ are allowed.',
                $identifier
            )
            : sprintf(
                'Partition strategy [%s] names a partition [%s]: a partition name may hold only letters, digits, '
                .'underscores and $, so the prefix and format() of the strategy may not produce anything else.',
                $strategy::class,
                $identifier
            ));
    }
}

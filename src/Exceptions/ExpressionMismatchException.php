<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Exceptions;

class ExpressionMismatchException extends PartitionException
{
    public function __construct(string $table, string $found, string $expected)
    {
        parent::__construct(sprintf(
            'Table [%s] is partitioned by [%s] but the builder is configured for [%s]. '
            .'Adjust the strategy, the column or the expression (to_days, unix_timestamp, columns) to match the table.',
            $table,
            $found,
            $expected
        ));
    }
}

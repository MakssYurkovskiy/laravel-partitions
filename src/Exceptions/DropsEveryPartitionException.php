<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Exceptions;

class DropsEveryPartitionException extends PartitionException
{
    public function __construct(string $table, int $partitions)
    {
        parent::__construct(sprintf(
            'The %s selected %s all that table [%s] has, and MySQL keeps at least one partition in a partitioned '
            .'table, so the last DROP PARTITION would fail. Narrow the selection, call removePartitioning() to '
            .'keep the rows in a table without partitions, or drop the table itself.',
            $partitions === 1 ? 'partition' : $partitions.' partitions',
            $partitions === 1 ? 'is' : 'are',
            $table
        ));
    }
}

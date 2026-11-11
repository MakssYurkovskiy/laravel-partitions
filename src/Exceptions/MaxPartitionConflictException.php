<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Exceptions;

class MaxPartitionConflictException extends PartitionException
{
    public function __construct(string $table, string $max)
    {
        parent::__construct(sprintf(
            'Table [%s] has the MAXVALUE partition [%s], but the builder is configured with '
            .'withoutMaxPartition(), and sync() cannot honour both: every new range is carved out of '
            .'[%s], which puts it back. Call withMaxPartition() to keep it, or rebuild the layout with '
            .'removePartitioning() followed by create() to get rid of it.',
            $table,
            $max,
            $max
        ));
    }
}

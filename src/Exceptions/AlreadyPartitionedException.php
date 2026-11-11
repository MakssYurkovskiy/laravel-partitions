<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Exceptions;

class AlreadyPartitionedException extends PartitionException
{
    public static function forCreate(string $table): self
    {
        return new self(sprintf('Table [%s] is already partitioned; use sync() to add partitions.', $table));
    }

    public static function forMigration(string $table): self
    {
        return new self(sprintf(
            'Table [%s] is already partitioned, so declaring partitionByRange() on it would replace the whole '
            .'layout and rebuild the table. Call ->replacePartitioning() to confirm that, or use sync() / '
            .'partitions:add to only add the missing partitions.',
            $table
        ));
    }
}

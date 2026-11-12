<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Exceptions;

class NotPartitionedException extends PartitionException
{
    public static function forRemoval(string $table): self
    {
        return new self(sprintf(
            'Table [%s] is not partitioned, so removePartitioning() has nothing to remove. '
            .'Guard the call with isPartitioned(), or call create() to partition the table first.',
            $table
        ));
    }

    public static function forSync(string $table): self
    {
        return new self(sprintf(
            'Table [%s] is not partitioned, so sync() has no partitions to add to. Call create() — or run '
            .'partitions:add with --create — to partition it first; that rebuilds the whole table.',
            $table
        ));
    }

    public static function forMigration(string $table): self
    {
        return new self(sprintf(
            'Table [%s] is not partitioned, so removePartitioning() has nothing to remove. Drop the call, or '
            .'guard it with Partition::table(%s)->isPartitioned().',
            $table,
            var_export($table, true)
        ));
    }
}

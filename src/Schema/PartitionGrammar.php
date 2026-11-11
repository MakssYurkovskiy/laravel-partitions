<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Schema;

use Yurkovskiy\Partitions\Support\Identifier;

class PartitionGrammar
{
    public function __construct(protected ?string $tablespace = null) {}

    public function withTablespace(?string $tablespace): static
    {
        $clone = clone $this;
        $clone->tablespace = $tablespace;

        return $clone;
    }

    public function partition(string $name, string $boundary): string
    {
        return sprintf(
            'PARTITION %s VALUES LESS THAN (%s)%s',
            Identifier::quote($name),
            $boundary,
            $this->tablespaceClause()
        );
    }

    public function maxPartition(string $name): string
    {
        return sprintf(
            'PARTITION %s VALUES LESS THAN MAXVALUE%s',
            Identifier::quote($name),
            $this->tablespaceClause()
        );
    }

    /**
     * @param  iterable<int, string>  $definitions
     */
    public function partitionByClause(string $column, PartitionExpression $expression, iterable $definitions): string
    {
        return sprintf(
            "PARTITION BY %s (\n%s\n)",
            $expression->rangeClause($column),
            $this->indent($definitions)
        );
    }

    /**
     * @param  iterable<int, string>  $definitions
     */
    public function createPartitioning(string $table, string $column, PartitionExpression $expression, iterable $definitions): string
    {
        return sprintf(
            "ALTER TABLE %s\n%s",
            Identifier::quote($table),
            $this->partitionByClause($column, $expression, $definitions)
        );
    }

    /**
     * @param  iterable<int, string>  $definitions
     */
    public function addPartitions(string $table, iterable $definitions): string
    {
        return sprintf(
            "ALTER TABLE %s\nADD PARTITION (\n%s\n)",
            Identifier::quote($table),
            $this->indent($definitions)
        );
    }

    /**
     * @param  iterable<int, string>  $definitions
     */
    public function reorganizeMaxPartition(string $table, string $maxPartition, iterable $definitions): string
    {
        $definitions = collect($definitions)->push($this->maxPartition($maxPartition));

        return sprintf(
            "ALTER TABLE %s\nREORGANIZE PARTITION %s INTO (\n%s\n)",
            Identifier::quote($table),
            Identifier::quote($maxPartition),
            $this->indent($definitions)
        );
    }

    /**
     * @param  iterable<int, string>  $names
     */
    public function dropPartitions(string $table, iterable $names): string
    {
        return sprintf(
            'ALTER TABLE %s DROP PARTITION %s',
            Identifier::quote($table),
            $this->quoteNames($names)
        );
    }

    /**
     * @param  iterable<int, string>  $names
     */
    public function truncatePartitions(string $table, iterable $names): string
    {
        return sprintf(
            'ALTER TABLE %s TRUNCATE PARTITION %s',
            Identifier::quote($table),
            $this->quoteNames($names)
        );
    }

    public function removePartitioning(string $table): string
    {
        return sprintf('ALTER TABLE %s REMOVE PARTITIONING', Identifier::quote($table));
    }

    protected function tablespaceClause(): string
    {
        return $this->tablespace === null || $this->tablespace === ''
            ? ''
            : ' TABLESPACE = '.Identifier::validate($this->tablespace);
    }

    /**
     * @param  iterable<int, string>  $names
     */
    protected function quoteNames(iterable $names): string
    {
        return collect($names)
            ->map(fn (string $name) => Identifier::quote($name))
            ->implode(', ');
    }

    /**
     * @param  iterable<int, string>  $lines
     */
    protected function indent(iterable $lines): string
    {
        return collect($lines)
            ->map(fn (string $line) => '    '.$line)
            ->implode(",\n");
    }
}

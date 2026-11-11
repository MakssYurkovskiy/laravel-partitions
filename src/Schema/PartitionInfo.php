<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Schema;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, mixed>
 */
final class PartitionInfo implements Arrayable
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly int $ordinal = 0,
        public readonly int $rows = 0,
        public readonly int $dataLength = 0,
        public readonly int $indexLength = 0,
        public readonly ?string $method = null,
        public readonly ?string $expression = null,
    ) {}

    /**
     * @param  object|array<string, mixed>  $row
     */
    public static function fromRow(object|array $row): self
    {
        $row = (array) $row;
        $row = array_change_key_case($row);

        return new self(
            name: (string) ($row['partition_name'] ?? $row['name'] ?? ''),
            description: isset($row['partition_description']) ? (string) $row['partition_description'] : ($row['description'] ?? null),
            ordinal: (int) ($row['partition_ordinal_position'] ?? $row['ordinal'] ?? 0),
            rows: (int) ($row['table_rows'] ?? $row['rows'] ?? 0),
            dataLength: (int) ($row['data_length'] ?? 0),
            indexLength: (int) ($row['index_length'] ?? 0),
            method: isset($row['partition_method']) ? (string) $row['partition_method'] : ($row['method'] ?? null),
            expression: isset($row['partition_expression']) ? (string) $row['partition_expression'] : ($row['expression'] ?? null),
        );
    }

    public function withSubpartition(self $other): self
    {
        return new self(
            name: $this->name,
            description: $this->description ?? $other->description,
            ordinal: $this->ordinal,
            rows: $this->rows + $other->rows,
            dataLength: $this->dataLength + $other->dataLength,
            indexLength: $this->indexLength + $other->indexLength,
            method: $this->method,
            expression: $this->expression,
        );
    }

    public function isMax(): bool
    {
        return strcasecmp(trim((string) $this->description), 'MAXVALUE') === 0;
    }

    public function totalLength(): int
    {
        return $this->dataLength + $this->indexLength;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'ordinal' => $this->ordinal,
            'rows' => $this->rows,
            'data_length' => $this->dataLength,
            'index_length' => $this->indexLength,
            'method' => $this->method,
            'expression' => $this->expression,
        ];
    }
}

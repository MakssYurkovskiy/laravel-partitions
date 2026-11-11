<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Schema;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, mixed>
 */
final class TableInfo implements Arrayable
{
    public function __construct(
        public readonly string $name,
        public readonly int $rows = 0,
        public readonly int $dataLength = 0,
        public readonly int $indexLength = 0,
        public readonly ?string $engine = null,
    ) {}

    /**
     * @param  object|array<string, mixed>  $row
     */
    public static function fromRow(object|array $row): self
    {
        $row = (array) $row;
        $row = array_change_key_case($row);

        return new self(
            name: (string) ($row['table_name'] ?? $row['name'] ?? ''),
            rows: (int) ($row['table_rows'] ?? $row['rows'] ?? 0),
            dataLength: (int) ($row['data_length'] ?? 0),
            indexLength: (int) ($row['index_length'] ?? 0),
            engine: isset($row['engine']) ? (string) $row['engine'] : null,
        );
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
            'rows' => $this->rows,
            'data_length' => $this->dataLength,
            'index_length' => $this->indexLength,
            'engine' => $this->engine,
        ];
    }
}

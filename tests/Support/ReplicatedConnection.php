<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Support;

use Illuminate\Database\MySqlConnection;
use RuntimeException;

class ReplicatedConnection extends MySqlConnection
{
    /** @var array<string, string> */
    public array $primary;

    /** @var array<int, string> */
    public array $replicaReads = [];

    /** @var array<int, string> */
    public array $primaryReads = [];

    /** @var array<int, string> */
    public array $statements = [];

    public ?int $failingStatement = null;

    /**
     * @param  array<string, string>  $replica
     */
    public function __construct(public array $replica = [])
    {
        parent::__construct(fn () => null, 'testdb', '', ['driver' => 'mysql']);

        $this->primary = $replica;
    }

    /**
     * @param  array<array-key, mixed>  $fetchUsing
     */
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        if ($useReadPdo) {
            $this->replicaReads[] = $query;
        } else {
            $this->primaryReads[] = $query;
        }

        if (! str_contains($query, '`information_schema`.`PARTITIONS`')) {
            return [];
        }

        return $this->rows($useReadPdo ? $this->replica : $this->primary);
    }

    public function statement($query, $bindings = [])
    {
        if ($this->failingStatement === count($this->statements) + 1) {
            $this->failingStatement = null;

            throw new RuntimeException('Lock wait timeout exceeded; try restarting transaction');
        }

        $this->statements[] = $query;

        preg_match_all('/PARTITION `([^`]+)` VALUES LESS THAN (?:\((.+)\)|(MAXVALUE))/', $query, $definitions, PREG_SET_ORDER);

        foreach ($definitions as $definition) {
            unset($this->primary[$definition[1]]);

            $this->primary[$definition[1]] = $definition[3] ?? $definition[2];
        }

        return true;
    }

    /**
     * @param  array<string, string>  $partitions
     * @return array<int, object>
     */
    protected function rows(array $partitions): array
    {
        $rows = [];

        foreach ($partitions as $name => $description) {
            $rows[] = (object) [
                'PARTITION_NAME' => $name,
                'PARTITION_DESCRIPTION' => $description,
                'PARTITION_ORDINAL_POSITION' => count($rows) + 1,
                'PARTITION_METHOD' => 'RANGE',
                'PARTITION_EXPRESSION' => 'to_days(`created_at`)',
                'TABLE_ROWS' => 0,
                'DATA_LENGTH' => 0,
                'INDEX_LENGTH' => 0,
            ];
        }

        return $rows;
    }
}

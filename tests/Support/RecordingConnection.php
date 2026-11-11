<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Support;

use Illuminate\Database\MySqlConnection;

class RecordingConnection extends MySqlConnection
{
    /** @var array<int, array{0: string, 1: array<int, mixed>}> */
    public array $queries = [];

    /** @var array<int, string> */
    public array $statements = [];

    /** @var array<int, object> */
    public array $rows = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(string $database = 'testdb', array $config = ['driver' => 'mysql'])
    {
        parent::__construct(fn () => null, $database, '', $config);
    }

    /**
     * @param  array<int, object>  $rows
     */
    public function returning(array $rows): self
    {
        $this->rows = $rows;

        return $this;
    }

    /**
     * @param  array<array-key, mixed>  $fetchUsing
     */
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        $this->queries[] = [$query, $bindings];

        return $this->rows;
    }

    public function statement($query, $bindings = [])
    {
        $this->statements[] = $query;

        return true;
    }
}

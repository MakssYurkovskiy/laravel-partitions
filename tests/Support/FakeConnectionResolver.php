<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;

class FakeConnectionResolver implements ConnectionResolverInterface
{
    /** @var array<int, string|null> */
    public array $requested = [];

    /**
     * @param  array<string, ConnectionInterface>  $connections
     */
    public function __construct(protected array $connections = [], protected string $default = 'mysql') {}

    public function connection($name = null)
    {
        $this->requested[] = $name;

        return $this->connections[$name ?? $this->default];
    }

    public function getDefaultConnection()
    {
        return $this->default;
    }

    public function setDefaultConnection($name)
    {
        $this->default = $name;
    }
}

<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Exceptions;

class UnsupportedDriverException extends PartitionException
{
    public function __construct(?string $connection, string $driver)
    {
        parent::__construct(sprintf(
            'Partitions are read from information_schema.PARTITIONS, which only MySQL and MariaDB provide, '
            .'but connection [%s] uses the [%s] driver. Point the builder at a MySQL connection with '
            .'connection(\'mysql\'), or guard the call with a driver check.',
            $connection ?? 'default',
            $driver
        ));
    }
}

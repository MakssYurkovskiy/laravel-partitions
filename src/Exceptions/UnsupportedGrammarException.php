<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Exceptions;

class UnsupportedGrammarException extends PartitionException
{
    public function __construct(string $grammar)
    {
        parent::__construct(sprintf(
            'Partitioning is only supported on MySQL and MariaDB, but the connection uses [%s]. '
            .'Guard the migration with a driver check, or move the partitioning to a MySQL connection.',
            $grammar
        ));
    }
}

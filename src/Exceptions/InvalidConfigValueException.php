<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Exceptions;

class InvalidConfigValueException extends PartitionException
{
    public function __construct(string $setting, mixed $value, ?string $table = null, bool $nullable = false)
    {
        parent::__construct(sprintf(
            'Setting [%s] %s config/partitions.php has to be a whole number, zero or above%s; %s given.',
            $setting,
            $table === null ? 'in' : sprintf('of table [%s] in', $table),
            $nullable ? ', or null' : '',
            is_scalar($value) ? var_export($value, true) : get_debug_type($value)
        ));
    }
}

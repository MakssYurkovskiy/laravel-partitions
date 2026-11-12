<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Exceptions;

class InvalidTableConfigException extends PartitionException
{
    public function __construct(string $key, mixed $settings)
    {
        parent::__construct(sprintf(
            'Table [%s] in config/partitions.php has to be an array of settings, %s given.',
            $key,
            get_debug_type($settings)
        ));
    }
}

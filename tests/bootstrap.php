<?php

declare(strict_types=1);

$autoloader = __DIR__.'/../vendor/autoload.php';

if (! file_exists($autoloader)) {
    fwrite(STDERR, "Dependencies are not installed. Run \"composer install\" first.\n");

    exit(1);
}

require $autoloader;

require __DIR__.'/Support/period_named_strategy.php';

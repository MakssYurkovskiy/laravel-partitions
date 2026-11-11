<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Support;

use Psr\Log\AbstractLogger;

class FakeLogger extends AbstractLogger
{
    /** @var array<int, array{level: mixed, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    /**
     * @param  array<array-key, mixed>  $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}

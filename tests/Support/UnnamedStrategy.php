<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Yurkovskiy\Partitions\Strategies\AbstractStrategy;

class UnnamedStrategy extends AbstractStrategy
{
    public function start(DateTimeInterface $date): CarbonImmutable
    {
        return CarbonImmutable::instance($date)->startOfDay();
    }

    public function next(DateTimeInterface $date): CarbonImmutable
    {
        return $this->start($date)->addDay();
    }

    protected function format(): string
    {
        return 'Ymd';
    }
}

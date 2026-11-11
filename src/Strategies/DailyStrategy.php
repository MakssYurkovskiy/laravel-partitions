<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Strategies;

use Carbon\CarbonImmutable;
use DateTimeInterface;

class DailyStrategy extends AbstractStrategy
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

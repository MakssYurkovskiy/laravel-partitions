<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Strategies;

use Carbon\CarbonImmutable;
use DateTimeInterface;

class MonthlyStrategy extends AbstractStrategy
{
    public function start(DateTimeInterface $date): CarbonImmutable
    {
        return CarbonImmutable::instance($date)->startOfMonth();
    }

    public function next(DateTimeInterface $date): CarbonImmutable
    {
        return $this->start($this->start($date)->addMonth());
    }

    protected function format(): string
    {
        return 'Ym';
    }
}

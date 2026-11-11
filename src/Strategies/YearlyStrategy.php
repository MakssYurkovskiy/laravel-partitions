<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Strategies;

use Carbon\CarbonImmutable;
use DateTimeInterface;

class YearlyStrategy extends AbstractStrategy
{
    public function start(DateTimeInterface $date): CarbonImmutable
    {
        return CarbonImmutable::instance($date)->startOfYear();
    }

    public function next(DateTimeInterface $date): CarbonImmutable
    {
        return $this->start($date)->addYear();
    }

    protected function format(): string
    {
        return 'Y';
    }
}

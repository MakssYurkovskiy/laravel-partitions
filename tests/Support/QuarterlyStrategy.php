<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Yurkovskiy\Partitions\Strategies\AbstractStrategy;

class QuarterlyStrategy extends AbstractStrategy
{
    public function name(): string
    {
        return 'quarterly';
    }

    public function start(DateTimeInterface $date): CarbonImmutable
    {
        return CarbonImmutable::instance($date)->firstOfQuarter()->startOfDay();
    }

    public function next(DateTimeInterface $date): CarbonImmutable
    {
        return $this->start($date)->addQuarter();
    }

    protected function format(): string
    {
        return 'Ym';
    }
}

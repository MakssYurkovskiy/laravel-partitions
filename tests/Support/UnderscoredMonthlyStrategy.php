<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Yurkovskiy\Partitions\Strategies\AbstractStrategy;

class UnderscoredMonthlyStrategy extends AbstractStrategy
{
    public function start(DateTimeInterface $date): CarbonImmutable
    {
        return CarbonImmutable::instance($date)->startOfMonth();
    }

    public function next(DateTimeInterface $date): CarbonImmutable
    {
        return $this->start($date)->addMonth();
    }

    protected function format(): string
    {
        return 'Y_m';
    }
}

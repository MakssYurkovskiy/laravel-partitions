<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Strategies;

use Carbon\CarbonImmutable;
use DateTimeInterface;

class WeeklyStrategy extends AbstractStrategy
{
    public function start(DateTimeInterface $date): CarbonImmutable
    {
        return CarbonImmutable::instance($date)->startOfWeek(CarbonImmutable::MONDAY);
    }

    public function next(DateTimeInterface $date): CarbonImmutable
    {
        return $this->start($this->start($date)->addWeek());
    }

    protected function format(): string
    {
        return 'oW';
    }

    protected function decode(string $stamp): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}(0[1-9]|[1-4]\d|5[0-3])$/', $stamp) !== 1) {
            return null;
        }

        $year = (int) substr($stamp, 0, 4);
        $week = (int) substr($stamp, 4);

        $date = CarbonImmutable::create($year, 1, 4);

        if (! $date instanceof CarbonImmutable) {
            return null;
        }

        $start = $this->start($date->setISODate($year, $week));

        return $start->format($this->format()) === $stamp ? $start : null;
    }
}

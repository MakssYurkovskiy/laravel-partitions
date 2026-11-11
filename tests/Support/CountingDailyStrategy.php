<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Support;

use DateTimeInterface;
use Yurkovskiy\Partitions\Strategies\DailyStrategy;

class CountingDailyStrategy extends DailyStrategy
{
    public static int $plans = 0;

    public function periods(DateTimeInterface $from, DateTimeInterface $to): iterable
    {
        self::$plans++;

        return parent::periods($from, $to);
    }
}

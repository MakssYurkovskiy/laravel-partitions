<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Support;

use DateTimeInterface;
use DateTimeZone;

final class MySqlDate
{
    private const EPOCH_IN_DAYS = 719528;

    public static function toDays(DateTimeInterface $date): int
    {
        $utc = (new \DateTimeImmutable($date->format('Y-m-d').' 00:00:00', new DateTimeZone('UTC')))
            ->getTimestamp();

        return intdiv($utc, 86400) + self::EPOCH_IN_DAYS;
    }

    public static function fromDays(int $days): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@'.(($days - self::EPOCH_IN_DAYS) * 86400)))
            ->setTimezone(new DateTimeZone('UTC'));
    }
}

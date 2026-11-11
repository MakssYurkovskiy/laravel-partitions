<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Schema;

use Yurkovskiy\Partitions\Contracts\PartitionStrategy;
use Yurkovskiy\Partitions\Strategies\DailyStrategy;
use Yurkovskiy\Partitions\Strategies\MonthlyStrategy;
use Yurkovskiy\Partitions\Strategies\WeeklyStrategy;
use Yurkovskiy\Partitions\Strategies\YearlyStrategy;
use Yurkovskiy\Partitions\Support\Keyword;

enum PartitionPeriod: string
{
    case Daily = 'daily';

    case Weekly = 'weekly';

    case Monthly = 'monthly';

    case Yearly = 'yearly';

    public static function tryMake(self|string $period): ?self
    {
        if ($period instanceof self) {
            return $period;
        }

        $needle = Keyword::normalize($period);

        foreach (self::cases() as $case) {
            if ($needle === $case->value || in_array($needle, $case->aliases(), true)) {
                return $case;
            }
        }

        return null;
    }

    public static function tryFromStrategy(PartitionStrategy $strategy): ?self
    {
        foreach (self::cases() as $case) {
            if (is_a($strategy, $case->strategyClass())) {
                return $case;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    public function unit(): string
    {
        return match ($this) {
            self::Daily => 'day',
            self::Weekly => 'week',
            self::Monthly => 'month',
            self::Yearly => 'year',
        };
    }

    /**
     * @return list<string>
     */
    public function aliases(): array
    {
        return [$this->unit(), $this->unit()[0]];
    }

    public function strategy(): PartitionStrategy
    {
        $strategy = $this->strategyClass();

        return new $strategy;
    }

    /**
     * @return class-string<PartitionStrategy>
     */
    public function strategyClass(): string
    {
        return match ($this) {
            self::Daily => DailyStrategy::class,
            self::Weekly => WeeklyStrategy::class,
            self::Monthly => MonthlyStrategy::class,
            self::Yearly => YearlyStrategy::class,
        };
    }
}

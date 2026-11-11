<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Unit;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yurkovskiy\Partitions\Support\MySqlDate;

class MySqlDateTest extends TestCase
{
    #[DataProvider('dates')]
    public function test_it_matches_mysql_to_days(string $date, int $expected): void
    {
        $this->assertSame($expected, MySqlDate::toDays(CarbonImmutable::parse($date)));
    }

    #[DataProvider('dates')]
    public function test_it_converts_back_to_a_date(string $date, int $days): void
    {
        $this->assertSame($date, MySqlDate::fromDays($days)->format('Y-m-d'));
    }

    public function test_it_ignores_the_time_part(): void
    {
        $this->assertSame(
            MySqlDate::toDays(CarbonImmutable::parse('2025-06-13 00:00:00')),
            MySqlDate::toDays(CarbonImmutable::parse('2025-06-13 23:59:59'))
        );
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function dates(): array
    {
        return [
            'epoch' => ['1970-01-01', 719528],
            'leap day' => ['2000-02-29', 730544],
            'documented example' => ['2008-10-07', 733687],
            'today-ish' => ['2025-06-13', 739780],
            'non leap century' => ['2100-02-28', 767068],
            'max date' => ['9999-12-31', 3652424],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Unit;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yurkovskiy\Partitions\Exceptions\InvalidTimezoneException;
use Yurkovskiy\Partitions\Exceptions\TimezoneMismatchException;
use Yurkovskiy\Partitions\PartitionBuilder;
use Yurkovskiy\Partitions\Schema\PartitionExpression;
use Yurkovskiy\Partitions\Tests\Support\FakePartitionRepository;

class TimezoneTest extends TestCase
{
    private const ZONES = ['UTC', 'Europe/Kyiv', 'America/New_York', 'Pacific/Kiritimati'];

    private string $applicationTimezone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->applicationTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        date_default_timezone_set($this->applicationTimezone);

        parent::tearDown();
    }

    public function test_boundaries_do_not_depend_on_the_application_timezone(): void
    {
        $statements = [];

        foreach (self::ZONES as $zone) {
            $this->pinNow($zone);

            $statements[] = $this->builder()->timezone('Europe/Kyiv')->syncStatements()->all();
        }

        $this->assertSame([<<<'SQL'
            ALTER TABLE `transactions`
            REORGANIZE PARTITION `pMax` INTO (
                PARTITION `p20250613` VALUES LESS THAN (1749848400),
                PARTITION `p20250614` VALUES LESS THAN (1749934800),
                PARTITION `pMax` VALUES LESS THAN MAXVALUE
            )
            SQL], $statements[0]);

        foreach ($statements as $index => $statement) {
            $this->assertSame($statements[0], $statement, self::ZONES[$index]);
        }
    }

    public function test_boundaries_follow_the_configured_timezone(): void
    {
        $this->pinNow('UTC');

        $boundaries = fn (string $zone): array => $this->builder()
            ->timezone($zone)
            ->missing()
            ->pluck('boundary')
            ->all();

        $this->assertSame(['1749859200', '1749945600'], $boundaries('UTC'));
        $this->assertSame(['1749848400', '1749934800'], $boundaries('Europe/Kyiv'));
        $this->assertSame(['1749873600', '1749960000'], $boundaries('America/New_York'));
    }

    public function test_to_days_and_range_columns_ignore_the_timezone(): void
    {
        $this->pinNow('UTC');

        foreach (['to_days' => ['739781', '739782'], 'columns' => ["'2025-06-14'", "'2025-06-15'"]] as $expression => $expected) {
            foreach (self::ZONES as $zone) {
                $boundaries = $this->builder($this->repository(['pMax' => 'MAXVALUE'], $expression))
                    ->expression($expression)
                    ->timezone($zone)
                    ->missing()
                    ->pluck('boundary')
                    ->all();

                $this->assertSame($expected, $boundaries, "$expression in $zone");
            }
        }
    }

    public function test_the_boundary_round_trip_keeps_the_date_for_any_input_offset(): void
    {
        $this->pinNow('UTC');

        $expression = PartitionExpression::UnixTimestamp;
        $zone = new DateTimeZone('Europe/Kyiv');

        foreach (self::ZONES as $input) {
            $date = new DateTimeImmutable('2025-06-14 00:00:00', new DateTimeZone($input));

            $this->assertSame(
                '2025-06-14',
                $expression->parseBoundary($expression->boundary($date, $zone), $zone)?->format('Y-m-d'),
                $input
            );

            $this->assertSame(
                '2025-06-14',
                $expression->parseBoundary($expression->boundary($date))?->format('Y-m-d'),
                $input
            );
        }
    }

    public function test_a_boundary_written_in_another_timezone_still_covers_the_full_period(): void
    {
        $this->pinNow('UTC');

        $missing = $this->builder($this->repository(['p20250613' => '1749848400', 'pMax' => 'MAXVALUE']))
            ->timezone('Europe/Kyiv')
            ->missing();

        $this->assertSame(['p20250614'], $missing->pluck('name')->all());
        $this->assertSame(86400, (int) $missing->first()->boundary - 1749848400);
    }

    public function test_a_day_whose_midnight_dst_skips_ends_at_the_next_midnight(): void
    {
        date_default_timezone_set('America/Santiago');
        CarbonImmutable::setTestNow('2026-09-05 12:00:00');

        $missing = $this->builder()->timezone('America/Santiago')->future(2)->missing();

        $midnight = fn (string $date): string => (string) (new DateTimeImmutable(
            "$date 00:00:00",
            new DateTimeZone('America/Santiago')
        ))->getTimestamp();

        $this->assertSame(['p20260905', 'p20260906', 'p20260907'], $missing->pluck('name')->all());
        $this->assertSame(
            [$midnight('2026-09-06'), $midnight('2026-09-07'), $midnight('2026-09-08')],
            $missing->pluck('boundary')->all()
        );
        $this->assertSame('1788750000', $missing->get(1)->boundary);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: string, 4: list<string>}>
     */
    public static function periodsAhead(): iterable
    {
        $periods = [
            'daily' => ['p20250613', '2025-06-14', ['p20250614', 'p20250615', 'p20250616']],
            'weekly' => ['p202524', '2025-06-16', ['p202525', 'p202526', 'p202527']],
            'monthly' => ['p202506', '2025-07-01', ['p202507', 'p202508', 'p202509']],
            'yearly' => ['p2025', '2026-01-01', ['p2026', 'p2027', 'p2028']],
        ];

        foreach (PartitionExpression::values() as $expression) {
            foreach ($periods as $period => [$last, $upperBound, $expected]) {
                yield "$period by $expression" => [$expression, $period, $last, $upperBound, $expected];
            }
        }
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('periodsAhead')]
    public function test_the_periods_ahead_do_not_depend_on_the_application_timezone(
        string $expression,
        string $period,
        string $last,
        string $upperBound,
        array $expected
    ): void {
        foreach (self::ZONES as $application) {
            foreach (self::ZONES as $reference) {
                $this->pinNow($application);

                $zone = new DateTimeZone($reference);
                $boundary = PartitionExpression::make($expression)->boundary(new DateTimeImmutable($upperBound, $zone), $zone);

                $missing = (new PartitionBuilder($this->repository([$last => $boundary, 'pMax' => 'MAXVALUE'], $expression)))
                    ->table('transactions')
                    ->strategy($period, 'created_at')
                    ->expression($expression)
                    ->timezone($reference)
                    ->future(3)
                    ->missing()
                    ->pluck('name')
                    ->all();

                $this->assertSame($expected, $missing, "application in $application, boundaries in $reference");
            }
        }
    }

    public function test_tomorrow_is_added_in_advance_east_of_utc_without_a_max_partition(): void
    {
        foreach (self::ZONES as $zone) {
            $this->pinNow($zone);

            $repository = $this->repository(['p20250613' => '739781'], 'to_days');

            $this->builder($repository)->expression('to_days')->sync();

            $this->assertSame([<<<'SQL'
                ALTER TABLE `transactions`
                ADD PARTITION (
                    PARTITION `p20250614` VALUES LESS THAN (739782)
                )
                SQL], $repository->executed, $zone);
        }
    }

    public function test_a_date_object_drops_by_its_own_calendar_date(): void
    {
        foreach (self::ZONES as $application) {
            $this->pinNow($application);

            foreach ($this->sameDay('2025-06-14') as $index => $date) {
                $label = "application in $application, date #$index";
                $repository = $this->daily();

                $this->assertSame(['p20250612', 'p20250613'], $this->dailyBuilder($repository)->outdated($date)->pluck('name')->all(), $label);

                $this->dailyBuilder($repository)->dropBefore($date);

                $this->assertSame([
                    'ALTER TABLE `transactions` DROP PARTITION `p20250612`',
                    'ALTER TABLE `transactions` DROP PARTITION `p20250613`',
                ], $repository->executed, $label);
            }
        }
    }

    public function test_a_date_object_selects_a_range_by_its_own_calendar_dates(): void
    {
        foreach (self::ZONES as $application) {
            $this->pinNow($application);

            foreach (array_map(null, $this->sameDay('2025-06-13'), $this->sameDay('2025-06-14')) as $index => [$from, $to]) {
                $label = "application in $application, dates #$index";
                $repository = $this->daily();

                $this->assertSame(['p20250613', 'p20250614'], $this->dailyBuilder($repository)->inRange($from, $to)->pluck('name')->all(), $label);

                $this->dailyBuilder($repository)->dropBetween($from, $to);

                $this->assertSame([
                    'ALTER TABLE `transactions` DROP PARTITION `p20250613`',
                    'ALTER TABLE `transactions` DROP PARTITION `p20250614`',
                ], $repository->executed, $label);
            }
        }
    }

    public function test_a_date_object_plans_partitions_by_its_own_calendar_dates(): void
    {
        foreach (self::ZONES as $application) {
            $this->pinNow($application);

            foreach ($this->sameDay('2025-06-16') as $index => $date) {
                $this->assertSame(
                    ['p20250616', 'p20250617', 'p20250618'],
                    $this->dailyBuilder($this->daily())->from($date)->future(5)->missing()->pluck('name')->all(),
                    "from() with application in $application, date #$index"
                );
            }

            foreach (array_map(null, $this->sameDay('2025-06-14'), $this->sameDay('2025-06-17')) as $index => [$from, $to]) {
                $this->assertSame(
                    ['p20250614', 'p20250615', 'p20250616', 'p20250617'],
                    $this->dailyBuilder($this->daily(['p20250613' => '739781']))->between($from, $to)->missing()->pluck('name')->all(),
                    "between() with application in $application, dates #$index"
                );
            }
        }
    }

    public function test_a_date_object_names_the_partition_of_its_own_calendar_date(): void
    {
        foreach (self::ZONES as $application) {
            $this->pinNow($application);

            foreach ($this->sameDay('2025-06-14') as $index => $date) {
                $builder = $this->dailyBuilder($this->daily());

                $this->assertSame('p20250614', $builder->partitionNameFor($date), "application in $application, date #$index");
                $this->assertTrue($builder->has($date), "application in $application, date #$index");
            }
        }
    }

    public function test_the_connection_timezone_is_the_default_reference_frame(): void
    {
        $this->pinNow('UTC');

        $repository = $this->repository(['pMax' => 'MAXVALUE']);
        $repository->connectionTimezone = 'Europe/Kyiv';

        $builder = $this->builder($repository);

        $this->assertSame('Europe/Kyiv', $builder->getTimezone()->getName());
        $this->assertSame(['1749848400', '1749934800'], $builder->missing()->pluck('boundary')->all());
        $this->assertSame('UTC', $builder->timezone('UTC')->getTimezone()->getName());
    }

    public function test_the_application_timezone_is_the_last_resort(): void
    {
        $this->pinNow('America/New_York');

        $repository = $this->repository(['pMax' => 'MAXVALUE']);

        $this->assertSame('America/New_York', $this->builder($repository)->getTimezone()->getName());

        $repository->connectionTimezone = 'SYSTEM';

        $this->assertSame('America/New_York', $this->builder($repository)->getTimezone()->getName());

        $repository->connectionTimezone = 'Mars/Olympus';

        $this->assertSame('America/New_York', $this->builder($repository)->getTimezone()->getName());
    }

    public function test_an_empty_timezone_clears_the_configured_one(): void
    {
        $this->pinNow('UTC');

        $repository = $this->repository(['pMax' => 'MAXVALUE']);
        $repository->connectionTimezone = 'Europe/Kyiv';

        $builder = $this->builder($repository)->timezone('UTC')->timezone('');

        $this->assertSame('Europe/Kyiv', $builder->getTimezone()->getName());
    }

    public function test_an_unknown_timezone_is_refused(): void
    {
        $this->pinNow('UTC');

        $this->expectException(InvalidTimezoneException::class);
        $this->expectExceptionMessage('Invalid timezone [Mars/Olympus]');

        $this->builder()->timezone('Mars/Olympus');
    }

    public function test_a_session_timezone_mismatch_stops_the_ddl(): void
    {
        $this->pinNow('UTC');

        $repository = $this->repository(['pMax' => 'MAXVALUE']);
        $repository->sessionTimezone = 'Etc/GMT+1';

        try {
            $this->builder($repository)->timezone('UTC')->verifyTimezone()->sync();

            $this->fail('A session timezone one hour away was accepted.');
        } catch (TimezoneMismatchException $exception) {
            $this->assertStringContainsString('are computed in [UTC]', $exception->getMessage());
            $this->assertStringContainsString('+60 minutes away', $exception->getMessage());
        }

        $this->assertSame([['2025-06-13 12:00:00', '2025-06-14 00:00:00', '2025-06-15 00:00:00']], $repository->timestampQueries);
        $this->assertSame([], $repository->executed);
    }

    public function test_a_matching_session_timezone_runs_the_ddl(): void
    {
        $this->pinNow('UTC');

        $repository = $this->repository(['pMax' => 'MAXVALUE']);
        $repository->sessionTimezone = 'UTC';

        $this->builder($repository)->timezone('UTC')->verifyTimezone()->sync();

        $this->assertSame([['2025-06-13 12:00:00', '2025-06-14 00:00:00', '2025-06-15 00:00:00']], $repository->timestampQueries);
        $this->assertCount(1, $repository->executed);
    }

    public function test_a_session_with_other_dst_rules_is_caught_before_the_clocks_change(): void
    {
        date_default_timezone_set('UTC');
        CarbonImmutable::setTestNow('2026-01-15 12:00:00');

        $repository = $this->repository(['pMax' => 'MAXVALUE']);
        $repository->sessionTimezone = 'Europe/London';

        try {
            $this->builder($repository)->monthly()->timezone('UTC')->verifyTimezone()->future(7)->sync();

            $this->fail('A session that reads the summer boundaries an hour away was accepted.');
        } catch (TimezoneMismatchException $exception) {
            $this->assertStringContainsString('reads [2026-04-01 00:00:00] -60 minutes away', $exception->getMessage());
        }

        $this->assertSame([[
            '2026-01-15 12:00:00',
            '2026-02-01 00:00:00',
            '2026-03-01 00:00:00',
            '2026-04-01 00:00:00',
            '2026-05-01 00:00:00',
            '2026-06-01 00:00:00',
            '2026-07-01 00:00:00',
            '2026-08-01 00:00:00',
            '2026-09-01 00:00:00',
        ]], $repository->timestampQueries);
        $this->assertSame([], $repository->executed);
    }

    public function test_a_session_zone_with_the_same_dst_rules_passes_through_the_clock_change(): void
    {
        date_default_timezone_set('UTC');
        CarbonImmutable::setTestNow('2026-01-15 12:00:00');

        $repository = $this->repository(['pMax' => 'MAXVALUE']);
        $repository->sessionTimezone = 'Europe/Lisbon';

        $this->builder($repository)->monthly()->timezone('Europe/London')->verifyTimezone()->future(7)->sync();

        $this->assertCount(1, $repository->timestampQueries);
        $this->assertCount(1, $repository->executed);
    }

    public function test_a_datetime_the_server_already_confirmed_is_not_asked_again(): void
    {
        $this->pinNow('UTC');

        $repository = $this->repository(['pMax' => 'MAXVALUE']);
        $repository->sessionTimezone = 'UTC';

        $builder = $this->builder($repository)->timezone('UTC')->verifyTimezone();
        $builder->sync();
        $builder->removePartitioning();
        $builder->future(2)->sync();

        $this->assertSame([
            ['2025-06-13 12:00:00', '2025-06-14 00:00:00', '2025-06-15 00:00:00'],
            ['2025-06-16 00:00:00'],
        ], $repository->timestampQueries);
        $this->assertCount(3, $repository->executed);
    }

    public function test_changing_the_timezone_asks_the_server_again(): void
    {
        $this->pinNow('UTC');

        $repository = $this->repository(['pMax' => 'MAXVALUE']);
        $repository->sessionTimezone = 'UTC';

        $builder = $this->builder($repository)->timezone('UTC')->verifyTimezone();
        $builder->sync();

        $this->expectException(TimezoneMismatchException::class);

        $builder->timezone('Europe/Kyiv')->future(2)->sync();
    }

    public function test_the_session_timezone_is_only_read_when_it_matters(): void
    {
        $this->pinNow('UTC');

        $repository = $this->repository(['pMax' => 'MAXVALUE']);
        $repository->sessionTimezone = 'Etc/GMT+1';

        $this->builder($repository)->timezone('UTC')->sync();

        $toDays = $this->repository(['pMax' => 'MAXVALUE'], 'to_days');
        $toDays->sessionTimezone = 'Etc/GMT+1';

        $this->builder($toDays)->expression('to_days')->verifyTimezone()->sync();

        $this->assertSame([], $repository->timestampQueries);
        $this->assertSame([], $toDays->timestampQueries);
        $this->assertCount(1, $repository->executed);
        $this->assertCount(1, $toDays->executed);
    }

    public function test_a_dry_run_asks_the_server_nothing(): void
    {
        $this->pinNow('UTC');

        $repository = $this->repository(['pMax' => 'MAXVALUE']);
        $repository->sessionTimezone = 'Etc/GMT+1';

        $statements = $this->builder($repository)->timezone('UTC')->verifyTimezone()->dryRun()->sync();

        $this->assertCount(1, $statements);
        $this->assertSame([], $repository->timestampQueries);
        $this->assertSame([], $repository->executed);
    }

    private function pinNow(string $applicationTimezone): void
    {
        date_default_timezone_set($applicationTimezone);

        CarbonImmutable::setTestNow('2025-06-13 12:00:00');
    }

    /**
     * @param  array<string, string|null>  $partitions
     */
    private function repository(array $partitions, string $expression = 'unix_timestamp'): FakePartitionRepository
    {
        return FakePartitionRepository::withPartitions(
            $partitions,
            $expression === 'columns' ? 'RANGE COLUMNS' : 'RANGE',
            $expression === 'columns' ? '`created_at`' : "$expression(`created_at`)"
        );
    }

    /**
     * @return list<DateTimeInterface|string>
     */
    private function sameDay(string $date): array
    {
        $dates = [$date, $date.'T00:00:00+14:00', $date.'T23:59:59-10:00'];

        foreach (self::ZONES as $zone) {
            $dates[] = new DateTimeImmutable($date, new DateTimeZone($zone));
            $dates[] = CarbonImmutable::parse($date.' 23:59:59', $zone);
        }

        return $dates;
    }

    /**
     * @param  array<string, string|null>|null  $partitions
     */
    private function daily(?array $partitions = null): FakePartitionRepository
    {
        return $this->repository($partitions ?? [
            'p20250612' => '739780',
            'p20250613' => '739781',
            'p20250614' => '739782',
            'p20250615' => '739783',
            'pMax' => 'MAXVALUE',
        ], 'to_days');
    }

    private function dailyBuilder(FakePartitionRepository $repository): PartitionBuilder
    {
        return $this->builder($repository)->expression('to_days');
    }

    private function builder(?FakePartitionRepository $repository = null): PartitionBuilder
    {
        return (new PartitionBuilder($repository ?? $this->repository(['pMax' => 'MAXVALUE'])))
            ->table('transactions')
            ->daily('created_at')
            ->expression('unix_timestamp')
            ->future(1);
    }
}

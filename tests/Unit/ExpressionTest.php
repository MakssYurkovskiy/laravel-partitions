<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Unit;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use Yurkovskiy\Partitions\Exceptions\ExpressionMismatchException;
use Yurkovskiy\Partitions\Exceptions\UnknownExpressionException;
use Yurkovskiy\Partitions\PartitionBuilder;
use Yurkovskiy\Partitions\Schema\PartitionExpression;
use Yurkovskiy\Partitions\Tests\Support\FakePartitionRepository;

class ExpressionTest extends TestCase
{
    private string $timezone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');

        CarbonImmutable::setTestNow('2025-06-13 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        date_default_timezone_set($this->timezone);

        parent::tearDown();
    }

    public function test_canonical_values_resolve_to_the_same_case(): void
    {
        foreach (PartitionExpression::cases() as $expression) {
            $this->assertSame($expression, PartitionExpression::make($expression));
            $this->assertSame($expression, PartitionExpression::make($expression->value));
        }
    }

    public function test_the_case_is_resolved_regardless_of_letter_case_and_padding(): void
    {
        $this->assertSame(PartitionExpression::ToDays, PartitionExpression::make('TO_DAYS'));
        $this->assertSame(PartitionExpression::UnixTimestamp, PartitionExpression::make('Unix_Timestamp'));
        $this->assertSame(PartitionExpression::Columns, PartitionExpression::make("  columns\n"));
        $this->assertSame(PartitionExpression::ToDays, PartitionExpression::make(' TO_DAYS '));
    }

    public function test_anything_else_is_reported_with_the_input_as_given(): void
    {
        $this->expectException(UnknownExpressionException::class);
        $this->expectExceptionMessage('Unknown partition expression [ToDays()]. Use to_days, unix_timestamp or columns.');

        PartitionExpression::make('ToDays()');
    }

    public function test_the_builder_accepts_a_padded_expression_name(): void
    {
        $repository = FakePartitionRepository::withPartitions(
            ['p202506' => '1751328000', 'pMax' => 'MAXVALUE'],
            'RANGE',
            'unix_timestamp(`created_at`)'
        );

        $missing = (new PartitionBuilder($repository))
            ->table('transactions')
            ->monthly('created_at')
            ->expression(' UNIX_TIMESTAMP ')
            ->future(1)
            ->missing();

        $this->assertSame(['p202507'], $missing->pluck('name')->all());
    }

    public function test_unix_timestamp_partitions_are_supported(): void
    {
        $repository = FakePartitionRepository::withPartitions(
            ['p202506' => '1751328000', 'p_future' => 'MAXVALUE'],
            'RANGE',
            'unix_timestamp(`created_at`)'
        );

        $statements = (new PartitionBuilder($repository))
            ->table('transactions')
            ->monthly('created_at')
            ->expression('unix_timestamp')
            ->withMaxPartition('p_future')
            ->future(2)
            ->sync();

        $this->assertSame([<<<'SQL'
            ALTER TABLE `transactions`
            REORGANIZE PARTITION `p_future` INTO (
                PARTITION `p202507` VALUES LESS THAN (1754006400),
                PARTITION `p202508` VALUES LESS THAN (1756684800),
                PARTITION `p_future` VALUES LESS THAN MAXVALUE
            )
            SQL], $statements->all());
    }

    public function test_it_refuses_to_manage_a_table_partitioned_differently(): void
    {
        $repository = FakePartitionRepository::withPartitions(
            ['p202506' => '1751328000', 'p_future' => 'MAXVALUE'],
            'RANGE',
            'unix_timestamp(`created_at`)'
        );

        $this->expectException(ExpressionMismatchException::class);
        $this->expectExceptionMessage('is partitioned by [RANGE (unix_timestamp(created_at))]');

        (new PartitionBuilder($repository))
            ->table('transactions')
            ->daily('created_at')
            ->missing();
    }

    public function test_it_refuses_when_only_the_column_differs(): void
    {
        $repository = FakePartitionRepository::withPartitions(
            ['p20250613' => '739781'],
            'RANGE',
            'to_days(`processed_at`)'
        );

        $this->expectException(ExpressionMismatchException::class);

        (new PartitionBuilder($repository))
            ->table('transactions')
            ->daily('created_at')
            ->missing();
    }

    public function test_a_matching_table_passes_the_guard(): void
    {
        $repository = FakePartitionRepository::withPartitions(
            ['p20250613' => '739781', 'pMax' => 'MAXVALUE'],
            'RANGE',
            'to_days(`created_at`)'
        );

        $missing = (new PartitionBuilder($repository))
            ->table('transactions')
            ->daily('created_at')
            ->future(1)
            ->missing();

        $this->assertSame(['p20250614'], $missing->pluck('name')->all());
    }

    public function test_using_unix_timestamp_selects_the_same_expression_as_its_name(): void
    {
        $repository = FakePartitionRepository::withPartitions(
            ['p202506' => '1751328000', 'pMax' => 'MAXVALUE'],
            'RANGE',
            'unix_timestamp(`created_at`)'
        );

        $statements = (new PartitionBuilder($repository))
            ->table('transactions')
            ->monthly('created_at')
            ->usingUnixTimestamp()
            ->future(1)
            ->sync();

        $this->assertSame([<<<'SQL'
            ALTER TABLE `transactions`
            REORGANIZE PARTITION `pMax` INTO (
                PARTITION `p202507` VALUES LESS THAN (1754006400),
                PARTITION `pMax` VALUES LESS THAN MAXVALUE
            )
            SQL], $statements->all());
    }

    public function test_range_columns_tables_are_recognised(): void
    {
        $repository = FakePartitionRepository::withPartitions(
            ['p202506' => "'2025-07-01'", 'pMax' => 'MAXVALUE'],
            'RANGE COLUMNS',
            '`occurred_on`'
        );

        $missing = (new PartitionBuilder($repository))
            ->table('events')
            ->monthly('occurred_on')
            ->usingRangeColumns()
            ->future(1)
            ->missing();

        $this->assertSame(['p202507'], $missing->pluck('name')->all());
    }
}

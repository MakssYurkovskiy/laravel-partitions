<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Yurkovskiy\Partitions\Exceptions\AlreadyPartitionedException;
use Yurkovskiy\Partitions\Exceptions\InvalidRangeException;
use Yurkovskiy\Partitions\Exceptions\MissingDefinitionException;
use Yurkovskiy\Partitions\Exceptions\MissingTableException;
use Yurkovskiy\Partitions\Exceptions\NotPartitionedException;
use Yurkovskiy\Partitions\Exceptions\PartitionException;
use Yurkovskiy\Partitions\Exceptions\UnknownExpressionException;
use Yurkovskiy\Partitions\PartitionBuilder;
use Yurkovskiy\Partitions\Schema\PartitionExpression;
use Yurkovskiy\Partitions\Tests\Support\FakePartitionRepository;

class ExceptionTest extends TestCase
{
    public function test_the_base_exception_is_a_runtime_exception(): void
    {
        $this->assertInstanceOf(RuntimeException::class, new PartitionException('…'));
    }

    public function test_every_exception_of_the_package_narrows_the_base_one(): void
    {
        $files = glob(__DIR__.'/../../src/Exceptions/*.php');

        $this->assertNotEmpty($files);

        foreach ($files ?: [] as $file) {
            $class = 'Yurkovskiy\\Partitions\\Exceptions\\'.basename($file, '.php');

            if ($class === PartitionException::class) {
                continue;
            }

            $this->assertTrue(
                is_subclass_of($class, PartitionException::class),
                sprintf('%s does not extend %s.', $class, PartitionException::class)
            );
        }
    }

    public function test_the_two_sided_cases_keep_a_message_per_caller(): void
    {
        $this->assertStringContainsString('use sync()', AlreadyPartitionedException::forCreate('transactions')->getMessage());
        $this->assertStringContainsString('replacePartitioning()', AlreadyPartitionedException::forMigration('transactions')->getMessage());
        $this->assertStringContainsString('isPartitioned()', NotPartitionedException::forRemoval('transactions')->getMessage());
        $this->assertStringContainsString("Partition::table('transactions')", NotPartitionedException::forMigration('transactions')->getMessage());
    }

    public function test_an_empty_range_says_where_its_end_came_from(): void
    {
        $this->assertSame(
            'The range for table [transactions] starts at 2025-06-25 and ends at 2025-06-21, before it starts, '
            .'so it covers no period. Swap the two dates, or correct the one that is off.',
            InvalidRangeException::forDates('transactions', '2025-06-25', '2025-06-21')->getMessage()
        );
        $this->assertSame(
            'The range for table [transactions] starts at 2025-06-25, but with no end date it stops at 2025-06-23 '
            .'— future(10), counted from today — so it covers no period. Give the range an end date past its '
            .'start, or raise the look-ahead.',
            InvalidRangeException::forLookAhead('transactions', '2025-06-25', '2025-06-23', 10)->getMessage()
        );
    }

    public function test_an_unknown_expression_is_refused(): void
    {
        $this->expectException(UnknownExpressionException::class);
        $this->expectExceptionMessage('Unknown partition expression [hourly]');

        PartitionExpression::make('hourly');
    }

    public function test_a_builder_without_a_table_is_refused(): void
    {
        $this->expectException(MissingTableException::class);
        $this->expectExceptionMessage('No table set for the partition builder.');

        (new PartitionBuilder(new FakePartitionRepository))->getTable();
    }

    public function test_a_missing_definition_names_the_partition_by_command(): void
    {
        $this->assertStringContainsString('partitionBy', (new MissingDefinitionException)->getMessage());
    }
}

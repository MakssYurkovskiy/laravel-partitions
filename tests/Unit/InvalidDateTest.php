<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Unit;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\DataProvider;
use Yurkovskiy\Partitions\Console\AddPartitionsCommand;
use Yurkovskiy\Partitions\Console\DropPartitionsCommand;
use Yurkovskiy\Partitions\Exceptions\InvalidDateException;
use Yurkovskiy\Partitions\Exceptions\PartitionException;
use Yurkovskiy\Partitions\PartitionBuilder;
use Yurkovskiy\Partitions\PartitionManager;
use Yurkovskiy\Partitions\Schema\PartitionMacros;
use Yurkovskiy\Partitions\Tests\Support\CommandTestCase;
use Yurkovskiy\Partitions\Tests\Support\FakePartitionRepository;

class InvalidDateTest extends CommandTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2025-06-13 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Container::setInstance(null);

        parent::tearDown();
    }

    protected function partitioned(): FakePartitionRepository
    {
        return FakePartitionRepository::withPartitions([
            'p20250612' => '739780',
            'p20250613' => '739781',
            'pMax' => 'MAXVALUE',
        ], expression: 'to_days(`created_at`)');
    }

    protected function builder(): PartitionBuilder
    {
        return (new PartitionBuilder($this->partitioned()))->table('transactions')->daily();
    }

    /**
     * @return array<string, array{Closure(PartitionBuilder): mixed}>
     */
    public static function builderEntryPoints(): array
    {
        return [
            'from()' => [fn (PartitionBuilder $builder) => $builder->from('not a date')],
            'to()' => [fn (PartitionBuilder $builder) => $builder->to('not a date')],
            'between() start' => [fn (PartitionBuilder $builder) => $builder->between('not a date', '2025-06-20')],
            'between() end' => [fn (PartitionBuilder $builder) => $builder->between('2025-06-13', 'not a date')],
            'partitionNameFor()' => [fn (PartitionBuilder $builder) => $builder->partitionNameFor('not a date')],
            'has()' => [fn (PartitionBuilder $builder) => $builder->has('not a date')],
            'inRange() start' => [fn (PartitionBuilder $builder) => $builder->inRange('not a date', '2025-06-13')],
            'inRange() end' => [fn (PartitionBuilder $builder) => $builder->inRange('2025-06-12', 'not a date')],
            'outdated()' => [fn (PartitionBuilder $builder) => $builder->outdated('not a date')],
            'dropBefore()' => [fn (PartitionBuilder $builder) => $builder->dropBefore('not a date')],
            'dropBetween() start' => [fn (PartitionBuilder $builder) => $builder->dropBetween('not a date', '2025-06-13')],
            'dropBetween() end' => [fn (PartitionBuilder $builder) => $builder->dropBetween('2025-06-12', 'not a date')],
        ];
    }

    /**
     * @param  Closure(PartitionBuilder): mixed  $call
     */
    #[DataProvider('builderEntryPoints')]
    public function test_the_builder_refuses_a_date_it_cannot_read(Closure $call): void
    {
        $this->expectException(InvalidDateException::class);
        $this->expectExceptionMessage('Invalid date [not a date]');

        $call($this->builder());
    }

    public function test_the_refusal_is_a_package_exception_that_keeps_the_original(): void
    {
        try {
            $this->builder()->from('2025-13-45');

            $this->fail('The date was accepted.');
        } catch (PartitionException $e) {
            $this->assertInstanceOf(InvalidDateException::class, $e);
            $this->assertInstanceOf(InvalidFormatException::class, $e->getPrevious());
            $this->assertSame(
                'Invalid date [2025-13-45]. Use a date PHP can read, e.g. "2025-06-13", or pass a DateTimeInterface.',
                $e->getMessage()
            );
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function blankDates(): array
    {
        return [
            'empty' => [''],
            'spaces' => ['  '],
        ];
    }

    #[DataProvider('blankDates')]
    public function test_a_blank_date_is_refused_rather_than_read_as_now(string $date): void
    {
        $repository = $this->partitioned();
        $builder = (new PartitionBuilder($repository))->table('transactions')->daily();

        try {
            $builder->dropBefore($date);

            $this->fail('The date was accepted.');
        } catch (InvalidDateException) {
            $this->assertSame([], $repository->executed);
        }
    }

    public function test_a_date_object_and_a_relative_date_are_still_read(): void
    {
        $builder = $this->builder();

        $this->assertSame('p20250613', $builder->partitionNameFor(new \DateTimeImmutable('2025-06-13')));
        $this->assertSame('p20250614', $builder->partitionNameFor('tomorrow'));
    }

    /**
     * @return array<string, array{Closure(Blueprint): mixed}>
     */
    public static function blueprintEntryPoints(): array
    {
        return [
            'from()' => [fn (Blueprint $table) => $table->partitionByRange('created_at')->daily()->from('not a date')],
            'to()' => [fn (Blueprint $table) => $table->partitionByRange('created_at')->daily()->to('not a date')],
            'between() start' => [fn (Blueprint $table) => $table->partitionByRange('created_at')->daily()->between('not a date', '2025-06-20')],
            'between() end' => [fn (Blueprint $table) => $table->partitionByRange('created_at')->daily()->between('2025-06-13', 'not a date')],
        ];
    }

    /**
     * @param  Closure(Blueprint): mixed  $declare
     */
    #[DataProvider('blueprintEntryPoints')]
    public function test_a_migration_refuses_a_date_it_cannot_read(Closure $declare): void
    {
        $container = new Container;
        $container->instance(PartitionManager::class, new PartitionManager(new FakePartitionRepository, null, [
            'prefix' => 'p',
            'max_partition' => 'pMax',
            'expression' => 'to_days',
            'future' => 7,
        ]));
        Container::setInstance($container);
        PartitionMacros::register();

        $this->expectException(InvalidDateException::class);
        $this->expectExceptionMessage('Invalid date [not a date]');

        $connection = new MySqlConnection(fn () => null, 'testing', '', ['name' => 'mysql']);
        $connection->useDefaultSchemaGrammar();

        $declare(new Blueprint($connection, 'transactions'));
    }

    /**
     * @return array<string, array{class-string<Command>, array<string, string>}>
     */
    public static function commandEntryPoints(): array
    {
        return [
            'partitions:add --from' => [AddPartitionsCommand::class, ['--from' => 'not a date']],
            'partitions:add --to' => [AddPartitionsCommand::class, ['--to' => 'not a date']],
            'partitions:drop --before' => [DropPartitionsCommand::class, ['--before' => 'not a date']],
            'partitions:drop --from' => [DropPartitionsCommand::class, ['--from' => 'not a date', '--to' => '2025-06-13']],
            'partitions:drop --to' => [DropPartitionsCommand::class, ['--from' => '2025-06-12', '--to' => 'not a date']],
        ];
    }

    /**
     * @param  class-string<Command>  $command
     * @param  array<string, string>  $options
     */
    #[DataProvider('commandEntryPoints')]
    public function test_a_command_refuses_a_date_it_cannot_read(string $command, array $options): void
    {
        $repository = $this->partitioned();
        $command = $this->withManager(new $command, new PartitionManager($repository));

        try {
            $this->runCommand($command, ['table' => 'transactions', '--force' => true] + $options);

            $this->fail('The date was accepted.');
        } catch (InvalidDateException $e) {
            $this->assertStringContainsString('Invalid date [not a date]', $e->getMessage());
            $this->assertSame([], $repository->executed);
        }
    }
}

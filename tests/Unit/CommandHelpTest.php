<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Unit;

use Illuminate\Console\Attributes\Signature;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yurkovskiy\Partitions\Console\AddPartitionsCommand;
use Yurkovskiy\Partitions\Console\DropPartitionsCommand;
use Yurkovskiy\Partitions\Console\ListPartitionsCommand;
use Yurkovskiy\Partitions\Console\PartitionCommand;
use Yurkovskiy\Partitions\Console\RunPartitionsCommand;
use Yurkovskiy\Partitions\Schema\PartitionExpression;
use Yurkovskiy\Partitions\Schema\PartitionPeriod;

class CommandHelpTest extends TestCase
{
    private const TABLE_OPTIONS = [
        'strategy' => 'Partition period: daily (day, d), weekly (week, w), monthly (month, m), yearly (year, y), '
            .'or a strategy class name',
        'column' => 'Date column used for partitioning',
        'expression' => 'Partitioning expression: to_days, unix_timestamp or columns',
        'prefix' => 'Partition name prefix',
        'connection' => 'Database connection',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function tableOptionsOf(PartitionCommand $command): array
    {
        $described = [];

        foreach (array_keys(self::TABLE_OPTIONS) as $name) {
            $described[$name] = $command->getDefinition()->getOption($name)->getDescription();
        }

        return $described;
    }

    /**
     * @return array<string, array{class-string<PartitionCommand>}>
     */
    public static function tableCommands(): array
    {
        return [
            'partitions:add' => [AddPartitionsCommand::class],
            'partitions:drop' => [DropPartitionsCommand::class],
            'partitions:list' => [ListPartitionsCommand::class],
        ];
    }

    /**
     * @param  class-string<PartitionCommand>  $command
     */
    #[DataProvider('tableCommands')]
    public function test_a_command_that_takes_a_table_describes_it_with_the_same_options(string $command): void
    {
        self::assertSame(self::TABLE_OPTIONS, $this->tableOptionsOf(new $command));
    }

    public function test_the_strategy_help_names_every_period_and_every_shorthand(): void
    {
        $description = (new AddPartitionsCommand)
            ->getDefinition()
            ->getOption('strategy')
            ->getDescription();

        foreach (PartitionPeriod::cases() as $period) {
            self::assertStringContainsString(
                sprintf('%s (%s)', $period->value, implode(', ', $period->aliases())),
                $description
            );
        }
    }

    public function test_the_expression_help_names_every_expression(): void
    {
        $description = (new AddPartitionsCommand)
            ->getDefinition()
            ->getOption('expression')
            ->getDescription();

        foreach (PartitionExpression::cases() as $expression) {
            self::assertStringContainsString($expression->value, $description);
        }
    }

    public function test_the_table_options_take_a_value_that_may_be_left_empty(): void
    {
        $definition = (new AddPartitionsCommand)->getDefinition();

        foreach (array_keys(self::TABLE_OPTIONS) as $name) {
            self::assertTrue($definition->getOption($name)->isValueOptional(), $name);
        }
    }

    public function test_the_options_of_a_signature_are_kept_next_to_the_shared_ones(): void
    {
        $definition = (new AddPartitionsCommand)->getDefinition();

        self::assertSame('partitions:add', (new AddPartitionsCommand)->getName());
        self::assertTrue($definition->hasArgument('table'));
        self::assertSame(
            ['strategy', 'column', 'expression', 'prefix', 'connection', 'from', 'to', 'future'],
            array_slice(array_keys($definition->getOptions()), 0, 8)
        );
    }

    public function test_the_run_command_takes_no_table_options(): void
    {
        $definition = (new RunPartitionsCommand)->getDefinition();

        self::assertSame(['dry-run'], array_keys($definition->getOptions()));
    }

    public function test_a_command_declared_by_name_alone_is_built_with_the_table_options(): void
    {
        $command = new class extends PartitionCommand
        {
            protected $name = 'partitions:named';
        };

        self::assertSame('partitions:named', $command->getName());
        self::assertSame(self::TABLE_OPTIONS, $this->tableOptionsOf($command));
    }

    public function test_a_command_declared_by_a_signature_attribute_is_built_with_the_table_options(): void
    {
        if (! class_exists(Signature::class)) {
            self::markTestSkipped('The Signature attribute ships with Laravel 13.');
        }

        $command = new #[Signature('partitions:attributed {table} {--dry-run}')] class extends PartitionCommand {};

        self::assertSame('partitions:attributed', $command->getName());
        self::assertTrue($command->getDefinition()->hasArgument('table'));
        self::assertTrue($command->getDefinition()->hasOption('dry-run'));
        self::assertSame(self::TABLE_OPTIONS, $this->tableOptionsOf($command));
    }

    public function test_a_signature_cannot_turn_a_table_option_into_a_flag(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('An option named "strategy" already exists.');

        new class extends PartitionCommand
        {
            protected $signature = 'partitions:clash {--strategy}';
        };
    }
}

<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Support;

use Illuminate\Console\Command;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Yurkovskiy\Partitions\PartitionManager;

abstract class CommandTestCase extends TestCase
{
    /**
     * @template TCommand of Command
     *
     * @param  TCommand  $command
     * @return TCommand
     */
    protected function withManager(Command $command, PartitionManager $manager): Command
    {
        $container = new ConsoleContainer;
        $container->instance(PartitionManager::class, $manager);

        $command->setLaravel($container);

        return $command;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<int, string>  $inputs
     */
    protected function runCommand(
        Command $command,
        array $arguments = [],
        array $inputs = [],
        bool $interactive = true,
    ): CommandTester {
        if ($command->getLaravel() === null) {
            $command->setLaravel(new ConsoleContainer);
        }

        $tester = new CommandTester($command);
        $tester->setInputs($inputs);
        $tester->execute($arguments, ['interactive' => $interactive]);

        return $tester;
    }
}

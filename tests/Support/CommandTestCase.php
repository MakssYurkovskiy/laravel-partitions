<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Support;

use Illuminate\Console\Command;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

abstract class CommandTestCase extends TestCase
{
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
        $command->setLaravel(new ConsoleContainer);

        $tester = new CommandTester($command);
        $tester->setInputs($inputs);
        $tester->execute($arguments, ['interactive' => $interactive]);

        return $tester;
    }
}

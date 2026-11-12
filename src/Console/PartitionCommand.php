<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Yurkovskiy\Partitions\PartitionBuilder;
use Yurkovskiy\Partitions\PartitionManager;
use Yurkovskiy\Partitions\Schema\PartitionExpression;
use Yurkovskiy\Partitions\Schema\PartitionPeriod;
use Yurkovskiy\Partitions\Support\WholeNumber;

abstract class PartitionCommand extends Command
{
    protected PartitionManager $manager;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->manager = $this->laravel->make(PartitionManager::class);

        return parent::execute($input, $output);
    }

    protected function configure(): void
    {
        parent::configure();

        $this->getDefinition()->addOptions($this->tableOptions());
    }

    /**
     * @return list<InputOption>
     */
    protected function tableOptions(): array
    {
        $periods = array_map(
            fn (PartitionPeriod $period) => sprintf('%s (%s)', $period->value, implode(', ', $period->aliases())),
            PartitionPeriod::cases()
        );

        return [
            new InputOption('strategy', null, InputOption::VALUE_OPTIONAL, sprintf(
                'Partition period: %s, or a strategy class name',
                implode(', ', $periods)
            )),
            new InputOption('column', null, InputOption::VALUE_OPTIONAL, 'Date column used for partitioning'),
            new InputOption('expression', null, InputOption::VALUE_OPTIONAL, sprintf(
                'Partitioning expression: %s',
                Arr::join(PartitionExpression::values(), ', ', ' or ')
            )),
            new InputOption('prefix', null, InputOption::VALUE_OPTIONAL, 'Partition name prefix'),
            new InputOption('connection', null, InputOption::VALUE_OPTIONAL, 'Database connection'),
        ];
    }

    protected function builderFor(string $table): PartitionBuilder
    {
        $builder = $this->manager->isConfigured($table)
            ? $this->manager->for($table)
            : $this->manager->table($table)->daily();

        if (($strategy = $this->stringOption('strategy')) !== null) {
            $builder->strategy($strategy);
        }

        if (($column = $this->stringOption('column')) !== null) {
            $builder->column($column);
        }

        if (($connection = $this->stringOption('connection')) !== null) {
            $builder->connection($connection);
        }

        if (($expression = $this->stringOption('expression')) !== null) {
            $builder->expression($expression);
        }

        if (($prefix = $this->stringOption('prefix')) !== null) {
            $builder->prefix($prefix);
        }

        if (($tablespace = $this->stringOption('tablespace')) !== null) {
            $builder->tablespace($tablespace);
        }

        if (($maxGaps = $this->integerOption('max-gaps')) !== null) {
            $builder->maxGaps($maxGaps);
        }

        if ($this->hasOption('dry-run') && $this->option('dry-run')) {
            $builder->dryRun();
        }

        return $builder;
    }

    protected function tableArgument(): string
    {
        return $this->stringArgument('table') ?? '';
    }

    protected function stringArgument(string $key): ?string
    {
        $value = $this->argument($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function stringOption(string $key): ?string
    {
        if (! $this->hasOption($key)) {
            return null;
        }

        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function integerOption(string $key): ?int
    {
        $value = $this->stringOption($key);

        if ($value === null) {
            return null;
        }

        $number = WholeNumber::parse($value);

        if ($number === null) {
            $this->fail(sprintf('The --%s option takes a whole number, zero or above; [%s] given.', $key, $value));
        }

        return $number;
    }

    /**
     * @param  Collection<int, string>  $statements
     */
    protected function printStatements(Collection $statements, PartitionBuilder $builder): void
    {
        foreach ($statements as $sql) {
            $this->line('<fg=gray>'.$sql.';</>');
        }

        if ($builder->isDryRun()) {
            $this->comment('Dry run — nothing was executed.');
        }
    }

    protected function confirmed(PartitionBuilder $builder, string $question): bool
    {
        if ($this->option('force') || $builder->isDryRun() || ! $this->input->isInteractive()) {
            return true;
        }

        return $this->confirm($question, false);
    }

    protected function unpartitioned(PartitionBuilder $builder): int
    {
        if ($builder->tableInfo() === null) {
            $this->error("Table [{$builder->getTable()}] does not exist.");

            return self::FAILURE;
        }

        $this->warn("Table [{$builder->getTable()}] is not partitioned.");

        return self::SUCCESS;
    }

    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), $power === 0 ? 0 : 1).' '.$units[$power];
    }
}

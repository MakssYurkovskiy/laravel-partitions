<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Console;

use Throwable;
use Yurkovskiy\Partitions\Exceptions\UnknownTableException;
use Yurkovskiy\Partitions\PartitionBuilder;

class RunPartitionsCommand extends PartitionCommand
{
    protected $signature = 'partitions:run
        {table? : Limit the run to a single table from config/partitions.php}
        {--dry-run : Print the SQL without running it}';

    protected $description = 'Add upcoming partitions and prune expired ones for every configured table';

    protected function tableOptions(): array
    {
        return [];
    }

    public function handle(): int
    {
        $keys = $this->keys();

        if ($keys === []) {
            $this->warn('No tables configured in config/partitions.php.');

            return self::SUCCESS;
        }

        $rows = [];
        $failed = false;

        foreach ($keys as $key) {
            $table = $this->configuredTable($key);

            try {
                $builder = $this->manager->for($key);
                $table = $builder->getTable();

                if ($this->option('dry-run')) {
                    $builder->dryRun();
                }

                $rows[] = $this->maintain($key, $builder);
            } catch (Throwable $e) {
                $failed = true;
                $rows[] = [$key, $table, '—', '—', '<fg=red>'.$e->getMessage().'</>'];
            }
        }

        $this->table(['Key', 'Table', 'Added', 'Dropped', 'Statements'], $rows);

        if ($this->option('dry-run')) {
            $this->comment('Dry run — nothing was executed.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    protected function maintain(string $key, PartitionBuilder $builder): array
    {
        if (! $builder->isPartitioned()) {
            return [$key, $builder->getTable(), '—', '—', '<fg=yellow>not partitioned</>'];
        }

        $plan = $builder->plan();
        $added = $plan->pending->count();
        $deferred = $plan->deferred->count();
        $outdated = $builder->outdated()->count();
        $result = $builder->maintain($plan);

        return [
            $key,
            $builder->getTable(),
            $deferred === 0 ? (string) $added : sprintf('<fg=yellow>%d (+%d left)</>', $added, $deferred),
            (string) $outdated,
            (string) $result['added']->merge($result['dropped'])->count(),
        ];
    }

    /**
     * @return list<string>
     */
    protected function keys(): array
    {
        $table = $this->stringArgument('table');

        if ($table === null) {
            return $this->manager->configuredKeys();
        }

        if (! $this->manager->isConfigured($table)) {
            throw new UnknownTableException($table);
        }

        return [$table];
    }

    protected function configuredTable(string $key): string
    {
        $table = data_get($this->manager->config(), ['tables', $key, 'table']) ?? $key;

        return is_string($table) && $table !== '' ? $table : '—';
    }
}

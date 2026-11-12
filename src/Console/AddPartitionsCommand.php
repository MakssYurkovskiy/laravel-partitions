<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Console;

use Yurkovskiy\Partitions\PartitionBuilder;
use Yurkovskiy\Partitions\Schema\PendingPartition;

class AddPartitionsCommand extends PartitionCommand
{
    protected $signature = 'partitions:add
        {table : Table name, or a key from config/partitions.php}
        {--from= : First period to cover (YYYY-MM-DD); by default the one after the last existing partition, or today when the table has none}
        {--to= : Last period to cover (YYYY-MM-DD)}
        {--future= : Periods to keep created ahead of today when --to is omitted, counted from today rather than from the last partition}
        {--tablespace= : Tablespace for the created partitions}
        {--chunk= : Partitions added per ALTER statement (0 = all in one statement)}
        {--max-gaps= : Periods the table skipped before today that this run fills, oldest first (0 = no limit)}
        {--create : Partition a table that is not partitioned yet; without it such a table is refused}
        {--force : Do not ask for confirmation before rebuilding the table}
        {--dry-run : Print the SQL without running it}';

    protected $description = 'Add the missing partitions to a table';

    public function handle(): int
    {
        $builder = $this->builderFor($this->tableArgument());

        if (($from = $this->stringOption('from')) !== null) {
            $builder->from($from);
        }

        if (($to = $this->stringOption('to')) !== null) {
            $builder->to($to);
        }

        if (($future = $this->integerOption('future')) !== null) {
            $builder->future($future);
        }

        if (($chunk = $this->integerOption('chunk')) !== null) {
            $builder->syncChunk($chunk);
        }

        if ($this->option('create')) {
            return $this->create($builder);
        }

        if (! $builder->isPartitioned()) {
            $this->error("Table [{$builder->getTable()}] is not partitioned yet. Pass --create to partition it.");
            $this->line('  ALTER TABLE ... PARTITION BY rebuilds the whole table and holds it locked until it finishes.');

            return self::FAILURE;
        }

        $plan = $builder->plan();
        $pending = $plan->pending;
        $deferred = $plan->deferred;

        if ($pending->isEmpty()) {
            $this->info("All partitions for [{$builder->getTable()}] already exist.");

            return self::SUCCESS;
        }

        $this->line($deferred->isEmpty()
            ? sprintf('Adding %d partitions to [%s]:', $pending->count(), $builder->getTable())
            : sprintf(
                'Adding %d of %d missing partitions to [%s]:',
                $pending->count(),
                $pending->count() + $deferred->count(),
                $builder->getTable()
            ));
        $pending->each(fn (PendingPartition $partition) => $this->line("  → {$partition->name} (< {$partition->boundary})"));

        if (($gap = $builder->gap()) !== null) {
            $this->warn($gap->message());
        }

        $this->printStatements($builder->sync($plan), $builder);

        if ($deferred->isNotEmpty()) {
            $this->warn(sprintf(
                '%d partitions are left for the next run (%s first): the table skipped more periods than '
                .'one run fills. Run the command again to add them — '
                .'each run reorganises everything still sitting in the MAXVALUE partition.',
                $deferred->count(),
                $deferred->first()->name
            ));
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    protected function create(PartitionBuilder $builder): int
    {
        if ($builder->isPartitioned()) {
            $this->error("Table [{$builder->getTable()}] is already partitioned.");

            return self::FAILURE;
        }

        $planned = $builder->missing();

        $this->warn("Table [{$builder->getTable()}] is not partitioned yet.");
        $this->line('  ALTER TABLE ... PARTITION BY rebuilds the whole table and holds it locked until it finishes.');

        if (($footprint = $this->footprint($builder)) !== null) {
            $this->line('  '.$footprint);
        }

        $this->line(sprintf('Partitioning [%s] into %d partitions:', $builder->getTable(), $planned->count()));
        $planned->each(fn (PendingPartition $pending) => $this->line("  → {$pending->name} (< {$pending->boundary})"));

        if (! $this->confirmed($builder, "Rebuild [{$builder->getTable()}] and partition it?")) {
            $this->comment('Aborted.');

            return self::FAILURE;
        }

        $this->printStatements($builder->create(), $builder);
        $this->info('Done.');

        return self::SUCCESS;
    }

    protected function footprint(PartitionBuilder $builder): ?string
    {
        $table = $builder->tableInfo();

        if ($table === null) {
            return null;
        }

        return sprintf(
            '~%s rows, %s on disk have to be rewritten.',
            number_format($table->rows),
            $this->formatBytes($table->totalLength())
        );
    }
}

<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Console;

use Illuminate\Support\Collection;
use Yurkovskiy\Partitions\PartitionBuilder;
use Yurkovskiy\Partitions\Schema\PartitionInfo;

class DropPartitionsCommand extends PartitionCommand
{
    protected $signature = 'partitions:drop
        {table : Table name, or a key from config/partitions.php}
        {--before= : Drop every partition older than this date (YYYY-MM-DD)}
        {--from= : Drop the partitions covering this range — start, inclusive; needs --to}
        {--to= : Drop the partitions covering this range — end, inclusive; needs --from}
        {--retain= : Keep N complete periods before the current one, drop older}
        {--chunk= : Partitions dropped per ALTER statement (0 = all in one statement)}
        {--force : Do not ask for confirmation}
        {--dry-run : Print the SQL without running it}';

    protected $description = 'Drop partitions — the data they hold is deleted';

    public function handle(): int
    {
        if (($this->stringOption('from') === null) !== ($this->stringOption('to') === null)) {
            $this->fail('The --from and --to options go together: pass both ends of the range to drop.');
        }

        $builder = $this->builderFor($this->tableArgument());

        if (($retain = $this->integerOption('retain')) !== null) {
            $builder->retain($retain);
        }

        if (($chunk = $this->integerOption('chunk')) !== null) {
            $builder->chunk($chunk);
        }

        if (! $builder->isPartitioned()) {
            $this->warn("Table [{$builder->getTable()}] is not partitioned.");

            return self::SUCCESS;
        }

        $targets = $this->resolveTargets($builder);

        if ($targets->isEmpty()) {
            $this->info("No partitions of [{$builder->getTable()}] match the given range.");

            return self::SUCCESS;
        }

        $this->line(sprintf('Dropping %d partitions from [%s]:', $targets->count(), $builder->getTable()));
        $this->line('  '.$targets->map(fn (PartitionInfo $partition) => $partition->name)->implode(', '));
        $this->line(sprintf(
            '  ~%s rows, %s on disk will be deleted.',
            number_format((int) $targets->sum(fn (PartitionInfo $partition) => $partition->rows)),
            $this->formatBytes((int) $targets->sum(fn (PartitionInfo $partition) => $partition->totalLength()))
        ));

        if (! $this->confirmed($builder, 'Drop these partitions?')) {
            $this->comment('Aborted.');

            return self::FAILURE;
        }

        $this->printStatements($builder->drop($targets), $builder);
        $this->info('Done.');

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, PartitionInfo>
     */
    protected function resolveTargets(PartitionBuilder $builder): Collection
    {
        if (($before = $this->stringOption('before')) !== null) {
            return $builder->outdated($before);
        }

        $from = $this->stringOption('from');
        $to = $this->stringOption('to');

        if ($from !== null && $to !== null) {
            return $builder->inRange($from, $to);
        }

        return $builder->outdated();
    }
}

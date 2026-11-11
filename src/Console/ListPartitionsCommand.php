<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Console;

use Yurkovskiy\Partitions\Schema\PartitionInfo;
use Yurkovskiy\Partitions\Schema\PendingPartition;

class ListPartitionsCommand extends PartitionCommand
{
    protected $signature = 'partitions:list
        {table : Table name, or a key from config/partitions.php}
        {--missing : Show the partitions that are still missing}';

    protected $description = 'Show the partitions of a table';

    public function handle(): int
    {
        $builder = $this->builderFor($this->tableArgument());
        $partitions = $builder->existing();

        if ($partitions->isEmpty()) {
            $this->warn("Table [{$builder->getTable()}] is not partitioned.");

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'Partition', 'Less than', 'Covers', 'Rows (est.)', 'Size'],
            $partitions->map(fn (PartitionInfo $partition) => [
                $partition->ordinal,
                $partition->name,
                $partition->isMax() ? 'MAXVALUE' : (string) $partition->description,
                $builder->getStrategy()->parse($partition->name)?->toDateString() ?? '—',
                number_format($partition->rows),
                $this->formatBytes($partition->totalLength()),
            ])->all()
        );

        $this->line(sprintf(
            '%d partitions, ~%s rows, %s on disk.',
            $partitions->count(),
            number_format((int) $partitions->sum(fn (PartitionInfo $partition) => $partition->rows)),
            $this->formatBytes((int) $partitions->sum(fn (PartitionInfo $partition) => $partition->totalLength()))
        ));

        if ($this->option('missing')) {
            $missing = $builder->missing();

            $missing->isEmpty()
                ? $this->info('No missing partitions for the configured range.')
                : $this->table(
                    ['Missing', 'Covers', 'Less than'],
                    $missing->map(fn (PendingPartition $pending) => [
                        $pending->name,
                        $pending->start->toDateString(),
                        $pending->boundary,
                    ])->all()
                );
        }

        return self::SUCCESS;
    }
}

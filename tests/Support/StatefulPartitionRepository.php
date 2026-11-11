<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Support;

use Yurkovskiy\Partitions\Schema\PartitionInfo;

class StatefulPartitionRepository extends FakePartitionRepository
{
    public function statement(?string $connection, string $sql): bool
    {
        parent::statement($connection, $sql);

        if (preg_match('/DROP PARTITION (.+)$/', $sql, $match) === 1) {
            preg_match_all('/`([^`]+)`/', $match[1], $names);

            $this->partitions = array_values(array_filter(
                $this->partitions,
                fn (PartitionInfo $partition) => ! in_array($partition->name, $names[1], true),
            ));

            return true;
        }

        if (preg_match('/REORGANIZE PARTITION|ADD PARTITION/', $sql) !== 1) {
            return true;
        }

        preg_match_all('/PARTITION `([^`]+)` VALUES LESS THAN (?:\((.+)\)|(MAXVALUE))/', $sql, $definitions, PREG_SET_ORDER);

        $sample = $this->partitions[0] ?? null;
        $added = array_map(fn (array $definition) => $definition[1], $definitions);

        $this->partitions = array_values(array_filter(
            $this->partitions,
            fn (PartitionInfo $partition) => ! in_array($partition->name, $added, true),
        ));

        foreach ($definitions as $definition) {
            $this->partitions[] = new PartitionInfo(
                name: $definition[1],
                description: $definition[3] ?? $definition[2],
                ordinal: count($this->partitions) + 1,
                method: $sample?->method,
                expression: $sample?->expression,
            );
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(fn (PartitionInfo $partition) => $partition->name, $this->partitions);
    }
}

<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Schema;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, mixed>
 */
final class PartitionGap implements Arrayable
{
    public function __construct(
        public readonly string $table,
        public readonly string $from,
        public readonly string $last,
        public readonly string $firstSkipped,
        public readonly string $lastSkipped,
        public readonly int $skipped,
        public readonly string $holder,
        public readonly string $continueAt,
    ) {}

    public function message(): string
    {
        $single = $this->skipped === 1;

        return sprintf(
            'The start date %s leaves %s of table [%s] without a partition of %s own — %s, after [%s]. '
            .'%s rows land in [%s] and are dropped together with it. Start at %s, or leave the start '
            .'date out, to give each period its partition.',
            $this->from,
            $single ? '1 period' : $this->skipped.' periods',
            $this->table,
            $single ? 'its' : 'their',
            $single ? "[$this->firstSkipped]" : "[$this->firstSkipped] to [$this->lastSkipped]",
            $this->last,
            $single ? 'Its' : 'Their',
            $this->holder,
            $this->continueAt
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'from' => $this->from,
            'last' => $this->last,
            'first_skipped' => $this->firstSkipped,
            'last_skipped' => $this->lastSkipped,
            'skipped' => $this->skipped,
            'holder' => $this->holder,
            'continue_at' => $this->continueAt,
        ];
    }
}

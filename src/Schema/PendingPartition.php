<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Schema;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, mixed>
 */
final class PendingPartition implements Arrayable
{
    public function __construct(
        public readonly string $name,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $upperBound,
        public readonly string $boundary,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'start' => $this->start->toDateString(),
            'upper_bound' => $this->upperBound->toDateString(),
            'boundary' => $this->boundary,
        ];
    }
}

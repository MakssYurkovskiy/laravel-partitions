<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Contracts;

use Carbon\CarbonImmutable;
use DateTimeInterface;

interface PartitionStrategy
{
    public function name(): string;

    public function prefix(): string;

    public function withPrefix(string $prefix): static;

    public function start(DateTimeInterface $date): CarbonImmutable;

    public function next(DateTimeInterface $date): CarbonImmutable;

    public function partitionName(DateTimeInterface $date): string;

    public function parse(string $partitionName): ?CarbonImmutable;

    /**
     * @return iterable<int, CarbonImmutable>
     */
    public function periods(DateTimeInterface $from, DateTimeInterface $to): iterable;

    public function shift(DateTimeInterface $date, int $periods): CarbonImmutable;
}

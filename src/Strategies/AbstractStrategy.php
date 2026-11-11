<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Strategies;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;
use Yurkovskiy\Partitions\Contracts\PartitionStrategy;
use Yurkovskiy\Partitions\Exceptions\InvalidIdentifierException;
use Yurkovskiy\Partitions\Schema\PartitionPeriod;
use Yurkovskiy\Partitions\Support\Identifier;

abstract class AbstractStrategy implements PartitionStrategy
{
    public function __construct(protected string $prefix = 'p') {}

    abstract protected function format(): string;

    public function name(): string
    {
        $period = PartitionPeriod::tryFromStrategy($this);

        return $period === null ? static::class : $period->value;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function withPrefix(string $prefix): static
    {
        if ($prefix === $this->prefix) {
            return $this;
        }

        $clone = clone $this;
        $clone->prefix = $prefix;

        return $clone;
    }

    /**
     * @throws InvalidIdentifierException
     */
    public function partitionName(DateTimeInterface $date): string
    {
        $name = $this->prefix.$this->start($date)->format($this->format());

        if (! Identifier::isValid($name)) {
            throw new InvalidIdentifierException($name, $this);
        }

        return $name;
    }

    public function parse(string $partitionName): ?CarbonImmutable
    {
        $stamp = $this->stripPrefix($partitionName);

        if ($stamp === null || ! Identifier::isValid($partitionName)) {
            return null;
        }

        return $this->decode($stamp);
    }

    public function periods(DateTimeInterface $from, DateTimeInterface $to): iterable
    {
        $period = $this->start($from);
        $last = $this->start($to);

        while ($period->lessThanOrEqualTo($last)) {
            yield $period;

            $period = $this->next($period);
        }
    }

    public function shift(DateTimeInterface $date, int $periods): CarbonImmutable
    {
        $result = $this->start($date);

        for ($i = 0; $i < abs($periods); $i++) {
            $result = $periods > 0
                ? $this->next($result)
                : $this->previous($result);
        }

        return $result;
    }

    protected function previous(CarbonImmutable $period): CarbonImmutable
    {
        return $this->start($period->subDay());
    }

    protected function decode(string $stamp): ?CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!'.$this->format(), $stamp);
        } catch (Throwable) {
            return null;
        }

        if (! $date instanceof CarbonImmutable) {
            return null;
        }

        $start = $this->start($date);

        return $start->format($this->format()) === $stamp ? $start : null;
    }

    protected function stripPrefix(string $partitionName): ?string
    {
        if ($this->prefix !== '' && ! str_starts_with($partitionName, $this->prefix)) {
            return null;
        }

        return substr($partitionName, strlen($this->prefix));
    }
}

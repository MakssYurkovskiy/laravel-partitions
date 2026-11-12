<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Schema;

use DateTimeInterface;
use DateTimeZone;
use Yurkovskiy\Partitions\Contracts\PartitionStrategy;
use Yurkovskiy\Partitions\Exceptions\AlreadyPartitionedException;
use Yurkovskiy\Partitions\Exceptions\MissingStrategyException;
use Yurkovskiy\Partitions\Exceptions\TimezoneMismatchException;
use Yurkovskiy\Partitions\PartitionBuilder;

final class PartitionDefinition
{
    private bool $replaceExisting = false;

    public function __construct(private readonly PartitionBuilder $builder) {}

    public function daily(): self
    {
        return $this->strategy(PartitionPeriod::Daily);
    }

    public function weekly(): self
    {
        return $this->strategy(PartitionPeriod::Weekly);
    }

    public function monthly(): self
    {
        return $this->strategy(PartitionPeriod::Monthly);
    }

    public function yearly(): self
    {
        return $this->strategy(PartitionPeriod::Yearly);
    }

    public function strategy(PartitionStrategy|PartitionPeriod|string $strategy): self
    {
        $this->builder->strategy($strategy);

        return $this;
    }

    public function prefix(string $prefix): self
    {
        $this->builder->prefix($prefix);

        return $this;
    }

    public function usingToDays(): self
    {
        return $this->expression(PartitionExpression::ToDays);
    }

    public function usingUnixTimestamp(): self
    {
        return $this->expression(PartitionExpression::UnixTimestamp);
    }

    public function usingRangeColumns(): self
    {
        return $this->expression(PartitionExpression::Columns);
    }

    public function expression(PartitionExpression|string $expression): self
    {
        $this->builder->expression($expression);

        return $this;
    }

    public function timezone(DateTimeZone|string|null $timezone): self
    {
        $this->builder->timezone($timezone);

        return $this;
    }

    public function verifyTimezone(bool $verify = true): self
    {
        $this->builder->verifyTimezone($verify);

        return $this;
    }

    public function from(DateTimeInterface|string $date): self
    {
        $this->builder->from($date);

        return $this;
    }

    public function to(DateTimeInterface|string $date): self
    {
        $this->builder->to($date);

        return $this;
    }

    public function between(DateTimeInterface|string $from, DateTimeInterface|string $to): self
    {
        $this->builder->between($from, $to);

        return $this;
    }

    public function future(int $periods): self
    {
        $this->builder->future($periods);

        return $this;
    }

    public function withMaxPartition(bool|string $max = true): self
    {
        $this->builder->withMaxPartition($max);

        return $this;
    }

    public function withoutMaxPartition(): self
    {
        $this->builder->withoutMaxPartition();

        return $this;
    }

    public function tablespace(?string $tablespace): self
    {
        $this->builder->tablespace($tablespace);

        return $this;
    }

    public function replacePartitioning(bool $replace = true): self
    {
        $this->replaceExisting = $replace;

        return $this;
    }

    /**
     * @param  bool  $creating  whether the blueprint also creates the table
     *
     * @throws AlreadyPartitionedException
     * @throws TimezoneMismatchException
     */
    public function assertApplicable(bool $creating): void
    {
        if (! $creating && ! $this->replaceExisting && $this->builder->isPartitioned()) {
            throw AlreadyPartitionedException::forMigration($this->builder->getTable());
        }

        $this->builder->assertTimezoneMatches();
    }

    /**
     * @throws MissingStrategyException when no strategy was declared
     */
    public function clause(): string
    {
        return $this->builder->partitionByClause();
    }

    public function builder(): PartitionBuilder
    {
        return $this->builder;
    }
}

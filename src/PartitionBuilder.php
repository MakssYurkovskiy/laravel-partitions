<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Throwable;
use Yurkovskiy\Partitions\Contracts\PartitionRepository;
use Yurkovskiy\Partitions\Contracts\PartitionStrategy;
use Yurkovskiy\Partitions\Events\PartitioningRemoved;
use Yurkovskiy\Partitions\Events\PartitionsCreated;
use Yurkovskiy\Partitions\Events\PartitionsDropped;
use Yurkovskiy\Partitions\Events\PartitionsTruncated;
use Yurkovskiy\Partitions\Exceptions\AlreadyPartitionedException;
use Yurkovskiy\Partitions\Exceptions\DropsEveryPartitionException;
use Yurkovskiy\Partitions\Exceptions\ExpressionMismatchException;
use Yurkovskiy\Partitions\Exceptions\InvalidDateException;
use Yurkovskiy\Partitions\Exceptions\InvalidRangeException;
use Yurkovskiy\Partitions\Exceptions\InvalidTimezoneException;
use Yurkovskiy\Partitions\Exceptions\MaxPartitionConflictException;
use Yurkovskiy\Partitions\Exceptions\MissingStrategyException;
use Yurkovskiy\Partitions\Exceptions\MissingTableException;
use Yurkovskiy\Partitions\Exceptions\NotPartitionedException;
use Yurkovskiy\Partitions\Exceptions\PartitionException;
use Yurkovskiy\Partitions\Exceptions\StrategyMismatchException;
use Yurkovskiy\Partitions\Exceptions\TimezoneMismatchException;
use Yurkovskiy\Partitions\Exceptions\TooManyPartitionsException;
use Yurkovskiy\Partitions\Exceptions\UnknownStrategyException;
use Yurkovskiy\Partitions\Schema\PartitionExpression;
use Yurkovskiy\Partitions\Schema\PartitionGap;
use Yurkovskiy\Partitions\Schema\PartitionGrammar;
use Yurkovskiy\Partitions\Schema\PartitionInfo;
use Yurkovskiy\Partitions\Schema\PartitionPeriod;
use Yurkovskiy\Partitions\Schema\PartitionPlan;
use Yurkovskiy\Partitions\Schema\PendingPartition;
use Yurkovskiy\Partitions\Schema\TableInfo;
use Yurkovskiy\Partitions\Support\Identifier;

class PartitionBuilder
{
    protected const FRAME = 'UTC';

    protected const MAX_PARTITIONS = 8192;

    protected const EARLIEST_YEAR = 1000;

    protected const RETENTION_STRIDE = 512;

    protected string $table = '';

    protected string $column = 'created_at';

    protected ?PartitionStrategy $strategy = null;

    protected PartitionExpression $expression = PartitionExpression::ToDays;

    protected PartitionGrammar $grammar;

    protected ?string $connection = null;

    protected ?string $modelConnection = null;

    protected ?string $defaultConnection = null;

    protected ?CarbonImmutable $from = null;

    protected ?CarbonImmutable $to = null;

    protected int $future = 7;

    protected ?int $retain = null;

    protected string $maxPartition = 'pMax';

    protected bool $withMaxPartition = true;

    protected ?int $chunk = 1;

    protected ?int $syncChunk = null;

    protected bool $fillGaps = true;

    protected ?int $maxGaps = 12;

    protected bool $dryRun = false;

    protected string $prefix = 'p';

    protected ?DateTimeZone $timezone = null;

    protected ?DateTimeZone $resolvedTimezone = null;

    protected bool $verifyTimezone = false;

    /**
     * @var array<string, true>
     */
    protected array $verifiedDatetimes = [];

    /**
     * @var Collection<int, PartitionInfo>|null
     */
    protected ?Collection $existingPartitions = null;

    protected TableInfo|false|null $tableInfoCache = null;

    protected ?string $prefixedTable = null;

    public function __construct(
        protected PartitionRepository $repository,
        protected ?Dispatcher $events = null,
        protected ?LoggerInterface $logger = null,
    ) {
        $this->grammar = new PartitionGrammar;
    }

    public function table(Model|string $table): static
    {
        if (is_string($table) && is_subclass_of($table, Model::class)) {
            $table = new $table;
        }

        $this->modelConnection = $table instanceof Model ? $table->getConnectionName() : null;
        $this->table = $table instanceof Model ? $table->getTable() : $table;

        return $this->connectionChanged();
    }

    public function connection(?string $connection): static
    {
        $this->connection = $connection === '' ? null : $connection;

        return $this->connectionChanged();
    }

    public function defaultConnection(?string $connection): static
    {
        $this->defaultConnection = $connection === '' ? null : $connection;

        return $this->connectionChanged();
    }

    public function column(string $column): static
    {
        $this->column = $column;

        return $this;
    }

    public function daily(?string $column = null): static
    {
        return $this->strategy(PartitionPeriod::Daily, $column);
    }

    public function weekly(?string $column = null): static
    {
        return $this->strategy(PartitionPeriod::Weekly, $column);
    }

    public function monthly(?string $column = null): static
    {
        return $this->strategy(PartitionPeriod::Monthly, $column);
    }

    public function yearly(?string $column = null): static
    {
        return $this->strategy(PartitionPeriod::Yearly, $column);
    }

    /**
     * @throws UnknownStrategyException
     */
    public function strategy(PartitionStrategy|PartitionPeriod|string $strategy, ?string $column = null): static
    {
        $this->strategy = $this->resolveStrategy($strategy)->withPrefix($this->prefix);

        if ($column !== null) {
            $this->column($column);
        }

        return $this;
    }

    public function prefix(string $prefix): static
    {
        $this->prefix = $prefix;
        $this->strategy = $this->strategy?->withPrefix($prefix);

        return $this;
    }

    public function usingToDays(): static
    {
        return $this->expression(PartitionExpression::ToDays);
    }

    public function usingUnixTimestamp(): static
    {
        return $this->expression(PartitionExpression::UnixTimestamp);
    }

    public function usingRangeColumns(): static
    {
        return $this->expression(PartitionExpression::Columns);
    }

    public function expression(PartitionExpression|string $expression): static
    {
        $this->expression = PartitionExpression::make($expression);

        return $this;
    }

    /**
     * @throws InvalidTimezoneException
     */
    public function timezone(DateTimeZone|string|null $timezone): static
    {
        $this->timezone = $timezone === null || $timezone === '' ? null : $this->toTimezone($timezone);
        $this->resolvedTimezone = null;
        $this->verifiedDatetimes = [];

        return $this;
    }

    public function verifyTimezone(bool $verify = true): static
    {
        $this->verifyTimezone = $verify;

        return $this;
    }

    public function getTimezone(): DateTimeZone
    {
        return $this->resolvedTimezone ??= $this->timezone
            ?? $this->connectionTimezone()
            ?? new DateTimeZone(date_default_timezone_get());
    }

    public function tablespace(?string $tablespace): static
    {
        $this->grammar = $this->grammar->withTablespace($tablespace);

        return $this;
    }

    public function withMaxPartition(bool|string $max = true): static
    {
        $this->withMaxPartition = $max !== false;

        if (is_string($max)) {
            $this->maxPartition = $max;
        }

        return $this;
    }

    public function withoutMaxPartition(): static
    {
        $this->withMaxPartition = false;

        return $this;
    }

    /**
     * @throws InvalidDateException
     */
    public function from(DateTimeInterface|string $date): static
    {
        $this->from = $this->toDate($date);

        return $this;
    }

    /**
     * @throws InvalidDateException
     */
    public function to(DateTimeInterface|string $date): static
    {
        $this->to = $this->toDate($date);

        return $this;
    }

    /**
     * @throws InvalidDateException
     */
    public function between(DateTimeInterface|string $from, DateTimeInterface|string $to): static
    {
        return $this->from($from)->to($to);
    }

    public function future(int $periods): static
    {
        $this->future = max(0, $periods);

        return $this;
    }

    public function retain(?int $periods): static
    {
        $this->retain = $periods === null ? null : max(0, $periods);

        return $this;
    }

    public function chunk(?int $size): static
    {
        $this->chunk = $size === null || $size < 1 ? null : $size;

        return $this;
    }

    public function syncChunk(?int $size): static
    {
        $this->syncChunk = $size === null || $size < 1 ? null : $size;

        return $this;
    }

    public function maxGaps(?int $gaps): static
    {
        $this->maxGaps = $gaps === null || $gaps < 1 ? null : $gaps;

        return $this;
    }

    public function fillGaps(bool $fillGaps = true): static
    {
        $this->fillGaps = $fillGaps;

        return $this;
    }

    public function dryRun(bool $dryRun = true): static
    {
        $this->dryRun = $dryRun;

        return $this;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function getTable(): string
    {
        if ($this->table === '') {
            throw new MissingTableException;
        }

        return $this->table;
    }

    protected function prefixedTable(): string
    {
        return $this->prefixedTable ??= Identifier::prefixTable(
            $this->getTable(),
            $this->repository->tablePrefix($this->getConnection())
        );
    }

    public function getColumn(): string
    {
        return $this->column;
    }

    public function getConnection(): ?string
    {
        return $this->connection ?? $this->modelConnection ?? $this->defaultConnection;
    }

    public function getStrategy(): PartitionStrategy
    {
        return $this->strategy ?? throw new MissingStrategyException($this->table);
    }

    public function getExpression(): PartitionExpression
    {
        return $this->expression;
    }

    /**
     * @return Collection<int, PartitionInfo>
     */
    public function existing(): Collection
    {
        return $this->existingPartitions ??= $this->repository->partitions($this->getConnection(), $this->prefixedTable());
    }

    public function tableInfo(): ?TableInfo
    {
        if ($this->tableInfoCache === null) {
            $this->tableInfoCache = $this->repository->tableInfo($this->getConnection(), $this->prefixedTable()) ?? false;
        }

        return $this->tableInfoCache === false ? null : $this->tableInfoCache;
    }

    public function refresh(): static
    {
        $this->existingPartitions = null;
        $this->tableInfoCache = null;
        $this->prefixedTable = null;
        $this->resolvedTimezone = null;

        return $this;
    }

    /**
     * @return Collection<int, string>
     */
    public function existingNames(): Collection
    {
        return $this->existing()->map(fn (PartitionInfo $partition) => $partition->name)->values();
    }

    public function isPartitioned(): bool
    {
        return $this->existing()->isNotEmpty();
    }

    public function hasMaxPartition(): bool
    {
        return $this->existing()->contains(fn (PartitionInfo $partition) => $partition->isMax());
    }

    /**
     * @throws InvalidDateException
     */
    public function partitionNameFor(DateTimeInterface|string $date): string
    {
        return $this->getStrategy()->partitionName($this->toDate($date));
    }

    /**
     * @throws InvalidDateException
     */
    public function has(DateTimeInterface|string $date): bool
    {
        return $this->existingNames()->contains($this->partitionNameFor($date));
    }

    /**
     * @return Collection<int, PendingPartition>
     */
    public function missing(): Collection
    {
        $existing = $this->existing();

        $this->assertMatchesExisting($existing);
        $this->assertPeriodMatchesExisting($existing);

        $floor = $this->highestBoundary($existing);

        return $this->absent($this->plannedPartitions($this->resolveFrom($floor)), $existing, $floor);
    }

    public function plan(): PartitionPlan
    {
        $missing = $this->missing();

        if ($this->maxGaps === null || $this->skipped($missing) <= $this->maxGaps) {
            return new PartitionPlan($missing, collect());
        }

        return new PartitionPlan(
            $missing->take($this->maxGaps)->values(),
            $missing->slice($this->maxGaps)->values(),
        );
    }

    /**
     * @return Collection<int, PendingPartition>
     */
    public function pending(): Collection
    {
        return $this->plan()->pending;
    }

    /**
     * @return Collection<int, PendingPartition>
     */
    public function deferred(): Collection
    {
        return $this->plan()->deferred;
    }

    public function gap(): ?PartitionGap
    {
        $last = $this->lastBounded($this->existing());
        $floor = $last === null ? null : $this->boundaryOf($last);

        if ($this->from === null || ! $this->fillGaps || $last === null || $floor === null) {
            return null;
        }

        $strategy = $this->getStrategy();
        $start = $strategy->start($this->from);
        $continueAt = $strategy->start($this->dateOf($floor));
        $lastSkipped = $continueAt;
        $skipped = 0;

        for ($period = $continueAt; $period->format('Y-m-d') < $start->format('Y-m-d'); $period = $strategy->next($period)) {
            $lastSkipped = $period;
            $skipped++;
        }

        if ($skipped === 0) {
            return null;
        }

        return new PartitionGap(
            $this->getTable(),
            $this->from->toDateString(),
            $last->name,
            $strategy->partitionName($continueAt),
            $strategy->partitionName($lastSkipped),
            $skipped,
            $strategy->partitionName($start),
            $continueAt->toDateString()
        );
    }

    /**
     * @return Collection<int, PartitionInfo>
     *
     * @throws PartitionException
     */
    public function inRange(DateTimeInterface|string $from, DateTimeInterface|string $to): Collection
    {
        $start = $this->getStrategy()->start($this->toDate($from));
        $end = $this->getStrategy()->start($this->toDate($to));

        $existing = $this->existing();

        $this->assertMatchesExisting($existing);
        $this->assertPeriodMatchesExisting($existing);

        return $existing
            ->reject(fn (PartitionInfo $partition) => $partition->isMax())
            ->filter(function (PartitionInfo $partition) use ($start, $end) {
                $period = $this->getStrategy()->parse($partition->name);

                return $period !== null && $period->betweenIncluded($start, $end);
            })
            ->values();
    }

    /**
     * @return Collection<int, PartitionInfo>
     *
     * @throws PartitionException
     */
    public function outdated(DateTimeInterface|string|null $before = null): Collection
    {
        $cutoff = $before !== null
            ? $this->getStrategy()->start($this->toDate($before))
            : $this->retentionCutoff();

        if ($cutoff === null) {
            return collect();
        }

        $existing = $this->existing();

        $this->assertMatchesExisting($existing);
        $this->assertPeriodMatchesExisting($existing);

        $anchor = $before === null ? $this->lastBounded($existing)?->name : null;

        return $this->startingBefore($existing, $cutoff)
            ->reject(fn (PartitionInfo $partition) => $partition->name === $anchor)
            ->values();
    }

    /**
     * @return Collection<int, PartitionInfo>
     *
     * @throws PartitionException
     */
    public function outdatedAfter(PartitionPlan $plan): Collection
    {
        $plan = $this->current($plan);
        $cutoff = $this->retentionCutoff();

        if ($cutoff === null || $plan->pending->isEmpty()) {
            return $this->outdated();
        }

        $existing = $this->existing();

        $this->assertMatchesExisting($existing);
        $this->assertPeriodMatchesExisting($existing);

        return $this->startingBefore($existing, $cutoff);
    }

    public function partitionByClause(): string
    {
        return $this->grammar->partitionByClause(
            $this->column,
            $this->expression,
            $this->definitionsFor($this->layout(), withMax: $this->withMaxPartition)
        );
    }

    public function createStatement(): string
    {
        return $this->createStatementFor($this->layout());
    }

    /**
     * @return Collection<int, string>
     *
     * @throws PartitionException
     */
    public function syncStatements(?PartitionPlan $plan = null): Collection
    {
        $this->assertPartitioned();
        $this->assertMaxPartitionMatches();

        $pending = $this->current($plan)->pending;

        if ($pending->isEmpty()) {
            return collect();
        }

        $this->assertFits($pending);

        $table = $this->prefixedTable();
        $maxPartition = $this->existingMaxPartition();

        return $this->grouped($pending, $this->syncChunk)
            ->map(function (Collection $group) use ($table, $maxPartition) {
                $definitions = $this->definitionsFor($group, withMax: false);

                return $maxPartition !== null
                    ? $this->grammar->reorganizeMaxPartition($table, $maxPartition->name, $definitions)
                    : $this->grammar->addPartitions($table, $definitions);
            })
            ->values();
    }

    /**
     * @param  Collection<int, PartitionInfo>|array<int, PartitionInfo>  $partitions
     *
     * @throws DropsEveryPartitionException
     */
    public function assertDroppable(Collection|array $partitions): void
    {
        $names = collect($partitions)->map(fn (PartitionInfo $partition) => $partition->name);
        $existing = $this->existingNames();

        if ($existing->isNotEmpty() && $existing->diff($names)->isEmpty()) {
            throw new DropsEveryPartitionException($this->getTable(), $existing->count());
        }
    }

    /**
     * @param  Collection<int, PartitionInfo>|array<int, PartitionInfo>  $partitions
     * @return Collection<int, string>
     *
     * @throws DropsEveryPartitionException
     */
    public function dropStatements(Collection|array $partitions): Collection
    {
        $this->assertDroppable($partitions);

        $names = collect($partitions)->map(fn (PartitionInfo $partition) => $partition->name);

        return $this->grouped($names, $this->chunk)
            ->map(fn (Collection $group) => $this->grammar->dropPartitions($this->prefixedTable(), $group))
            ->values();
    }

    /**
     * @return Collection<int, string>
     *
     * @throws PartitionException
     */
    public function create(): Collection
    {
        if ($this->isPartitioned()) {
            throw AlreadyPartitionedException::forCreate($this->getTable());
        }

        $planned = $this->layout();
        $statements = collect([$this->createStatementFor($planned)]);

        $this->run($statements, $planned);

        $this->fire(new PartitionsCreated(
            $this->getTable(),
            $this->getConnection(),
            $planned->map(fn (PendingPartition $pending) => $pending->name)->all(),
            $statements->all(),
        ));

        return $statements;
    }

    /**
     * @return Collection<int, string>
     *
     * @throws PartitionException
     */
    public function sync(?PartitionPlan $plan = null): Collection
    {
        $this->assertPartitioned();

        $plan = $this->current($plan);
        $statements = $this->syncStatements($plan);

        if ($statements->isEmpty()) {
            return $statements;
        }

        $gap = $this->gap();
        $names = $plan->pending->map(fn (PendingPartition $partition) => $partition->name);
        $groups = $this->grouped($names, $this->syncChunk);

        $this->run($statements, $plan->pending, fn (int $executed) => $this->fire(new PartitionsCreated(
            $this->getTable(),
            $this->getConnection(),
            $groups->take($executed)->flatten()->all(),
            $statements->take($executed)->all(),
        )));

        if ($gap !== null) {
            $this->warn($gap->message(), $gap->toArray());
        }

        return $statements;
    }

    /**
     * @return Collection<int, string>
     *
     * @throws PartitionException
     */
    public function dropBefore(DateTimeInterface|string $date): Collection
    {
        return $this->drop($this->outdated($date));
    }

    /**
     * @return Collection<int, string>
     *
     * @throws PartitionException
     */
    public function dropBetween(DateTimeInterface|string $from, DateTimeInterface|string $to): Collection
    {
        return $this->drop($this->inRange($from, $to));
    }

    /**
     * @return Collection<int, string>
     *
     * @throws PartitionException
     */
    public function prune(): Collection
    {
        return $this->drop($this->outdated());
    }

    /**
     * @return array{added: Collection<int, string>, dropped: Collection<int, string>}
     *
     * @throws PartitionException
     */
    public function maintain(?PartitionPlan $plan = null): array
    {
        $this->assertPartitioned();

        $plan = $this->current($plan);
        $outdated = $this->outdatedAfter($plan);

        return [
            'added' => $this->sync($plan),
            'dropped' => $this->drop($outdated),
        ];
    }

    /**
     * @param  Collection<int, PartitionInfo>|array<int, PartitionInfo>  $partitions
     * @return Collection<int, string>
     */
    public function truncate(Collection|array $partitions): Collection
    {
        $names = collect($partitions)->map(fn (PartitionInfo $partition) => $partition->name);

        if ($names->isEmpty()) {
            return collect();
        }

        $groups = $this->grouped($names, $this->chunk);
        $statements = $groups
            ->map(fn (Collection $group) => $this->grammar->truncatePartitions($this->prefixedTable(), $group))
            ->values();

        $this->run($statements, executed: fn (int $executed) => $this->fire(new PartitionsTruncated(
            $this->getTable(),
            $this->getConnection(),
            $groups->take($executed)->flatten()->all(),
            $statements->take($executed)->all(),
        )));

        return $statements;
    }

    /**
     * @return Collection<int, string>
     *
     * @throws PartitionException
     */
    public function removePartitioning(): Collection
    {
        if (! $this->isPartitioned()) {
            throw NotPartitionedException::forRemoval($this->getTable());
        }

        $removed = $this->existingNames();
        $statements = collect([$this->grammar->removePartitioning($this->prefixedTable())]);

        $this->run($statements);

        $this->fire(new PartitioningRemoved(
            $this->getTable(),
            $this->getConnection(),
            $removed->all(),
            $statements->all(),
        ));

        return $statements;
    }

    /**
     * @param  Collection<int, PartitionInfo>|array<int, PartitionInfo>  $partitions
     * @return Collection<int, string>
     *
     * @throws DropsEveryPartitionException
     */
    public function drop(Collection|array $partitions): Collection
    {
        $partitions = collect($partitions)->values();

        if ($partitions->isEmpty()) {
            return collect();
        }

        $statements = $this->dropStatements($partitions);
        $groups = $this->grouped($partitions->map(fn (PartitionInfo $partition) => $partition->name), $this->chunk);

        $this->run($statements, executed: fn (int $executed) => $this->fire(new PartitionsDropped(
            $this->getTable(),
            $this->getConnection(),
            $groups->take($executed)->flatten()->all(),
            $statements->take($executed)->all(),
        )));

        return $statements;
    }

    /**
     * @return Collection<int, PendingPartition>
     *
     * @throws InvalidRangeException
     * @throws TooManyPartitionsException
     */
    protected function plannedPartitions(?CarbonImmutable $from = null): Collection
    {
        $strategy = $this->getStrategy();

        $this->assertRangeCoversAPeriod();

        $from ??= $this->resolveFrom();
        $to = $this->resolveTo();
        $planned = collect();

        foreach ($strategy->periods($from, $to) as $start) {
            if ($planned->count() === self::MAX_PARTITIONS) {
                throw TooManyPartitionsException::forRange(
                    $this->getTable(),
                    $from->toDateString(),
                    $to->toDateString(),
                    self::MAX_PARTITIONS
                );
            }

            $upperBound = $strategy->next($start);

            $planned->push(new PendingPartition(
                name: $strategy->partitionName($start),
                start: $start,
                upperBound: $upperBound,
                boundary: $this->expression->boundary($upperBound, $this->boundaryTimezone()),
            ));
        }

        return $planned;
    }

    /**
     * @return Collection<int, PendingPartition>
     *
     * @throws InvalidRangeException
     * @throws TooManyPartitionsException
     */
    protected function layout(): Collection
    {
        $planned = $this->plannedPartitions();

        if ($this->withMaxPartition && $planned->count() === self::MAX_PARTITIONS) {
            throw TooManyPartitionsException::forMaxPartition(
                $this->getTable(),
                $this->resolveFrom()->toDateString(),
                $this->resolveTo()->toDateString(),
                self::MAX_PARTITIONS
            );
        }

        return $planned;
    }

    /**
     * @param  Collection<int, PendingPartition>  $pending
     *
     * @throws TooManyPartitionsException
     */
    protected function assertFits(Collection $pending): void
    {
        $existing = $this->existing()->count();

        if ($existing + $pending->count() > self::MAX_PARTITIONS) {
            throw TooManyPartitionsException::forTable(
                $this->getTable(),
                $existing,
                $pending->count(),
                self::MAX_PARTITIONS
            );
        }
    }

    /**
     * @param  Collection<int, PendingPartition>  $planned
     */
    protected function createStatementFor(Collection $planned): string
    {
        return $this->grammar->createPartitioning(
            $this->prefixedTable(),
            $this->column,
            $this->expression,
            $this->definitionsFor($planned, withMax: $this->withMaxPartition)
        );
    }

    /**
     * @param  Collection<int, PendingPartition>  $partitions
     * @return Collection<int, string>
     */
    protected function definitionsFor(Collection $partitions, bool $withMax): Collection
    {
        $definitions = $partitions
            ->map(fn (PendingPartition $pending) => $this->grammar->partition($pending->name, $pending->boundary))
            ->values();

        if ($withMax) {
            $definitions->push($this->grammar->maxPartition($this->maxPartition));
        }

        return $definitions;
    }

    /**
     * @throws InvalidRangeException
     */
    protected function assertRangeCoversAPeriod(): void
    {
        if ($this->from === null) {
            return;
        }

        $strategy = $this->getStrategy();
        $to = $this->resolveTo();

        if ($strategy->start($this->from)->lessThanOrEqualTo($strategy->start($to))) {
            return;
        }

        throw $this->to === null
            ? InvalidRangeException::forLookAhead(
                $this->getTable(),
                $this->from->toDateString(),
                $to->toDateString(),
                $this->future
            )
            : InvalidRangeException::forDates(
                $this->getTable(),
                $this->from->toDateString(),
                $to->toDateString()
            );
    }

    /**
     * @throws NotPartitionedException
     */
    protected function assertPartitioned(): void
    {
        if (! $this->isPartitioned()) {
            throw NotPartitionedException::forSync($this->getTable());
        }
    }

    /**
     * @throws MaxPartitionConflictException
     */
    protected function assertMaxPartitionMatches(): void
    {
        $existing = $this->withMaxPartition ? null : $this->existingMaxPartition();

        if ($existing === null) {
            return;
        }

        throw new MaxPartitionConflictException($this->getTable(), $existing->name);
    }

    /**
     * @param  Collection<int, PartitionInfo>  $existing
     *
     * @throws ExpressionMismatchException
     */
    protected function assertMatchesExisting(Collection $existing): void
    {
        $partition = $existing->first(
            fn (PartitionInfo $info) => trim((string) $info->expression) !== '' || trim((string) $info->method) !== ''
        );

        if ($partition === null) {
            return;
        }

        $found = PartitionExpression::normalize((string) $partition->expression);
        $expected = $this->expression->normalized($this->column);
        $method = strtoupper(trim((string) $partition->method));

        $methodMatches = $method === '' || $method === $this->expression->method();
        $expressionMatches = $found === '' || $found === $expected;

        if ($methodMatches && $expressionMatches) {
            return;
        }

        throw new ExpressionMismatchException(
            $this->getTable(),
            trim($method.($found === '' ? '' : ' ('.$found.')')),
            $this->expression->method().' ('.$expected.')'
        );
    }

    /**
     * @param  Collection<int, PartitionInfo>  $existing
     *
     * @throws StrategyMismatchException
     */
    protected function assertPeriodMatchesExisting(Collection $existing): void
    {
        $strategy = $this->getStrategy();
        $last = $this->lastBounded($existing);

        foreach ($existing as $partition) {
            $boundary = $this->boundaryOf($partition);

            if ($boundary === null) {
                continue;
            }

            $start = $strategy->parse($partition->name);

            if ($start === null && $partition !== $last) {
                continue;
            }

            if ($start !== null && $this->endsAt($strategy->next($start), $boundary)) {
                continue;
            }

            $period = $this->periodCutting($partition, $boundary);

            if ($period !== null) {
                throw StrategyMismatchException::forPeriod(
                    $this->getTable(),
                    $partition->name,
                    $period->value,
                    $strategy->name()
                );
            }

            if ($start !== null) {
                throw StrategyMismatchException::forBoundary(
                    $this->getTable(),
                    $partition->name,
                    $boundary->format('Y-m-d'),
                    $strategy->next($start)->format('Y-m-d'),
                    $strategy->name()
                );
            }
        }
    }

    protected function periodCutting(PartitionInfo $partition, DateTimeInterface $boundary): ?PartitionPeriod
    {
        $configured = PartitionPeriod::tryFromStrategy($this->getStrategy());

        foreach (PartitionPeriod::cases() as $period) {
            if ($period === $configured) {
                continue;
            }

            $strategy = $period->strategy()->withPrefix($this->getStrategy()->prefix());
            $start = $strategy->parse($partition->name);

            if ($start !== null && $this->endsAt($strategy->next($start), $boundary)) {
                return $period;
            }
        }

        return null;
    }

    protected function endsAt(DateTimeInterface $expected, DateTimeInterface $boundary): bool
    {
        if ($this->expression !== PartitionExpression::UnixTimestamp) {
            return $expected->format('Y-m-d') === $boundary->format('Y-m-d');
        }

        $epoch = (int) $this->expression->boundary($expected, $this->boundaryTimezone());

        return abs($epoch - $boundary->getTimestamp()) < 86400;
    }

    protected function boundaryOf(PartitionInfo $partition): ?DateTimeInterface
    {
        return $partition->isMax()
            ? null
            : $this->expression->parseBoundary($partition->description, $this->boundaryTimezone());
    }

    /**
     * @param  Collection<int, PartitionInfo>  $existing
     */
    protected function lastBounded(Collection $existing): ?PartitionInfo
    {
        return $existing
            ->filter(fn (PartitionInfo $partition) => $this->boundaryOf($partition) !== null)
            ->sortBy(fn (PartitionInfo $partition) => $this->boundaryOf($partition)?->format('Y-m-d'))
            ->last();
    }

    /**
     * @param  Collection<int, PartitionInfo>  $existing
     */
    protected function highestBoundary(Collection $existing): ?DateTimeInterface
    {
        $last = $this->lastBounded($existing);

        return $last === null ? null : $this->boundaryOf($last);
    }

    protected function current(?PartitionPlan $plan): PartitionPlan
    {
        if ($plan === null) {
            return $this->plan();
        }

        $existing = $this->existing();

        return new PartitionPlan(
            $this->absent($plan->pending, $existing, $this->highestBoundary($existing)),
            $plan->deferred,
        );
    }

    /**
     * @param  Collection<int, PendingPartition>  $partitions
     * @param  Collection<int, PartitionInfo>  $existing
     * @return Collection<int, PendingPartition>
     */
    protected function absent(Collection $partitions, Collection $existing, ?DateTimeInterface $floor): Collection
    {
        $names = $existing->map(fn (PartitionInfo $partition) => $partition->name)->all();

        return $partitions
            ->reject(fn (PendingPartition $pending) => in_array($pending->name, $names, true))
            ->reject(fn (PendingPartition $pending) => $floor !== null && $pending->upperBound->format('Y-m-d') <= $floor->format('Y-m-d'))
            ->values();
    }

    /**
     * @param  Collection<int, PendingPartition>  $missing
     */
    protected function skipped(Collection $missing): int
    {
        $current = $this->getStrategy()->start($this->now())->format('Y-m-d');

        return $missing
            ->filter(fn (PendingPartition $pending) => $pending->start->format('Y-m-d') < $current)
            ->count();
    }

    /**
     * @param  Collection<int, PartitionInfo>  $existing
     * @return Collection<int, PartitionInfo>
     */
    protected function startingBefore(Collection $existing, CarbonImmutable $cutoff): Collection
    {
        return $existing
            ->reject(fn (PartitionInfo $partition) => $partition->isMax())
            ->filter(function (PartitionInfo $partition) use ($cutoff) {
                $start = $this->getStrategy()->parse($partition->name);

                return $start !== null && $start->format('Y-m-d H:i:s') < $cutoff->format('Y-m-d H:i:s');
            })
            ->values();
    }

    protected function retentionCutoff(): ?CarbonImmutable
    {
        if ($this->retain === null) {
            return null;
        }

        $strategy = $this->getStrategy();
        $cutoff = $this->now();
        $left = $this->retain;

        do {
            $stride = min($left, self::RETENTION_STRIDE);
            $cutoff = $strategy->shift($cutoff, -$stride);
            $left -= $stride;
        } while ($left > 0 && $cutoff->year >= self::EARLIEST_YEAR);

        return $cutoff;
    }

    protected function resolveFrom(?DateTimeInterface $floor = null): CarbonImmutable
    {
        if ($this->from !== null) {
            return $this->from;
        }

        if ($floor === null || ! $this->fillGaps) {
            return $this->now();
        }

        $covered = $this->dateOf($floor);
        $cutoff = $this->retentionCutoff();

        return $cutoff !== null && $cutoff->format('Y-m-d') > $covered->format('Y-m-d')
            ? $cutoff
            : $covered;
    }

    protected function dateOf(DateTimeInterface $boundary): CarbonImmutable
    {
        return CarbonImmutable::parse($boundary->format('Y-m-d'), self::FRAME);
    }

    /**
     * @throws TooManyPartitionsException
     */
    protected function resolveTo(): CarbonImmutable
    {
        if ($this->to !== null) {
            return $this->to;
        }

        if ($this->future > self::MAX_PARTITIONS) {
            throw TooManyPartitionsException::forLookAhead($this->getTable(), $this->future, self::MAX_PARTITIONS);
        }

        return $this->getStrategy()->shift($this->now(), $this->future);
    }

    protected function existingMaxPartition(): ?PartitionInfo
    {
        return $this->existing()->first(fn (PartitionInfo $partition) => $partition->isMax());
    }

    /**
     * @template TValue
     *
     * @param  Collection<int, TValue>  $items
     * @return Collection<int, Collection<int, TValue>>
     */
    protected function grouped(Collection $items, ?int $size): Collection
    {
        return $items->isEmpty()
            ? collect()
            : $items->values()->chunk($size ?? $items->count())->map(fn (Collection $group) => $group->values())->values();
    }

    /**
     * @param  Collection<int, string>  $statements
     * @param  Collection<int, PendingPartition>|null  $writing
     * @param  (Closure(int): void)|null  $executed
     *
     * @throws TimezoneMismatchException
     */
    protected function run(Collection $statements, ?Collection $writing = null, ?Closure $executed = null): void
    {
        if ($this->verifiesTimezone()) {
            $this->assertSessionReads($writing ?? collect());
        }

        if ($this->dryRun) {
            return;
        }

        $done = 0;

        try {
            foreach ($statements as $sql) {
                $this->repository->statement($this->getConnection(), $sql);

                $done++;
            }
        } finally {
            $this->refresh();

            if ($executed !== null && $done > 0) {
                $executed($done);
            }
        }
    }

    protected function connectionChanged(): static
    {
        $this->verifiedDatetimes = [];

        return $this->refresh();
    }

    protected function fire(object $event): void
    {
        if (! $this->dryRun) {
            $this->events?->dispatch($event);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function warn(string $message, array $context = []): void
    {
        if (! $this->dryRun) {
            $this->logger?->warning($message, $context);
        }
    }

    /**
     * @throws PartitionException
     */
    public function assertTimezoneMatches(bool $planned = false): void
    {
        if ($this->verifiesTimezone()) {
            $this->assertSessionReads($planned ? $this->plannedPartitions() : collect());
        }
    }

    protected function verifiesTimezone(): bool
    {
        return $this->verifyTimezone && $this->expression === PartitionExpression::UnixTimestamp;
    }

    /**
     * @param  Collection<int, PendingPartition>  $writing
     *
     * @throws TimezoneMismatchException
     */
    protected function assertSessionReads(Collection $writing): void
    {
        $expected = [];

        if ($this->verifiedDatetimes === []) {
            $reference = CarbonImmutable::now()->setTimezone($this->getTimezone());
            $expected[$reference->format('Y-m-d H:i:s')] = $reference->getTimestamp();
        }

        foreach ($writing as $pending) {
            $expected[$pending->upperBound->format('Y-m-d H:i:s')] = (int) $pending->boundary;
        }

        $expected = array_diff_key($expected, $this->verifiedDatetimes);

        if ($expected === []) {
            return;
        }

        $session = $this->repository->unixTimestamps($this->getConnection(), array_keys($expected));

        foreach ($expected as $datetime => $epoch) {
            $read = $session[$datetime] ?? null;

            if ($read !== null && $read !== $epoch) {
                throw new TimezoneMismatchException(
                    $this->getTable(),
                    $this->getTimezone()->getName(),
                    $datetime,
                    intdiv($read - $epoch, 60)
                );
            }

            $this->verifiedDatetimes[$datetime] = true;
        }
    }

    protected function boundaryTimezone(): ?DateTimeZone
    {
        return $this->expression === PartitionExpression::UnixTimestamp ? $this->getTimezone() : null;
    }

    protected function connectionTimezone(): ?DateTimeZone
    {
        $name = $this->repository->connectionTimezone($this->getConnection());

        if ($name === null || $name === '' || strcasecmp($name, 'SYSTEM') === 0) {
            return null;
        }

        try {
            return new DateTimeZone($name);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @throws InvalidTimezoneException
     */
    protected function toTimezone(DateTimeZone|string $timezone): DateTimeZone
    {
        if ($timezone instanceof DateTimeZone) {
            return $timezone;
        }

        try {
            return new DateTimeZone($timezone);
        } catch (Throwable) {
            throw new InvalidTimezoneException($timezone);
        }
    }

    protected function now(): CarbonImmutable
    {
        return CarbonImmutable::now()->shiftTimezone(self::FRAME);
    }

    /**
     * @throws InvalidDateException
     */
    protected function toDate(DateTimeInterface|string $date): CarbonImmutable
    {
        if ($date instanceof DateTimeInterface) {
            return CarbonImmutable::instance($date)->shiftTimezone(self::FRAME);
        }

        if (trim($date) === '') {
            throw new InvalidDateException($date);
        }

        try {
            return CarbonImmutable::parse($date)->shiftTimezone(self::FRAME);
        } catch (Throwable $e) {
            throw new InvalidDateException($date, $e);
        }
    }

    /**
     * @throws UnknownStrategyException
     */
    protected function resolveStrategy(PartitionStrategy|PartitionPeriod|string $strategy): PartitionStrategy
    {
        if ($strategy instanceof PartitionStrategy) {
            return $strategy;
        }

        $name = $strategy instanceof PartitionPeriod ? $strategy->value : $strategy;

        $period = PartitionPeriod::tryMake($name);

        if ($period !== null) {
            return $period->strategy();
        }

        if (is_a($name, PartitionStrategy::class, true)) {
            return new $name;
        }

        throw new UnknownStrategyException($name);
    }
}

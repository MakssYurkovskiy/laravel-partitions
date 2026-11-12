<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Yurkovskiy\Partitions\Contracts\PartitionRepository;
use Yurkovskiy\Partitions\Contracts\PartitionStrategy;
use Yurkovskiy\Partitions\Exceptions\UnknownStrategyException;
use Yurkovskiy\Partitions\Exceptions\UnknownTableException;
use Yurkovskiy\Partitions\Schema\PartitionPeriod;

class PartitionManager
{
    protected const NULLABLE = ['retain', 'chunk', 'sync_chunk', 'max_gaps'];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected PartitionRepository $repository,
        protected ?Dispatcher $events = null,
        protected array $config = [],
        protected ?LoggerInterface $logger = null,
    ) {}

    public function newBuilder(): PartitionBuilder
    {
        $config = $this->given($this->config);

        $builder = $this->apply(new PartitionBuilder($this->repository, $this->events, $this->logger), $config)
            ->defaultConnection($this->nullableString($config['connection'] ?? null));

        if (array_key_exists('future', $config)) {
            $builder->future((int) $config['future']);
        }

        return $builder;
    }

    public function table(Model|string $table): PartitionBuilder
    {
        return $this->newBuilder()->table($table);
    }

    /**
     * @param  Model|class-string<Model>  $model
     */
    public function model(Model|string $model): PartitionBuilder
    {
        return $this->table($model);
    }

    public function daily(Model|string $table, string $column = 'created_at'): PartitionBuilder
    {
        return $this->table($table)->daily($column);
    }

    public function weekly(Model|string $table, string $column = 'created_at'): PartitionBuilder
    {
        return $this->table($table)->weekly($column);
    }

    public function monthly(Model|string $table, string $column = 'created_at'): PartitionBuilder
    {
        return $this->table($table)->monthly($column);
    }

    public function yearly(Model|string $table, string $column = 'created_at'): PartitionBuilder
    {
        return $this->table($table)->yearly($column);
    }

    /**
     * @throws UnknownTableException
     * @throws UnknownStrategyException
     */
    public function for(string $key): PartitionBuilder
    {
        $settings = $this->tables()[$key] ?? null;

        if (! is_array($settings)) {
            throw new UnknownTableException($key);
        }

        return $this->fromArray($key, $settings);
    }

    public function isConfigured(string $key): bool
    {
        return is_array($this->tables()[$key] ?? null);
    }

    /**
     * @return list<string>
     */
    public function configuredKeys(): array
    {
        return array_map(
            fn (int|string $key): string => (string) $key,
            array_keys(array_filter($this->tables(), is_array(...)))
        );
    }

    /**
     * @return Collection<string, PartitionBuilder>
     *
     * @throws UnknownStrategyException
     */
    public function configured(): Collection
    {
        return collect($this->configuredKeys())
            ->mapWithKeys(fn (string $key): array => [$key => $this->for($key)]);
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }

    /**
     * @param  array<string, mixed>  $settings
     *
     * @throws UnknownStrategyException
     */
    protected function fromArray(string $key, array $settings): PartitionBuilder
    {
        $settings = $this->given($settings);

        $builder = $this->apply($this->newBuilder(), $settings);

        if (array_key_exists('connection', $settings)) {
            $builder->connection($this->nullableString($settings['connection']));
        }

        $strategy = $settings['strategy'] ?? PartitionPeriod::Daily;

        if (! is_string($strategy) && ! $strategy instanceof PartitionPeriod && ! $strategy instanceof PartitionStrategy) {
            throw UnknownStrategyException::forTable($strategy, $key);
        }

        $builder->table($settings['table'] ?? $key)
            ->strategy($strategy, $settings['column'] ?? 'created_at')
            ->retain($this->nullableInt($settings['retain'] ?? null));

        if (array_key_exists('future', $settings)) {
            $builder->future((int) $settings['future']);
        }

        return $builder;
    }

    /**
     * @param  array<array-key, mixed>  $settings
     */
    protected function apply(PartitionBuilder $builder, array $settings): PartitionBuilder
    {
        if (array_key_exists('prefix', $settings)) {
            $builder->prefix((string) $settings['prefix']);
        }

        if (array_key_exists('tablespace', $settings)) {
            $builder->tablespace($this->unlessOff($settings['tablespace']));
        }

        if (array_key_exists('expression', $settings)) {
            $builder->expression((string) $settings['expression']);
        }

        if (array_key_exists('chunk', $settings)) {
            $builder->chunk($this->nullableInt($settings['chunk']));
        }

        if (array_key_exists('sync_chunk', $settings)) {
            $builder->syncChunk($this->nullableInt($settings['sync_chunk']));
        }

        if (array_key_exists('fill_gaps', $settings)) {
            $builder->fillGaps((bool) $settings['fill_gaps']);
        }

        if (array_key_exists('max_gaps', $settings)) {
            $builder->maxGaps($this->nullableInt($settings['max_gaps']));
        }

        if (array_key_exists('timezone', $settings)) {
            $builder->timezone($this->unlessOff($settings['timezone']));
        }

        if (array_key_exists('verify_timezone', $settings)) {
            $builder->verifyTimezone((bool) $settings['verify_timezone']);
        }

        if (array_key_exists('max_partition', $settings)) {
            $max = $settings['max_partition'];
            $builder->withMaxPartition(is_string($max) ? $max : (bool) $max);
        }

        return $builder;
    }

    /**
     * @param  array<array-key, mixed>  $settings
     * @return array<array-key, mixed>
     */
    protected function given(array $settings): array
    {
        return array_filter(
            $settings,
            fn (mixed $value, int|string $key): bool => $value !== ''
                && ($value !== null || in_array($key, self::NULLABLE, true)),
            ARRAY_FILTER_USE_BOTH
        );
    }

    protected function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    protected function unlessOff(mixed $value): ?string
    {
        return $value === false ? null : (string) $value;
    }

    protected function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function tables(): array
    {
        $tables = $this->config['tables'] ?? [];

        return is_array($tables) ? $tables : [];
    }
}

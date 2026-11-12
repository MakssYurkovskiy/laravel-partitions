<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Unit;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yurkovskiy\Partitions\Exceptions\InvalidTableConfigException;
use Yurkovskiy\Partitions\Exceptions\PartitionException;
use Yurkovskiy\Partitions\Exceptions\TimezoneMismatchException;
use Yurkovskiy\Partitions\Exceptions\UnknownStrategyException;
use Yurkovskiy\Partitions\Exceptions\UnknownTableException;
use Yurkovskiy\Partitions\PartitionBuilder;
use Yurkovskiy\Partitions\PartitionManager;
use Yurkovskiy\Partitions\Schema\PartitionPeriod;
use Yurkovskiy\Partitions\Strategies\DailyStrategy;
use Yurkovskiy\Partitions\Strategies\MonthlyStrategy;
use Yurkovskiy\Partitions\Strategies\WeeklyStrategy;
use Yurkovskiy\Partitions\Strategies\YearlyStrategy;
use Yurkovskiy\Partitions\Tests\Support\ArchiveEvent;
use Yurkovskiy\Partitions\Tests\Support\FakePartitionRepository;
use Yurkovskiy\Partitions\Tests\Support\PlainEvent;
use Yurkovskiy\Partitions\Tests\Support\QuarterlyStrategy;

class PartitionManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2025-06-13 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function manager(array $config = [], ?FakePartitionRepository $repository = null): PartitionManager
    {
        return new PartitionManager($repository ?? new FakePartitionRepository, null, $config);
    }

    public function test_a_builder_without_config_uses_the_package_defaults(): void
    {
        $sql = $this->manager()->newBuilder()->table('transactions')->daily('created_at')->createStatement();

        self::assertStringContainsString('PARTITION BY RANGE (TO_DAYS(`created_at`))', $sql);
        self::assertStringContainsString('PARTITION `p20250613` VALUES LESS THAN (739781)', $sql);
        self::assertStringContainsString('PARTITION `p20250620` VALUES LESS THAN (739788)', $sql);
        self::assertStringContainsString('PARTITION `pMax` VALUES LESS THAN MAXVALUE', $sql);
        self::assertStringNotContainsString('TABLESPACE', $sql);
    }

    public function test_the_defaults_fill_twelve_gaps_in_one_statement(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p202312' => '739251',
            'pMax' => 'MAXVALUE',
        ], expression: 'to_days(`created_at`)');

        $builder = $this->manager([], $repository)
            ->newBuilder()
            ->table('transactions')
            ->monthly()
            ->to('2025-06-01');

        self::assertSame(18, $builder->missing()->count());
        self::assertSame(12, $builder->pending()->count());
        self::assertSame(6, $builder->deferred()->count());
        self::assertCount(1, $builder->sync());
    }

    public function test_every_default_is_read_from_the_config(): void
    {
        $repository = new FakePartitionRepository;

        $sql = $this->manager([
            'prefix' => 'part',
            'max_partition' => 'overflow',
            'tablespace' => 'transactions_space',
            'expression' => 'columns',
            'future' => 1,
            'connection' => 'reports',
        ], $repository)->newBuilder()->table('transactions')->daily('created_at')->createStatement();

        self::assertStringContainsString('PARTITION BY RANGE COLUMNS(`created_at`)', $sql);
        self::assertStringContainsString("PARTITION `part20250613` VALUES LESS THAN ('2025-06-14') TABLESPACE = `transactions_space`", $sql);
        self::assertStringContainsString("PARTITION `part20250614` VALUES LESS THAN ('2025-06-15')", $sql);
        self::assertStringNotContainsString('part20250615', $sql);
        self::assertStringContainsString('PARTITION `overflow` VALUES LESS THAN MAXVALUE TABLESPACE = `transactions_space`', $sql);
        self::assertSame([['reports', 'transactions']], $repository->requested);
    }

    public function test_a_falsy_max_partition_in_the_config_drops_the_catch_all(): void
    {
        $sql = $this->manager(['max_partition' => false, 'future' => 0])
            ->newBuilder()
            ->table('transactions')
            ->daily('created_at')
            ->createStatement();

        self::assertStringContainsString('PARTITION `p20250613`', $sql);
        self::assertStringNotContainsString('MAXVALUE', $sql);
    }

    public function test_the_config_can_switch_gap_filling_off_and_lift_the_gap_limit(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p202312' => '739251',
            'pMax' => 'MAXVALUE',
        ], expression: 'to_days(`created_at`)');

        $withoutGaps = $this->manager(['fill_gaps' => false], $repository)
            ->newBuilder()
            ->table('transactions')
            ->monthly()
            ->to('2025-06-01');

        self::assertSame(['p202506'], $withoutGaps->missing()->pluck('name')->all());

        $withoutLimit = $this->manager(['max_gaps' => null], $repository)
            ->newBuilder()
            ->table('transactions')
            ->monthly()
            ->to('2025-06-01');

        self::assertSame(18, $withoutLimit->pending()->count());
        self::assertTrue($withoutLimit->deferred()->isEmpty());
    }

    public function test_the_config_sets_the_timezone_and_the_timezone_check(): void
    {
        $repository = new FakePartitionRepository;
        $repository->sessionTimezone = 'Europe/Kyiv';

        $builder = $this->manager([
            'expression' => 'unix_timestamp',
            'timezone' => 'Europe/Kyiv',
            'verify_timezone' => true,
            'future' => 0,
        ], $repository)->newBuilder()->table('transactions')->daily('created_at');

        self::assertSame('Europe/Kyiv', $builder->getTimezone()->getName());
        self::assertStringContainsString('VALUES LESS THAN (1749848400)', $builder->createStatement());

        $builder->create();

        self::assertSame([['2025-06-13 15:00:00', '2025-06-14 00:00:00']], $repository->timestampQueries);
    }

    public function test_empty_environment_values_count_as_not_set(): void
    {
        $repository = new FakePartitionRepository;
        $repository->connectionTimezone = 'Europe/Kyiv';

        $builder = $this->manager([
            'connection' => '',
            'tablespace' => '',
            'timezone' => '',
            'expression' => 'unix_timestamp',
            'future' => 0,
        ], $repository)->table('transactions')->daily('created_at');

        self::assertNull($builder->getConnection());
        self::assertSame('Europe/Kyiv', $builder->getTimezone()->getName());

        $sql = $builder->createStatement();

        self::assertStringContainsString('VALUES LESS THAN (1749848400)', $sql);
        self::assertStringNotContainsString('TABLESPACE', $sql);
    }

    public function test_an_empty_table_timezone_counts_as_not_set(): void
    {
        $repository = new FakePartitionRepository;
        $repository->connectionTimezone = 'Europe/Kyiv';

        $builder = $this->manager(['tables' => [
            'transactions' => ['expression' => 'unix_timestamp', 'timezone' => ''],
        ]], $repository)->for('transactions');

        self::assertSame('Europe/Kyiv', $builder->getTimezone()->getName());
    }

    public function test_an_unset_table_timezone_keeps_the_global_one(): void
    {
        $repository = new FakePartitionRepository;
        $repository->connectionTimezone = 'Europe/Kyiv';

        $builder = $this->manager([
            'timezone' => 'UTC',
            'future' => 0,
            'tables' => ['transactions' => ['expression' => 'unix_timestamp', 'timezone' => null]],
        ], $repository)->for('transactions');

        self::assertSame('UTC', $builder->getTimezone()->getName());
        self::assertStringContainsString('VALUES LESS THAN (1749859200)', $builder->createStatement());
    }

    public function test_a_table_timezone_of_false_turns_the_global_one_off(): void
    {
        $repository = new FakePartitionRepository;
        $repository->connectionTimezone = 'Europe/Kyiv';

        $builder = $this->manager([
            'timezone' => 'UTC',
            'tables' => ['transactions' => ['expression' => 'unix_timestamp', 'timezone' => false]],
        ], $repository)->for('transactions');

        self::assertSame('Europe/Kyiv', $builder->getTimezone()->getName());
    }

    public function test_an_unset_table_tablespace_keeps_the_global_one(): void
    {
        $sql = $this->manager([
            'tablespace' => 'transactions_space',
            'tables' => ['transactions' => ['tablespace' => null]],
        ])->for('transactions')->createStatement();

        self::assertStringContainsString('TABLESPACE = `transactions_space`', $sql);
    }

    public function test_an_empty_retain_drops_nothing(): void
    {
        $repository = $this->dailyRepository();

        $this->manager(['tables' => ['transactions' => ['retain' => '']]], $repository)
            ->for('transactions')
            ->maintain();

        self::assertNotEmpty($repository->executed);
        self::assertSame([], array_filter($repository->executed, fn (string $sql): bool => str_contains($sql, 'DROP')));
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function unsetGlobalValues(): iterable
    {
        $keys = ['prefix', 'max_partition', 'tablespace', 'expression', 'timezone', 'verify_timezone', 'future',
            'chunk', 'sync_chunk', 'fill_gaps', 'max_gaps', 'connection'];

        foreach ($keys as $key) {
            yield "empty {$key}" => [$key, ''];
        }

        foreach (['prefix', 'max_partition', 'tablespace', 'expression', 'timezone', 'verify_timezone', 'future', 'fill_gaps', 'connection'] as $key) {
            yield "null {$key}" => [$key, null];
        }
    }

    #[DataProvider('unsetGlobalValues')]
    public function test_an_unset_global_value_behaves_like_a_missing_key(string $key, mixed $value): void
    {
        $tables = ['tables' => ['transactions' => ['retain' => 3]]];

        self::assertSame(
            $this->fingerprint($tables),
            $this->fingerprint([...$tables, $key => $value])
        );
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function unsetTableValues(): iterable
    {
        $keys = ['table', 'strategy', 'column', 'retain', 'prefix', 'max_partition', 'tablespace', 'expression',
            'timezone', 'verify_timezone', 'future', 'chunk', 'sync_chunk', 'fill_gaps', 'max_gaps', 'connection'];

        foreach ($keys as $key) {
            yield "empty {$key}" => [$key, ''];
        }

        foreach (['table', 'strategy', 'column', 'prefix', 'max_partition', 'tablespace', 'expression', 'timezone', 'verify_timezone', 'future', 'fill_gaps', 'connection'] as $key) {
            yield "null {$key}" => [$key, null];
        }
    }

    #[DataProvider('unsetTableValues')]
    public function test_an_unset_table_value_behaves_like_a_missing_key(string $key, mixed $value): void
    {
        $global = [
            'tablespace' => 'transactions_space',
            'timezone' => 'Europe/Kyiv',
            'verify_timezone' => true,
            'future' => 2,
            'chunk' => 2,
            'sync_chunk' => 2,
            'fill_gaps' => true,
            'max_gaps' => 3,
            'connection' => 'reports',
        ];

        $table = ['retain' => 3];
        $missing = array_diff_key($table, [$key => true]);

        self::assertSame(
            $this->fingerprint([...$global, 'tables' => ['transactions' => $missing]]),
            $this->fingerprint([...$global, 'tables' => ['transactions' => [...$table, $key => $value]]])
        );
    }

    protected function dailyRepository(): FakePartitionRepository
    {
        return FakePartitionRepository::withPartitions([
            'p20250601' => '739769',
            'p20250602' => '739770',
            'p20250603' => '739771',
            'p20250604' => '739772',
            'p20250605' => '739773',
            'pMax' => 'MAXVALUE',
        ], expression: 'to_days(`created_at`)');
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function fingerprint(array $config): array
    {
        $builder = $this->manager($config)->for('transactions');

        $maintained = $this->dailyRepository();
        $this->manager($config, $maintained)->for('transactions')->maintain();

        $verified = new FakePartitionRepository;
        $verified->sessionTimezone = 'Etc/GMT+1';

        try {
            $this->manager($config, $verified)->for('transactions')->usingUnixTimestamp()->create();
            $created = $verified->executed;
        } catch (TimezoneMismatchException) {
            $created = TimezoneMismatchException::class;
        }

        return [
            'table' => $builder->getTable(),
            'column' => $builder->getColumn(),
            'strategy' => $builder->getStrategy()::class,
            'connection' => $builder->getConnection(),
            'timezone' => $builder->getTimezone()->getName(),
            'sql' => $builder->createStatement(),
            'maintained' => $maintained->executed,
            'created' => $created,
        ];
    }

    /**
     * @return array<string, array{array<string, int>, int}>
     */
    public static function configChunks(): array
    {
        return [
            'sync_chunk splits the additions' => [['sync_chunk' => 6], 3],
            'chunk leaves them in one statement' => [['chunk' => 6], 1],
        ];
    }

    /**
     * @param  array<string, int>  $config
     */
    #[DataProvider('configChunks')]
    public function test_the_config_sync_chunk_splits_the_sync_statements(array $config, int $statements): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p202312' => '739251',
            'pMax' => 'MAXVALUE',
        ], expression: 'to_days(`created_at`)');

        $this->manager([...$config, 'max_gaps' => null], $repository)
            ->newBuilder()
            ->table('transactions')
            ->monthly()
            ->to('2025-06-01')
            ->sync();

        self::assertCount($statements, $repository->executed);
    }

    public function test_the_config_chunk_groups_the_drop_statements(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p20250609' => '739777',
            'p20250610' => '739778',
            'p20250611' => '739779',
            'pMax' => 'MAXVALUE',
        ]);
        $drops = fn (PartitionBuilder $builder) => $builder->dropStatements($builder->outdated('2025-06-12'))->all();
        $manager = $this->manager(['chunk' => 1, 'tables' => [
            'transactions' => ['chunk' => null],
            'paired' => ['table' => 'transactions', 'chunk' => 2],
        ]], $repository);

        self::assertSame([
            'ALTER TABLE `transactions` DROP PARTITION `p20250609`',
            'ALTER TABLE `transactions` DROP PARTITION `p20250610`',
            'ALTER TABLE `transactions` DROP PARTITION `p20250611`',
        ], $drops($manager->daily('transactions')));
        self::assertSame([
            'ALTER TABLE `transactions` DROP PARTITION `p20250609`, `p20250610`',
            'ALTER TABLE `transactions` DROP PARTITION `p20250611`',
        ], $drops($manager->for('paired')));
        self::assertSame(
            ['ALTER TABLE `transactions` DROP PARTITION `p20250609`, `p20250610`, `p20250611`'],
            $drops($manager->for('transactions'))
        );
        self::assertSame(
            ['ALTER TABLE `transactions` DROP PARTITION `p20250609`, `p20250610`, `p20250611`'],
            $drops($this->manager(['chunk' => null], $repository)->daily('transactions'))
        );
    }

    public function test_the_named_strategies_are_exposed_on_the_manager(): void
    {
        $manager = $this->manager();

        self::assertInstanceOf(DailyStrategy::class, $manager->daily('transactions')->getStrategy());
        self::assertInstanceOf(WeeklyStrategy::class, $manager->weekly('transactions')->getStrategy());
        self::assertInstanceOf(MonthlyStrategy::class, $manager->monthly('transactions')->getStrategy());
        self::assertInstanceOf(YearlyStrategy::class, $manager->yearly('transactions')->getStrategy());
        self::assertSame('processed_at', $manager->daily('transactions', 'processed_at')->getColumn());
        self::assertSame('created_at', $manager->monthly('transactions')->getColumn());
    }

    public function test_an_unknown_table_key_throws(): void
    {
        $manager = $this->manager(['tables' => ['transactions' => ['strategy' => 'daily']]]);

        self::assertTrue($manager->isConfigured('transactions'));
        self::assertFalse($manager->isConfigured('audit_logs'));

        $this->expectException(UnknownTableException::class);
        $this->expectExceptionMessage('audit_logs');

        $manager->for('audit_logs');
    }

    public function test_a_table_key_with_a_dot_is_looked_up_as_written(): void
    {
        $manager = $this->manager(['tables' => [
            'analytics.transactions' => ['strategy' => 'monthly', 'column' => 'processed_at'],
        ]]);

        self::assertTrue($manager->isConfigured('analytics.transactions'));

        $builder = $manager->for('analytics.transactions');

        self::assertSame('analytics.transactions', $builder->getTable());
        self::assertInstanceOf(MonthlyStrategy::class, $builder->getStrategy());
        self::assertSame('processed_at', $builder->getColumn());
    }

    public function test_a_nested_array_is_not_a_table_under_a_dotted_key(): void
    {
        $manager = $this->manager(['tables' => [
            'analytics' => ['transactions' => ['strategy' => 'monthly']],
        ]]);

        self::assertFalse($manager->isConfigured('analytics.transactions'));

        $this->expectException(UnknownTableException::class);
        $this->expectExceptionMessage('analytics.transactions');

        $manager->for('analytics.transactions');
    }

    public function test_the_table_name_defaults_to_the_config_key(): void
    {
        $manager = $this->manager(['tables' => [
            'transactions' => [],
            'audit' => ['table' => 'audit_log_entries'],
        ]]);

        self::assertSame('transactions', $manager->for('transactions')->getTable());
        self::assertSame('audit_log_entries', $manager->for('audit')->getTable());
    }

    public function test_a_table_without_a_strategy_falls_back_to_daily_on_created_at(): void
    {
        $builder = $this->manager(['tables' => ['transactions' => []]])->for('transactions');

        self::assertInstanceOf(DailyStrategy::class, $builder->getStrategy());
        self::assertSame('created_at', $builder->getColumn());
    }

    public function test_the_strategy_and_column_come_from_the_table_settings(): void
    {
        $manager = $this->manager(['tables' => [
            'transactions' => ['strategy' => 'monthly', 'column' => 'processed_at'],
            'audit' => ['strategy' => QuarterlyStrategy::class],
        ]]);

        $transactions = $manager->for('transactions');

        self::assertInstanceOf(MonthlyStrategy::class, $transactions->getStrategy());
        self::assertSame('processed_at', $transactions->getColumn());
        self::assertInstanceOf(QuarterlyStrategy::class, $manager->for('audit')->getStrategy());
        self::assertSame('p202504', $manager->for('audit')->partitionNameFor('2025-06-13'));
    }

    public function test_a_strategy_can_be_configured_as_a_period_case(): void
    {
        $manager = $this->manager(['tables' => [
            'transactions' => ['strategy' => PartitionPeriod::Monthly, 'column' => 'created_at'],
        ]]);

        self::assertInstanceOf(MonthlyStrategy::class, $manager->for('transactions')->getStrategy());
    }

    public function test_an_unknown_strategy_name_throws(): void
    {
        $this->expectException(UnknownStrategyException::class);

        $this->manager(['tables' => ['transactions' => ['strategy' => 'hourly']]])->for('transactions');
    }

    #[DataProvider('unusableStrategies')]
    public function test_a_strategy_that_is_not_a_name_a_case_or_a_strategy_throws_and_names_the_table(
        mixed $strategy,
        string $reported
    ): void {
        $manager = $this->manager(['tables' => ['transactions' => ['strategy' => $strategy]]]);

        $this->expectException(UnknownStrategyException::class);
        $this->expectExceptionMessage(sprintf('Unknown partition strategy [%s] for table [transactions].', $reported));

        $manager->for('transactions');
    }

    #[DataProvider('unusableStrategies')]
    public function test_configured_reports_an_unusable_strategy_as_a_partition_exception(mixed $strategy): void
    {
        $this->expectException(PartitionException::class);

        $this->manager(['tables' => ['transactions' => ['strategy' => $strategy]]])->configured()->all();
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function unusableStrategies(): array
    {
        return [
            'an integer' => [7, '7'],
            'a boolean' => [true, 'true'],
            'an array of one name' => [['monthly'], 'array'],
            'a float' => [1.5, '1.5'],
        ];
    }

    public function test_the_table_settings_carry_prefix_tablespace_expression_and_max_partition(): void
    {
        $repository = new FakePartitionRepository;

        $sql = $this->manager(['tables' => ['transactions' => [
            'column' => 'processed_at',
            'prefix' => 'txn',
            'tablespace' => 'transactions_space',
            'expression' => 'columns',
            'max_partition' => 'overflow',
            'future' => 0,
        ]]], $repository)->for('transactions')->createStatement();

        self::assertStringContainsString('PARTITION BY RANGE COLUMNS(`processed_at`)', $sql);
        self::assertStringContainsString("PARTITION `txn20250613` VALUES LESS THAN ('2025-06-14') TABLESPACE = `transactions_space`", $sql);
        self::assertStringContainsString('PARTITION `overflow` VALUES LESS THAN MAXVALUE', $sql);
    }

    public function test_a_table_setting_can_turn_the_global_default_off_again(): void
    {
        $manager = $this->manager([
            'prefix' => 'part',
            'tablespace' => 'transactions_space',
            'max_partition' => 'overflow',
            'future' => 0,
            'tables' => ['transactions' => [
                'prefix' => 'p',
                'tablespace' => false,
                'max_partition' => false,
            ]],
        ]);

        $sql = $manager->for('transactions')->createStatement();

        self::assertStringContainsString('PARTITION `p20250613`', $sql);
        self::assertStringNotContainsString('TABLESPACE', $sql);
        self::assertStringNotContainsString('MAXVALUE', $sql);
    }

    public function test_the_table_connection_is_used_for_every_read(): void
    {
        $repository = new FakePartitionRepository;

        $this->manager([
            'connection' => 'default_reports',
            'tables' => ['transactions' => ['connection' => 'archive']],
        ], $repository)->for('transactions')->existing();

        self::assertSame('archive', $this->manager([
            'tables' => ['transactions' => ['connection' => 'archive']],
        ])->for('transactions')->getConnection());
        self::assertSame([['archive', 'transactions']], $repository->requested);
    }

    public function test_a_model_keeps_its_own_connection_over_the_configured_one(): void
    {
        $repository = new FakePartitionRepository;
        $manager = $this->manager(['connection' => 'reports'], $repository);

        $manager->table(ArchiveEvent::class)->existing();
        $manager->table(new ArchiveEvent)->existing();
        $manager->model(ArchiveEvent::class)->existing();
        $manager->daily(ArchiveEvent::class)->retain(30)->dryRun()->prune();

        self::assertSame([
            ['archive', 'events'],
            ['archive', 'events'],
            ['archive', 'events'],
            ['archive', 'events'],
        ], $repository->requested);
    }

    public function test_a_model_without_a_connection_runs_on_the_configured_one(): void
    {
        self::assertSame('reports', $this->manager(['connection' => 'reports'])->table(PlainEvent::class)->getConnection());
        self::assertNull($this->manager()->table(PlainEvent::class)->getConnection());
    }

    public function test_a_model_connection_is_used_without_a_configured_one(): void
    {
        self::assertSame('archive', $this->manager()->table(ArchiveEvent::class)->getConnection());
        self::assertSame('archive', $this->manager(['connection' => null])->table(ArchiveEvent::class)->getConnection());
    }

    public function test_an_explicit_connection_wins_over_the_model_in_either_order(): void
    {
        $manager = $this->manager(['connection' => 'reports']);

        self::assertSame('replica', $manager->table(ArchiveEvent::class)->connection('replica')->getConnection());
        self::assertSame('replica', $manager->newBuilder()->connection('replica')->table(ArchiveEvent::class)->getConnection());
    }

    public function test_the_table_entry_connection_wins_over_the_model(): void
    {
        $repository = new FakePartitionRepository;

        $this->manager([
            'connection' => 'reports',
            'tables' => ['events' => ['table' => ArchiveEvent::class, 'connection' => 'replica']],
        ], $repository)->for('events')->existing();

        self::assertSame([['replica', 'events']], $repository->requested);
    }

    public function test_a_table_entry_model_keeps_its_connection_without_an_entry_connection(): void
    {
        self::assertSame('archive', $this->manager([
            'connection' => 'reports',
            'tables' => ['events' => ['table' => ArchiveEvent::class]],
        ])->for('events')->getConnection());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unnamedConnections(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
    }

    #[DataProvider('unnamedConnections')]
    public function test_an_unnamed_table_entry_connection_falls_back_to_the_model_then_the_config(mixed $connection): void
    {
        $manager = $this->manager([
            'connection' => 'reports',
            'tables' => [
                'events' => ['table' => ArchiveEvent::class, 'connection' => $connection],
                'transactions' => ['connection' => $connection],
            ],
        ]);

        self::assertSame('archive', $manager->for('events')->getConnection());
        self::assertSame('reports', $manager->for('transactions')->getConnection());
    }

    #[DataProvider('unnamedConnections')]
    public function test_an_unnamed_explicit_connection_falls_back_to_the_model_then_the_config(mixed $connection): void
    {
        $manager = $this->manager(['connection' => 'reports']);

        self::assertSame('archive', $manager->table(ArchiveEvent::class)->connection($connection)->getConnection());
        self::assertSame('reports', $manager->table('transactions')->connection($connection)->getConnection());
    }

    public function test_an_empty_configured_connection_is_the_default_one(): void
    {
        $repository = new FakePartitionRepository;

        $this->manager(['connection' => ''], $repository)->table('transactions')->existing();

        self::assertSame([[null, 'transactions']], $repository->requested);
        self::assertSame('archive', $this->manager(['connection' => ''])->table(ArchiveEvent::class)->getConnection());
    }

    public function test_switching_from_a_model_to_a_table_name_drops_the_model_connection(): void
    {
        $repository = new FakePartitionRepository;

        $builder = $this->manager(['connection' => 'reports'], $repository)->table(ArchiveEvent::class);
        $builder->existing();
        $builder->table('transactions')->existing();

        self::assertSame([['archive', 'events'], ['reports', 'transactions']], $repository->requested);
    }

    public function test_future_falls_back_to_the_global_default(): void
    {
        $manager = $this->manager([
            'future' => 2,
            'tables' => [
                'transactions' => ['column' => 'created_at'],
                'audit' => ['column' => 'created_at', 'future' => 0],
            ],
        ]);

        self::assertSame(
            ['p20250613', 'p20250614', 'p20250615'],
            $manager->for('transactions')->missing()->pluck('name')->all()
        );
        self::assertSame(['p20250613'], $manager->for('audit')->missing()->pluck('name')->all());
    }

    public function test_retain_selects_the_outdated_partitions_and_is_off_by_default(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p20250610' => '739778',
            'p20250611' => '739779',
            'p20250612' => '739780',
            'p20250613' => '739781',
            'pMax' => 'MAXVALUE',
        ], expression: 'to_days(`created_at`)');

        $manager = $this->manager(['tables' => [
            'transactions' => ['retain' => 2],
            'two_days' => ['table' => 'transactions', 'retain' => 1],
            'audit' => [],
        ]], $repository);

        self::assertSame(
            ['p20250610'],
            $manager->for('transactions')->outdated()->pluck('name')->all()
        );
        self::assertSame(
            ['p20250610', 'p20250611'],
            $manager->for('two_days')->outdated()->pluck('name')->all()
        );
        self::assertTrue($manager->for('audit')->outdated()->isEmpty());
    }

    public function test_the_table_settings_carry_the_gap_options_and_the_chunk_size(): void
    {
        $repository = FakePartitionRepository::withPartitions([
            'p202312' => '739251',
            'pMax' => 'MAXVALUE',
        ], expression: 'to_days(`created_at`)');

        $manager = $this->manager(['tables' => [
            'bounded' => ['table' => 'transactions', 'strategy' => 'monthly', 'max_gaps' => 3],
            'unbounded' => ['table' => 'transactions', 'strategy' => 'monthly', 'max_gaps' => null, 'sync_chunk' => 6],
            'today_only' => ['table' => 'transactions', 'strategy' => 'monthly', 'fill_gaps' => false],
        ]], $repository);

        $bounded = $manager->for('bounded')->to('2025-06-01');
        self::assertSame(3, $bounded->pending()->count());
        self::assertSame(15, $bounded->deferred()->count());

        $manager->for('unbounded')->to('2025-06-01')->sync();
        self::assertCount(3, $repository->executed);

        self::assertSame(['p202506'], $manager->for('today_only')->to('2025-06-01')->missing()->pluck('name')->all());
    }

    public function test_the_table_settings_carry_the_timezone_options(): void
    {
        $manager = $this->manager([
            'timezone' => 'UTC',
            'tables' => [
                'transactions' => ['expression' => 'unix_timestamp', 'timezone' => 'Europe/Kyiv'],
                'audit' => ['expression' => 'unix_timestamp'],
            ],
        ]);

        self::assertSame('Europe/Kyiv', $manager->for('transactions')->getTimezone()->getName());
        self::assertSame('UTC', $manager->for('audit')->getTimezone()->getName());
    }

    public function test_verify_timezone_can_be_enabled_per_table(): void
    {
        $repository = new FakePartitionRepository;
        $repository->sessionTimezone = 'Etc/GMT+1';

        $builder = $this->manager(['tables' => ['transactions' => [
            'expression' => 'unix_timestamp',
            'timezone' => 'UTC',
            'verify_timezone' => true,
            'future' => 0,
        ]]], $repository)->for('transactions');

        $this->expectException(TimezoneMismatchException::class);

        $builder->create();
    }

    public function test_configured_returns_a_builder_per_table_keyed_by_config_key(): void
    {
        $manager = $this->manager(['tables' => [
            'transactions' => ['strategy' => 'monthly', 'column' => 'created_at'],
            'audit' => ['table' => 'audit_log_entries'],
        ]]);

        $configured = $manager->configured();

        self::assertSame(['transactions', 'audit'], $configured->keys()->all());
        self::assertSame('transactions', $configured->get('transactions')->getTable());
        self::assertInstanceOf(MonthlyStrategy::class, $configured->get('transactions')->getStrategy());
        self::assertSame('audit_log_entries', $configured->get('audit')->getTable());
    }

    public function test_configured_keys_are_listed_without_building_the_tables(): void
    {
        $manager = $this->manager(['tables' => [
            'transactions' => [],
            'logs' => ['timezone' => 'Mars/Base'],
            'audit' => ['strategy' => 'hourly'],
            'broken' => 'daily',
        ]]);

        self::assertSame(['transactions', 'logs', 'audit', 'broken'], $manager->configuredKeys());
        self::assertSame([], $this->manager()->configuredKeys());
    }

    public function test_an_entry_that_is_not_an_array_is_configured_but_cannot_be_built(): void
    {
        $manager = $this->manager(['tables' => ['transactions' => 'daily', 'audit' => null]]);

        self::assertTrue($manager->isConfigured('transactions'));
        self::assertTrue($manager->isConfigured('audit'));

        try {
            $manager->for('audit');
            self::fail('A null entry was built.');
        } catch (InvalidTableConfigException $e) {
            self::assertSame(
                'Table [audit] in config/partitions.php has to be an array of settings, null given.',
                $e->getMessage()
            );
        }

        $this->expectException(InvalidTableConfigException::class);
        $this->expectExceptionMessage('Table [transactions] in config/partitions.php has to be an array of settings, string given.');

        $manager->for('transactions');
    }

    public function test_configured_throws_on_an_entry_that_is_not_an_array(): void
    {
        $manager = $this->manager(['tables' => ['transactions' => [], 'audit' => 'daily']]);

        $this->expectException(InvalidTableConfigException::class);
        $this->expectExceptionMessage('Table [audit]');

        $manager->configured();
    }

    public function test_a_migration_of_a_table_with_a_broken_entry_throws(): void
    {
        $manager = $this->manager(['tables' => ['transactions' => 'monthly', 'audit' => 'daily']]);

        self::assertSame('audit_logs', $manager->forTable('audit_logs')->getTable());

        $this->expectException(InvalidTableConfigException::class);
        $this->expectExceptionMessage('Table [transactions]');

        $manager->forTable('transactions');
    }

    public function test_configured_is_empty_without_tables(): void
    {
        self::assertTrue($this->manager()->configured()->isEmpty());
        self::assertSame([], $this->manager()->config());
    }

    public function test_the_config_is_exposed_as_given(): void
    {
        $config = ['prefix' => 'p', 'tables' => ['transactions' => ['strategy' => 'daily']]];

        self::assertSame($config, $this->manager($config)->config());
    }
}

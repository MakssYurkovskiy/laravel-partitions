<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Unit;

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yurkovskiy\Partitions\Exceptions\AlreadyPartitionedException;
use Yurkovskiy\Partitions\Exceptions\InvalidIdentifierException;
use Yurkovskiy\Partitions\Exceptions\InvalidRangeException;
use Yurkovskiy\Partitions\Exceptions\MissingStrategyException;
use Yurkovskiy\Partitions\Exceptions\NotPartitionedException;
use Yurkovskiy\Partitions\Exceptions\TimezoneMismatchException;
use Yurkovskiy\Partitions\Exceptions\UnsupportedGrammarException;
use Yurkovskiy\Partitions\PartitionManager;
use Yurkovskiy\Partitions\Schema\PartitionMacros;
use Yurkovskiy\Partitions\Schema\PartitionPeriod;
use Yurkovskiy\Partitions\Tests\Support\ArchiveEvent;
use Yurkovskiy\Partitions\Tests\Support\FakePartitionRepository;
use Yurkovskiy\Partitions\Tests\Support\QuarterlyStrategy;
use Yurkovskiy\Partitions\Tests\Support\RecordingConnection;

class BlueprintTest extends TestCase
{
    protected FakePartitionRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2025-06-13 12:00:00');

        $this->repository = new FakePartitionRepository;
        $this->bind($this->repository);

        PartitionMacros::register();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Container::setInstance(null);

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function bind(FakePartitionRepository $repository, array $config = []): void
    {
        $container = new Container;
        $container->instance(PartitionManager::class, new PartitionManager($repository, null, $config + [
            'prefix' => 'p',
            'max_partition' => 'pMax',
            'expression' => 'to_days',
            'future' => 7,
        ]));

        Container::setInstance($container);
    }

    protected function bindVerifyingTimezone(string $sessionTimezone): void
    {
        $this->repository = new FakePartitionRepository;
        $this->repository->sessionTimezone = $sessionTimezone;

        $this->bind($this->repository, [
            'expression' => 'unix_timestamp',
            'timezone' => 'UTC',
            'verify_timezone' => true,
        ]);
    }

    protected function connection(string $prefix = '', string $driver = 'mysql'): Connection
    {
        $connection = $driver === 'mysql'
            ? new MySqlConnection(fn () => null, 'testing', $prefix, ['name' => 'mysql'])
            : new PostgresConnection(fn () => null, 'testing', $prefix, ['name' => 'pgsql']);

        $connection->useDefaultSchemaGrammar();

        return $connection;
    }

    /**
     * @return array<int, string>
     */
    protected function compile(Connection $connection, string $table, callable $callback): array
    {
        return (new Blueprint($connection, $table, $callback))->toSql();
    }

    public function test_partitioning_is_declared_inside_a_create_migration(): void
    {
        $statements = $this->compile($this->connection(), 'transactions', function (Blueprint $table) {
            $table->create();
            $table->id();
            $table->dateTime('created_at');

            $table->partitionByRange('created_at')->daily()->between('2025-06-13', '2025-06-14');
        });

        $this->assertCount(2, $statements);

        $this->assertStringStartsWith('create table `transactions`', $statements[0]);
        $this->assertSame(<<<'SQL'
            ALTER TABLE `transactions`
            PARTITION BY RANGE (TO_DAYS(`created_at`)) (
                PARTITION `p20250613` VALUES LESS THAN (739781),
                PARTITION `p20250614` VALUES LESS THAN (739782),
                PARTITION `pMax` VALUES LESS THAN MAXVALUE
            )
            SQL, $statements[1]);
    }

    public function test_every_declarative_option_reaches_the_clause(): void
    {
        $statements = $this->compile($this->connection(), 'transactions', function (Blueprint $table) {
            $table->partitionByRange('created_at')
                ->monthly()
                ->usingRangeColumns()
                ->prefix('part_')
                ->withMaxPartition('p_future')
                ->tablespace('innodb_file_per_table')
                ->between('2025-06-01', '2025-07-01');
        });

        $this->assertSame(<<<'SQL'
            ALTER TABLE `transactions`
            PARTITION BY RANGE COLUMNS(`created_at`) (
                PARTITION `part_202506` VALUES LESS THAN ('2025-07-01') TABLESPACE = `innodb_file_per_table`,
                PARTITION `part_202507` VALUES LESS THAN ('2025-08-01') TABLESPACE = `innodb_file_per_table`,
                PARTITION `p_future` VALUES LESS THAN MAXVALUE TABLESPACE = `innodb_file_per_table`
            )
            SQL, $statements[0]);
    }

    public function test_future_is_used_when_no_range_is_declared(): void
    {
        $statements = $this->compile($this->connection(), 'transactions', function (Blueprint $table) {
            $table->partitionByRange('created_at')->daily()->future(2);
        });

        $this->assertStringContainsString('PARTITION `p20250613`', $statements[0]);
        $this->assertStringContainsString('PARTITION `p20250615`', $statements[0]);
        $this->assertStringNotContainsString('PARTITION `p20250616`', $statements[0]);
    }

    public function test_the_connection_table_prefix_is_applied_on_both_sides(): void
    {
        $this->repository->tablePrefix = 'wp_';

        $statements = $this->compile($this->connection('wp_'), 'transactions', function (Blueprint $table) {
            $table->partitionByRange('created_at')->daily()->between('2025-06-13', '2025-06-13');
        });

        $this->assertStringStartsWith('ALTER TABLE `wp_transactions`', $statements[0]);

        $this->assertSame([['mysql', 'wp_transactions']], $this->repository->requested);
    }

    public function test_the_table_prefix_goes_after_the_schema_of_a_qualified_name(): void
    {
        $this->repository->tablePrefix = 'wp_';

        $statements = $this->compile($this->connection('wp_'), 'reporting.transactions', function (Blueprint $table) {
            $table->partitionByRange('created_at')->daily()->between('2025-06-13', '2025-06-13');
        });

        $this->assertStringStartsWith('ALTER TABLE `reporting`.`wp_transactions`', $statements[0]);

        $this->assertSame([['mysql', 'reporting.wp_transactions']], $this->repository->requested);
    }

    public function test_removing_partitioning_checks_the_prefixed_table(): void
    {
        $this->bind($this->repository = FakePartitionRepository::withPartitions([
            'p20250613' => '739781',
            'pMax' => 'MAXVALUE',
        ]));
        $this->repository->tablePrefix = 'wp_';

        $statements = $this->compile($this->connection('wp_'), 'reporting.transactions', function (Blueprint $table) {
            $table->removePartitioning();
        });

        $this->assertSame(['ALTER TABLE `reporting`.`wp_transactions` REMOVE PARTITIONING'], $statements);
        $this->assertSame([['mysql', 'reporting.wp_transactions']], $this->repository->requested);
    }

    public function test_replacing_the_partitioning_names_the_max_partition_as_declared(): void
    {
        $this->bind($this->repository = FakePartitionRepository::withPartitions([
            'p202505' => '739768',
            'pMax' => 'MAXVALUE',
        ]));

        $statements = $this->compile($this->connection(), 'transactions', function (Blueprint $table) {
            $table->partitionByRange('created_at')
                ->monthly()
                ->withMaxPartition('p_new')
                ->replacePartitioning()
                ->between('2025-06-01', '2025-06-01');
        });

        $this->assertStringContainsString('PARTITION `p_new` VALUES LESS THAN MAXVALUE', $statements[0]);
        $this->assertStringNotContainsString('pMax', $statements[0]);
        $this->assertSame(0, $this->repository->reads);
    }

    public function test_replacing_the_partitioning_falls_back_to_the_configured_max_partition_name(): void
    {
        $this->bind($this->repository = FakePartitionRepository::withPartitions([
            'p202505' => '739768',
            'p_future' => 'MAXVALUE',
        ]));

        $statements = $this->compile($this->connection(), 'transactions', function (Blueprint $table) {
            $table->partitionByRange('created_at')
                ->monthly()
                ->replacePartitioning()
                ->between('2025-06-01', '2025-06-01');
        });

        $this->assertStringContainsString('PARTITION `pMax` VALUES LESS THAN MAXVALUE', $statements[0]);
        $this->assertStringNotContainsString('p_future', $statements[0]);
    }

    public function test_a_pretended_migration_compiles_the_same_max_partition_as_a_real_one(): void
    {
        $this->bind($this->repository = FakePartitionRepository::withPartitions([
            'p202505' => '739768',
            'p_future' => 'MAXVALUE',
        ]));

        $declare = function (Blueprint $table) {
            $table->partitionByRange('created_at')
                ->monthly()
                ->replacePartitioning()
                ->between('2025-06-01', '2025-06-01');
        };

        $real = $this->compile($this->connection(), 'transactions', $declare);

        $connection = $this->connection();
        $connection->pretend(function () use ($connection, $declare, $real) {
            $this->assertSame($real, $this->compile($connection, 'transactions', $declare));
        });
    }

    public function test_a_custom_strategy_class_can_be_declared(): void
    {
        $statements = $this->compile($this->connection(), 'transactions', function (Blueprint $table) {
            $table->partitionByRange('created_at')
                ->strategy(QuarterlyStrategy::class)
                ->between('2025-01-01', '2025-04-01');
        });

        $this->assertStringContainsString('PARTITION `p202501` VALUES LESS THAN (739707)', $statements[0]);
        $this->assertStringContainsString('PARTITION `p202504` VALUES LESS THAN (739798)', $statements[0]);
    }

    /**
     * @return array<string, array{PartitionPeriod, string}>
     */
    public static function periods(): array
    {
        return [
            'daily' => [PartitionPeriod::Daily, <<<'SQL'
                ALTER TABLE `transactions`
                PARTITION BY RANGE (TO_DAYS(`created_at`)) (
                    PARTITION `p20250613` VALUES LESS THAN (739781),
                    PARTITION `p20250614` VALUES LESS THAN (739782),
                    PARTITION `pMax` VALUES LESS THAN MAXVALUE
                )
                SQL],
            'weekly' => [PartitionPeriod::Weekly, <<<'SQL'
                ALTER TABLE `transactions`
                PARTITION BY RANGE (TO_DAYS(`created_at`)) (
                    PARTITION `p202524` VALUES LESS THAN (739783),
                    PARTITION `p202525` VALUES LESS THAN (739790),
                    PARTITION `pMax` VALUES LESS THAN MAXVALUE
                )
                SQL],
            'monthly' => [PartitionPeriod::Monthly, <<<'SQL'
                ALTER TABLE `transactions`
                PARTITION BY RANGE (TO_DAYS(`created_at`)) (
                    PARTITION `p202506` VALUES LESS THAN (739798),
                    PARTITION `p202507` VALUES LESS THAN (739829),
                    PARTITION `pMax` VALUES LESS THAN MAXVALUE
                )
                SQL],
            'yearly' => [PartitionPeriod::Yearly, <<<'SQL'
                ALTER TABLE `transactions`
                PARTITION BY RANGE (TO_DAYS(`created_at`)) (
                    PARTITION `p2025` VALUES LESS THAN (739982),
                    PARTITION `p2026` VALUES LESS THAN (740347),
                    PARTITION `pMax` VALUES LESS THAN MAXVALUE
                )
                SQL],
        ];
    }

    #[DataProvider('periods')]
    public function test_a_period_enum_can_be_declared_as_the_strategy(PartitionPeriod $period, string $expected): void
    {
        $statements = $this->compile($this->connection(), 'transactions', function (Blueprint $table) use ($period) {
            $table->partitionByRange('created_at')->strategy($period)->future(1);
        });

        $this->assertSame([$expected], $statements);
    }

    public function test_partitioning_can_be_removed_from_a_migration(): void
    {
        $this->bind(FakePartitionRepository::withPartitions([
            'p20250613' => '739781',
            'pMax' => 'MAXVALUE',
        ]));

        $statements = $this->compile($this->connection(), 'transactions', function (Blueprint $table) {
            $table->removePartitioning();
        });

        $this->assertSame(['ALTER TABLE `transactions` REMOVE PARTITIONING'], $statements);
    }

    public function test_removing_partitioning_checks_the_table_really_is_partitioned(): void
    {
        $this->expectException(NotPartitionedException::class);
        $this->expectExceptionMessage('is not partitioned, so removePartitioning() has nothing to remove');

        $this->compile($this->connection(), 'transactions', function (Blueprint $table) {
            $table->removePartitioning();
        });
    }

    public function test_declaring_a_layout_on_an_already_partitioned_table_is_refused(): void
    {
        $this->bind(FakePartitionRepository::withPartitions([
            'p20250613' => '739781',
            'pMax' => 'MAXVALUE',
        ]));

        $this->expectException(AlreadyPartitionedException::class);
        $this->expectExceptionMessage('already partitioned');

        $this->compile($this->connection(), 'transactions', function (Blueprint $table) {
            $table->partitionByRange('created_at')->daily();
        });
    }

    public function test_replace_partitioning_confirms_the_rebuild(): void
    {
        $this->bind(FakePartitionRepository::withPartitions([
            'p20250613' => '739781',
            'pMax' => 'MAXVALUE',
        ]));

        $statements = $this->compile($this->connection(), 'transactions', function (Blueprint $table) {
            $table->partitionByRange('created_at')
                ->monthly()
                ->replacePartitioning()
                ->between('2025-06-01', '2025-06-01');
        });

        $this->assertStringContainsString('PARTITION BY RANGE (TO_DAYS(`created_at`))', $statements[0]);
        $this->assertStringContainsString('PARTITION `p202506`', $statements[0]);
    }

    public function test_a_migration_refuses_a_range_that_ends_before_it_starts(): void
    {
        $this->expectException(InvalidRangeException::class);
        $this->expectExceptionMessage(
            'The range for table [transactions] starts at 2025-06-14 and ends at 2025-06-13, before it starts'
        );

        $this->compile($this->connection(), 'transactions', function (Blueprint $table) {
            $table->create();
            $table->id();
            $table->dateTime('created_at');

            $table->partitionByRange('created_at')->daily()->between('2025-06-14', '2025-06-13');
        });
    }

    public function test_an_unpartitioned_table_can_be_partitioned_by_an_edit(): void
    {
        $statements = $this->compile($this->connection(), 'transactions', function (Blueprint $table) {
            $table->partitionByRange('created_at')->daily()->between('2025-06-13', '2025-06-13');
        });

        $this->assertStringStartsWith('ALTER TABLE `transactions`', $statements[0]);
        $this->assertStringContainsString('PARTITION `p20250613`', $statements[0]);
    }

    public function test_create_does_not_inspect_the_table_at_all(): void
    {
        $this->bind(FakePartitionRepository::withPartitions(['p20250613' => '739781']));

        $statements = $this->compile($this->connection(), 'transactions', function (Blueprint $table) {
            $table->create();
            $table->id();
            $table->partitionByRange('created_at')->daily()->between('2025-06-13', '2025-06-13');
        });

        $this->assertCount(2, $statements);
    }

    public function test_the_state_checks_are_skipped_while_pretending(): void
    {
        $connection = $this->connection();
        $connection->pretend(function () use ($connection) {
            $statements = $this->compile($connection, 'transactions', function (Blueprint $table) {
                $table->removePartitioning();
            });

            $this->assertSame(['ALTER TABLE `transactions` REMOVE PARTITIONING'], $statements);
        });
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function blueprintKinds(): array
    {
        return [
            'Schema::table' => [false],
            'Schema::create' => [true],
        ];
    }

    #[DataProvider('blueprintKinds')]
    public function test_a_session_timezone_mismatch_stops_the_migration(bool $creating): void
    {
        $this->bindVerifyingTimezone('Etc/GMT+1');

        try {
            $this->compile($this->connection(), 'transactions', function (Blueprint $table) use ($creating) {
                if ($creating) {
                    $table->create();
                    $table->id();
                }

                $table->partitionByRange('created_at')->daily()->between('2025-06-13', '2025-06-14');
            });

            $this->fail('A session timezone one hour away was accepted.');
        } catch (TimezoneMismatchException $exception) {
            $this->assertStringContainsString('table [transactions] are computed in [UTC]', $exception->getMessage());
            $this->assertStringContainsString('+60 minutes away', $exception->getMessage());
        }

        $this->assertSame([['2025-06-13 12:00:00', '2025-06-14 00:00:00', '2025-06-15 00:00:00']], $this->repository->timestampQueries);
    }

    #[DataProvider('blueprintKinds')]
    public function test_a_session_with_other_dst_rules_stops_the_migration(bool $creating): void
    {
        CarbonImmutable::setTestNow('2026-01-15 12:00:00');

        $this->bindVerifyingTimezone('Europe/London');

        try {
            $this->compile($this->connection(), 'transactions', function (Blueprint $table) use ($creating) {
                if ($creating) {
                    $table->create();
                    $table->id();
                }

                $table->partitionByRange('created_at')->monthly()->between('2026-01-01', '2026-08-01');
            });

            $this->fail('A session that reads the summer boundaries an hour away was accepted.');
        } catch (TimezoneMismatchException $exception) {
            $this->assertStringContainsString('reads [2026-04-01 00:00:00] -60 minutes away', $exception->getMessage());
        }

        $this->assertCount(1, $this->repository->timestampQueries);
    }

    public function test_a_matching_session_timezone_lets_the_migration_compile(): void
    {
        $this->bindVerifyingTimezone('UTC');

        $statements = $this->compile($this->connection(), 'transactions', function (Blueprint $table) {
            $table->partitionByRange('created_at')->daily()->between('2025-06-13', '2025-06-14');
        });

        $this->assertStringContainsString('PARTITION BY RANGE (UNIX_TIMESTAMP(`created_at`))', $statements[0]);
        $this->assertSame([['2025-06-13 12:00:00', '2025-06-14 00:00:00', '2025-06-15 00:00:00']], $this->repository->timestampQueries);
    }

    public function test_the_migration_reads_the_session_timezone_only_for_unix_timestamp(): void
    {
        $this->bindVerifyingTimezone('Etc/GMT+1');

        $statements = $this->compile($this->connection(), 'transactions', function (Blueprint $table) {
            $table->partitionByRange('created_at')->daily()->usingToDays()->between('2025-06-13', '2025-06-14');
        });

        $this->assertStringContainsString('PARTITION BY RANGE (TO_DAYS(`created_at`))', $statements[0]);
        $this->assertSame([], $this->repository->timestampQueries);
    }

    public function test_the_session_timezone_is_not_read_while_pretending(): void
    {
        $this->bindVerifyingTimezone('Etc/GMT+1');

        $connection = $this->connection();
        $connection->pretend(function () use ($connection) {
            $statements = $this->compile($connection, 'transactions', function (Blueprint $table) {
                $table->partitionByRange('created_at')->daily()->between('2025-06-13', '2025-06-14');
            });

            $this->assertStringContainsString('UNIX_TIMESTAMP(`created_at`)', $statements[0]);
        });

        $this->assertSame([], $this->repository->timestampQueries);
    }

    public function test_a_configured_table_brings_its_timezone_into_the_migration(): void
    {
        $this->bind($this->repository, [
            'timezone' => 'UTC',
            'tables' => ['transactions' => ['expression' => 'unix_timestamp', 'timezone' => 'Europe/Kyiv']],
        ]);

        $statements = $this->compile($this->connection(), 'transactions', function (Blueprint $table) {
            $table->partitionByRange('created_at')->daily()->between('2025-06-13', '2025-06-14');
        });

        $configured = PartitionMacros::manager()->for('transactions')->between('2025-06-13', '2025-06-14');

        $this->assertStringContainsString('PARTITION `p20250613` VALUES LESS THAN (1749848400)', $statements[0]);
        $this->assertStringEndsWith($configured->partitionByClause(), $statements[0]);
    }

    public function test_a_configured_table_is_found_by_its_table_setting(): void
    {
        $this->bind($this->repository, ['tables' => [
            'payments' => ['table' => 'transactions', 'strategy' => 'monthly', 'prefix' => 'txn', 'future' => 1],
        ]]);

        $statements = $this->compile($this->connection(), 'transactions', function (Blueprint $table) {
            $table->partitionByRange('booked_at');
        });

        $this->assertSame(<<<'SQL'
            ALTER TABLE `transactions`
            PARTITION BY RANGE (TO_DAYS(`booked_at`)) (
                PARTITION `txn202506` VALUES LESS THAN (739798),
                PARTITION `txn202507` VALUES LESS THAN (739829),
                PARTITION `pMax` VALUES LESS THAN MAXVALUE
            )
            SQL, $statements[0]);
    }

    public function test_the_migration_overrides_what_the_configured_table_sets(): void
    {
        $this->bind($this->repository, ['tables' => [
            'transactions' => ['strategy' => 'monthly', 'expression' => 'unix_timestamp', 'timezone' => 'Europe/Kyiv'],
        ]]);

        $statements = $this->compile($this->connection(), 'transactions', function (Blueprint $table) {
            $table->partitionByRange('created_at')->daily()->timezone('UTC')->between('2025-06-13', '2025-06-13');
        });

        $this->assertStringContainsString('PARTITION `p20250613` VALUES LESS THAN (1749859200)', $statements[0]);
    }

    public function test_a_table_configured_on_another_connection_is_left_out(): void
    {
        $this->bind($this->repository, ['tables' => [
            'events' => ['table' => ArchiveEvent::class, 'prefix' => 'archived'],
            'transactions' => ['connection' => 'reports', 'prefix' => 'reported'],
        ]]);

        foreach (['events', 'transactions'] as $name) {
            $statements = $this->compile($this->connection(), $name, function (Blueprint $table) {
                $table->partitionByRange('created_at')->daily()->between('2025-06-13', '2025-06-13');
            });

            $this->assertStringContainsString('PARTITION `p20250613`', $statements[0]);
        }
    }

    public function test_a_table_configured_on_the_connection_of_the_migration_is_used(): void
    {
        $this->bind($this->repository, ['tables' => [
            'reported' => ['table' => 'transactions', 'connection' => 'reports', 'prefix' => 'reported'],
            'transactions' => ['connection' => 'mysql', 'prefix' => 'txn'],
        ]]);

        $statements = $this->compile($this->connection(), 'transactions', function (Blueprint $table) {
            $table->partitionByRange('created_at')->between('2025-06-13', '2025-06-13');
        });

        $this->assertStringContainsString('PARTITION `txn20250613`', $statements[0]);
    }

    public function test_the_timezone_check_of_a_configured_table_runs_in_the_migration(): void
    {
        $this->repository->sessionTimezone = 'Etc/GMT+1';

        $this->bind($this->repository, ['tables' => [
            'transactions' => ['expression' => 'unix_timestamp', 'timezone' => 'UTC', 'verify_timezone' => true],
        ]]);

        $this->expectException(TimezoneMismatchException::class);

        $this->compile($this->connection(), 'transactions', function (Blueprint $table) {
            $table->partitionByRange('created_at')->daily()->between('2025-06-13', '2025-06-14');
        });
    }

    public function test_the_timezone_check_can_be_declared_in_the_migration(): void
    {
        $this->repository->sessionTimezone = 'Etc/GMT+1';

        try {
            $this->compile($this->connection(), 'transactions', function (Blueprint $table) {
                $table->partitionByRange('created_at')
                    ->daily()
                    ->usingUnixTimestamp()
                    ->timezone('UTC')
                    ->verifyTimezone()
                    ->between('2025-06-13', '2025-06-14');
            });

            $this->fail('A session timezone one hour away was accepted.');
        } catch (TimezoneMismatchException $exception) {
            $this->assertStringContainsString('+60 minutes away', $exception->getMessage());
        }

        $this->bindVerifyingTimezone('Etc/GMT+1');

        $statements = $this->compile($this->connection(), 'transactions', function (Blueprint $table) {
            $table->partitionByRange('created_at')->daily()->verifyTimezone(false)->between('2025-06-13', '2025-06-14');
        });

        $this->assertStringContainsString('UNIX_TIMESTAMP(`created_at`)', $statements[0]);
    }

    public function test_it_refuses_to_compile_on_a_non_mysql_connection(): void
    {
        $this->expectException(UnsupportedGrammarException::class);
        $this->expectExceptionMessage('only supported on MySQL and MariaDB');

        $this->compile($this->connection(driver: 'pgsql'), 'transactions', function (Blueprint $table) {
            $table->partitionByRange('created_at')->daily();
        });
    }

    public function test_remove_partitioning_also_refuses_a_non_mysql_connection(): void
    {
        $this->expectException(UnsupportedGrammarException::class);

        $this->compile($this->connection(driver: 'pgsql'), 'transactions', function (Blueprint $table) {
            $table->removePartitioning();
        });
    }

    public function test_a_declaration_without_a_strategy_fails_loudly(): void
    {
        $this->expectException(MissingStrategyException::class);
        $this->expectExceptionMessage('No partition strategy set');

        $this->compile($this->connection(), 'transactions', function (Blueprint $table) {
            $table->partitionByRange('created_at');
        });
    }

    public function test_an_unsafe_column_name_is_rejected(): void
    {
        $this->expectException(InvalidIdentifierException::class);

        $this->compile($this->connection(), 'transactions', function (Blueprint $table) {
            $table->partitionByRange('created_at`) */ --')->daily();
        });
    }

    #[DataProvider('dottedNames')]
    public function test_a_dotted_column_or_partition_name_is_rejected_before_the_table_is_created(callable $declare, string $name): void
    {
        $connection = new RecordingConnection;
        $connection->useDefaultSchemaGrammar();

        $blueprint = new Blueprint($connection, 'transactions', function (Blueprint $table) use ($declare) {
            $table->create();
            $table->id();
            $table->dateTime('created_at');

            $declare($table);
        });

        try {
            $blueprint->build();
            self::fail('The dotted name was accepted.');
        } catch (InvalidIdentifierException $e) {
            self::assertStringContainsString("[$name]", $e->getMessage());
        }

        self::assertSame([], $connection->statements);
        self::assertSame([], $this->repository->executed);
    }

    /**
     * @return array<string, array{0: callable, 1: string}>
     */
    public static function dottedNames(): array
    {
        return [
            'max partition' => [
                fn (Blueprint $table) => $table->partitionByRange('created_at')->daily()->withMaxPartition('p.max'),
                'p.max',
            ],
            'column' => [
                fn (Blueprint $table) => $table->partitionByRange('x.created_at')->daily(),
                'x.created_at',
            ],
        ];
    }

    public function test_the_macro_resolves_the_current_container_not_the_first_one(): void
    {
        $this->bind(new FakePartitionRepository, ['prefix' => 'later_']);

        $statements = $this->compile($this->connection(), 'transactions', function (Blueprint $table) {
            $table->partitionByRange('created_at')->daily()->between('2025-06-13', '2025-06-13');
        });

        $this->assertStringContainsString('PARTITION `later_20250613`', $statements[0]);
    }
}

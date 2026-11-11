<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Unit;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use PHPUnit\Framework\Attributes\DataProvider;
use Yurkovskiy\Partitions\Console\AddPartitionsCommand;
use Yurkovskiy\Partitions\Console\DropPartitionsCommand;
use Yurkovskiy\Partitions\Console\RunPartitionsCommand;
use Yurkovskiy\Partitions\PartitionBuilder;
use Yurkovskiy\Partitions\PartitionManager;
use Yurkovskiy\Partitions\Tests\Support\CommandTestCase;
use Yurkovskiy\Partitions\Tests\Support\FakePartitionRepository;

class PeriodLifecycleTest extends CommandTestCase
{
    /**
     * @var array<string, array{
     *     existing: array<string, string>,
     *     create: string,
     *     add: string,
     *     retained: list<string>,
     *     before: string,
     *     beforeThat: list<string>,
     *     from: string,
     *     to: string,
     *     inBetween: list<string>,
     * }>
     */
    protected const PERIODS = [
        'daily' => [
            'existing' => [
                'p20250609' => '739777',
                'p20250610' => '739778',
                'p20250611' => '739779',
                'p20250612' => '739780',
                'p20250613' => '739781',
            ],
            'create' => <<<'SQL'
            ALTER TABLE `transactions`
            PARTITION BY RANGE (TO_DAYS(`created_at`)) (
                PARTITION `p20250613` VALUES LESS THAN (739781),
                PARTITION `p20250614` VALUES LESS THAN (739782),
                PARTITION `p20250615` VALUES LESS THAN (739783),
                PARTITION `pMax` VALUES LESS THAN MAXVALUE
            )
            SQL,
            'add' => <<<'SQL'
            ALTER TABLE `transactions`
            REORGANIZE PARTITION `pMax` INTO (
                PARTITION `p20250614` VALUES LESS THAN (739782),
                PARTITION `p20250615` VALUES LESS THAN (739783),
                PARTITION `pMax` VALUES LESS THAN MAXVALUE
            )
            SQL,
            'retained' => [
                'ALTER TABLE `transactions` DROP PARTITION `p20250609`',
                'ALTER TABLE `transactions` DROP PARTITION `p20250610`',
            ],
            'before' => '2025-06-12',
            'beforeThat' => [
                'ALTER TABLE `transactions` DROP PARTITION `p20250609`',
                'ALTER TABLE `transactions` DROP PARTITION `p20250610`',
                'ALTER TABLE `transactions` DROP PARTITION `p20250611`',
            ],
            'from' => '2025-06-10',
            'to' => '2025-06-11',
            'inBetween' => [
                'ALTER TABLE `transactions` DROP PARTITION `p20250610`',
                'ALTER TABLE `transactions` DROP PARTITION `p20250611`',
            ],
        ],
        'weekly' => [
            'existing' => [
                'p202520' => '739755',
                'p202521' => '739762',
                'p202522' => '739769',
                'p202523' => '739776',
                'p202524' => '739783',
            ],
            'create' => <<<'SQL'
            ALTER TABLE `transactions`
            PARTITION BY RANGE (TO_DAYS(`created_at`)) (
                PARTITION `p202524` VALUES LESS THAN (739783),
                PARTITION `p202525` VALUES LESS THAN (739790),
                PARTITION `p202526` VALUES LESS THAN (739797),
                PARTITION `pMax` VALUES LESS THAN MAXVALUE
            )
            SQL,
            'add' => <<<'SQL'
            ALTER TABLE `transactions`
            REORGANIZE PARTITION `pMax` INTO (
                PARTITION `p202525` VALUES LESS THAN (739790),
                PARTITION `p202526` VALUES LESS THAN (739797),
                PARTITION `pMax` VALUES LESS THAN MAXVALUE
            )
            SQL,
            'retained' => [
                'ALTER TABLE `transactions` DROP PARTITION `p202520`',
                'ALTER TABLE `transactions` DROP PARTITION `p202521`',
            ],
            'before' => '2025-06-05',
            'beforeThat' => [
                'ALTER TABLE `transactions` DROP PARTITION `p202520`',
                'ALTER TABLE `transactions` DROP PARTITION `p202521`',
                'ALTER TABLE `transactions` DROP PARTITION `p202522`',
            ],
            'from' => '2025-05-21',
            'to' => '2025-06-01',
            'inBetween' => [
                'ALTER TABLE `transactions` DROP PARTITION `p202521`',
                'ALTER TABLE `transactions` DROP PARTITION `p202522`',
            ],
        ],
        'monthly' => [
            'existing' => [
                'p202502' => '739676',
                'p202503' => '739707',
                'p202504' => '739737',
                'p202505' => '739768',
                'p202506' => '739798',
            ],
            'create' => <<<'SQL'
            ALTER TABLE `transactions`
            PARTITION BY RANGE (TO_DAYS(`created_at`)) (
                PARTITION `p202506` VALUES LESS THAN (739798),
                PARTITION `p202507` VALUES LESS THAN (739829),
                PARTITION `p202508` VALUES LESS THAN (739860),
                PARTITION `pMax` VALUES LESS THAN MAXVALUE
            )
            SQL,
            'add' => <<<'SQL'
            ALTER TABLE `transactions`
            REORGANIZE PARTITION `pMax` INTO (
                PARTITION `p202507` VALUES LESS THAN (739829),
                PARTITION `p202508` VALUES LESS THAN (739860),
                PARTITION `pMax` VALUES LESS THAN MAXVALUE
            )
            SQL,
            'retained' => [
                'ALTER TABLE `transactions` DROP PARTITION `p202502`',
                'ALTER TABLE `transactions` DROP PARTITION `p202503`',
            ],
            'before' => '2025-05-20',
            'beforeThat' => [
                'ALTER TABLE `transactions` DROP PARTITION `p202502`',
                'ALTER TABLE `transactions` DROP PARTITION `p202503`',
                'ALTER TABLE `transactions` DROP PARTITION `p202504`',
            ],
            'from' => '2025-03-15',
            'to' => '2025-04-30',
            'inBetween' => [
                'ALTER TABLE `transactions` DROP PARTITION `p202503`',
                'ALTER TABLE `transactions` DROP PARTITION `p202504`',
            ],
        ],
        'yearly' => [
            'existing' => [
                'p2021' => '738521',
                'p2022' => '738886',
                'p2023' => '739251',
                'p2024' => '739617',
                'p2025' => '739982',
            ],
            'create' => <<<'SQL'
            ALTER TABLE `transactions`
            PARTITION BY RANGE (TO_DAYS(`created_at`)) (
                PARTITION `p2025` VALUES LESS THAN (739982),
                PARTITION `p2026` VALUES LESS THAN (740347),
                PARTITION `p2027` VALUES LESS THAN (740712),
                PARTITION `pMax` VALUES LESS THAN MAXVALUE
            )
            SQL,
            'add' => <<<'SQL'
            ALTER TABLE `transactions`
            REORGANIZE PARTITION `pMax` INTO (
                PARTITION `p2026` VALUES LESS THAN (740347),
                PARTITION `p2027` VALUES LESS THAN (740712),
                PARTITION `pMax` VALUES LESS THAN MAXVALUE
            )
            SQL,
            'retained' => [
                'ALTER TABLE `transactions` DROP PARTITION `p2021`',
                'ALTER TABLE `transactions` DROP PARTITION `p2022`',
            ],
            'before' => '2024-08-15',
            'beforeThat' => [
                'ALTER TABLE `transactions` DROP PARTITION `p2021`',
                'ALTER TABLE `transactions` DROP PARTITION `p2022`',
                'ALTER TABLE `transactions` DROP PARTITION `p2023`',
            ],
            'from' => '2022-06-01',
            'to' => '2023-12-31',
            'inBetween' => [
                'ALTER TABLE `transactions` DROP PARTITION `p2022`',
                'ALTER TABLE `transactions` DROP PARTITION `p2023`',
            ],
        ],
    ];

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
     * @return array<string, array{string}>
     */
    public static function periods(): array
    {
        return [
            'daily' => ['daily'],
            'weekly' => ['weekly'],
            'monthly' => ['monthly'],
            'yearly' => ['yearly'],
        ];
    }

    protected function partitioned(string $period): FakePartitionRepository
    {
        return FakePartitionRepository::withPartitions(
            self::PERIODS[$period]['existing'] + ['pMax' => 'MAXVALUE'],
            expression: 'to_days(`created_at`)',
        );
    }

    protected function builder(FakePartitionRepository $repository, string $period): PartitionBuilder
    {
        return (new PartitionBuilder($repository))->table('transactions')->strategy($period, 'created_at');
    }

    #[DataProvider('periods')]
    public function test_the_builder_partitions_a_table_by_the_period(string $period): void
    {
        $repository = new FakePartitionRepository;

        $statements = $this->builder($repository, $period)->future(2)->create();

        self::assertSame([self::PERIODS[$period]['create']], $statements->all());
        self::assertSame([self::PERIODS[$period]['create']], $repository->executed);
    }

    #[DataProvider('periods')]
    public function test_the_builder_adds_the_periods_ahead(string $period): void
    {
        $repository = $this->partitioned($period);

        $statements = $this->builder($repository, $period)->future(2)->sync();

        self::assertSame([self::PERIODS[$period]['add']], $statements->all());
        self::assertSame([self::PERIODS[$period]['add']], $repository->executed);
    }

    #[DataProvider('periods')]
    public function test_the_builder_prunes_what_is_older_than_the_retention(string $period): void
    {
        $repository = $this->partitioned($period);

        $statements = $this->builder($repository, $period)->retain(2)->prune();

        self::assertSame(self::PERIODS[$period]['retained'], $statements->all());
        self::assertSame(self::PERIODS[$period]['retained'], $repository->executed);
    }

    #[DataProvider('periods')]
    public function test_the_builder_drops_the_periods_before_a_date(string $period): void
    {
        $repository = $this->partitioned($period);

        $statements = $this->builder($repository, $period)->dropBefore(self::PERIODS[$period]['before']);

        self::assertSame(self::PERIODS[$period]['beforeThat'], $statements->all());
        self::assertSame(self::PERIODS[$period]['beforeThat'], $repository->executed);
    }

    #[DataProvider('periods')]
    public function test_the_builder_drops_the_periods_between_two_dates(string $period): void
    {
        $repository = $this->partitioned($period);

        $statements = $this->builder($repository, $period)
            ->dropBetween(self::PERIODS[$period]['from'], self::PERIODS[$period]['to']);

        self::assertSame(self::PERIODS[$period]['inBetween'], $statements->all());
        self::assertSame(self::PERIODS[$period]['inBetween'], $repository->executed);
    }

    #[DataProvider('periods')]
    public function test_the_builder_maintains_a_table_in_one_call(string $period): void
    {
        $repository = $this->partitioned($period);

        $result = $this->builder($repository, $period)->future(2)->retain(2)->maintain();

        self::assertSame([self::PERIODS[$period]['add']], $result['added']->all());
        self::assertSame(self::PERIODS[$period]['retained'], $result['dropped']->all());
        self::assertSame(
            [self::PERIODS[$period]['add'], ...self::PERIODS[$period]['retained']],
            $repository->executed
        );
    }

    #[DataProvider('periods')]
    public function test_the_builder_leaves_a_table_alone_when_it_is_up_to_date(string $period): void
    {
        $repository = $this->partitioned($period);

        $result = $this->builder($repository, $period)->future(0)->retain(4)->maintain();

        self::assertSame([], $result['added']->all());
        self::assertSame([], $result['dropped']->all());
        self::assertSame([], $repository->executed);
    }

    #[DataProvider('periods')]
    public function test_the_add_command_partitions_a_table_by_the_period(string $period): void
    {
        $repository = new FakePartitionRepository;

        $tester = $this->runCommand(new AddPartitionsCommand(new PartitionManager($repository)), [
            'table' => 'transactions',
            '--strategy' => $period,
            '--future' => '2',
            '--create' => true,
            '--force' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Partitioning [transactions] into 3 partitions:', $tester->getDisplay());
        self::assertSame([self::PERIODS[$period]['create']], $repository->executed);
    }

    #[DataProvider('periods')]
    public function test_the_add_command_adds_the_periods_ahead(string $period): void
    {
        $repository = $this->partitioned($period);

        $tester = $this->runCommand(new AddPartitionsCommand(new PartitionManager($repository)), [
            'table' => 'transactions',
            '--strategy' => $period,
            '--future' => '2',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Adding 2 partitions to [transactions]:', $tester->getDisplay());
        self::assertSame([self::PERIODS[$period]['add']], $repository->executed);
    }

    #[DataProvider('periods')]
    public function test_the_drop_command_prunes_what_is_older_than_the_retention(string $period): void
    {
        $repository = $this->partitioned($period);

        $tester = $this->runCommand(new DropPartitionsCommand(new PartitionManager($repository)), [
            'table' => 'transactions',
            '--strategy' => $period,
            '--retain' => '2',
            '--force' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(self::PERIODS[$period]['retained'], $repository->executed);
    }

    #[DataProvider('periods')]
    public function test_the_drop_command_drops_the_periods_before_a_date(string $period): void
    {
        $repository = $this->partitioned($period);

        $tester = $this->runCommand(new DropPartitionsCommand(new PartitionManager($repository)), [
            'table' => 'transactions',
            '--strategy' => $period,
            '--before' => self::PERIODS[$period]['before'],
            '--force' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(self::PERIODS[$period]['beforeThat'], $repository->executed);
    }

    #[DataProvider('periods')]
    public function test_the_drop_command_drops_the_periods_between_two_dates(string $period): void
    {
        $repository = $this->partitioned($period);

        $tester = $this->runCommand(new DropPartitionsCommand(new PartitionManager($repository)), [
            'table' => 'transactions',
            '--strategy' => $period,
            '--from' => self::PERIODS[$period]['from'],
            '--to' => self::PERIODS[$period]['to'],
            '--force' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(self::PERIODS[$period]['inBetween'], $repository->executed);
    }

    #[DataProvider('periods')]
    public function test_the_run_command_maintains_a_configured_table(string $period): void
    {
        $repository = $this->partitioned($period);

        $tester = $this->runCommand(new RunPartitionsCommand(new PartitionManager($repository, null, [
            'tables' => ['transactions' => ['strategy' => $period, 'future' => 2, 'retain' => 2]],
        ])));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(
            [self::PERIODS[$period]['add'], ...self::PERIODS[$period]['retained']],
            $repository->executed
        );
    }
}

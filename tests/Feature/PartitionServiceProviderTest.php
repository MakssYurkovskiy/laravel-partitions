<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Orchestra\Testbench\TestCase;
use Psr\Log\LoggerInterface;
use Yurkovskiy\Partitions\Contracts\PartitionRepository;
use Yurkovskiy\Partitions\Events\PartitionsCreated;
use Yurkovskiy\Partitions\Facades\Partition;
use Yurkovskiy\Partitions\PartitionManager;
use Yurkovskiy\Partitions\PartitionServiceProvider;
use Yurkovskiy\Partitions\Repositories\MySqlPartitionRepository;
use Yurkovskiy\Partitions\Schema\PartitionPeriod;
use Yurkovskiy\Partitions\Strategies\MonthlyStrategy;
use Yurkovskiy\Partitions\Tests\Support\FakeLogger;
use Yurkovskiy\Partitions\Tests\Support\FakePartitionRepository;

class PartitionServiceProviderTest extends TestCase
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PartitionServiceProvider::class];
    }

    /**
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return ['Partition' => Partition::class];
    }

    protected function enabledSchedule($app): void
    {
        $app['config']->set('partitions.schedule', [
            'enabled' => true,
            'cron' => '0 4 * * *',
            'on_one_server' => true,
        ]);
    }

    protected function enabledScheduleOnEveryServer($app): void
    {
        $app['config']->set('partitions.schedule', [
            'enabled' => true,
            'on_one_server' => false,
        ]);
    }

    protected function enabledScheduleWithNothingElse($app): void
    {
        $app['config']->set('partitions.schedule', ['enabled' => true]);
    }

    protected function enabledScheduleWithAnEmptyCron($app): void
    {
        $app['config']->set('partitions.schedule', [
            'enabled' => true,
            'cron' => '',
            'on_one_server' => true,
        ]);
    }

    protected function configuredTable($app): void
    {
        $app['config']->set('partitions.prefix', 'txn');
        $app['config']->set('partitions.tables', [
            'transactions' => [
                'strategy' => 'monthly',
                'column' => 'processed_at',
                'retain' => 90,
            ],
        ]);
    }

    protected function tableConfiguredWithAPeriodCase($app): void
    {
        $app['config']->set('partitions.tables', [
            'transactions' => [
                'strategy' => PartitionPeriod::Monthly,
                'column' => 'created_at',
            ],
        ]);
    }

    public function test_the_package_config_is_merged_into_the_application(): void
    {
        $config = config('partitions');

        self::assertSame('p', $config['prefix']);
        self::assertSame('pMax', $config['max_partition']);
        self::assertSame('to_days', $config['expression']);
        self::assertSame(7, $config['future']);
        self::assertSame(1, $config['chunk']);
        self::assertArrayHasKey('sync_chunk', $config);
        self::assertNull($config['sync_chunk']);
        self::assertTrue($config['fill_gaps']);
        self::assertSame(12, $config['max_gaps']);
        self::assertFalse($config['verify_timezone']);
        self::assertFalse($config['schedule']['enabled']);
        self::assertSame('15 3 * * *', $config['schedule']['cron']);
        self::assertTrue($config['schedule']['on_one_server']);
        self::assertSame([], $config['tables']);
    }

    public function test_the_config_file_is_publishable(): void
    {
        $paths = ServiceProvider::pathsToPublish(PartitionServiceProvider::class, 'partitions-config');

        self::assertCount(1, $paths);
        self::assertStringEndsWith('config/partitions.php', (string) array_key_first($paths));
        self::assertSame($this->app->configPath('partitions.php'), reset($paths));
    }

    public function test_the_repository_is_shared_and_the_manager_is_built_on_every_resolve(): void
    {
        $repository = $this->app->make(PartitionRepository::class);
        $manager = $this->app->make(PartitionManager::class);

        self::assertInstanceOf(MySqlPartitionRepository::class, $repository);
        self::assertSame($repository, $this->app->make(PartitionRepository::class));
        self::assertNotSame($manager, $this->app->make(PartitionManager::class));
        self::assertNotSame($manager, $this->app->make('partitions'));
        self::assertNotSame(Partition::getFacadeRoot(), Partition::getFacadeRoot());
    }

    public function test_events_faked_after_artisan_has_loaded_reach_the_facade(): void
    {
        Artisan::call('list');
        Partition::table('transactions');

        Event::fake();
        $this->app->instance(PartitionRepository::class, $repository = FakePartitionRepository::withPartitions([
            'p20250620' => '739788',
            'pMax' => 'MAXVALUE',
        ]));

        Partition::table('transactions')->daily('created_at')->between('2025-06-25', '2025-06-26')->sync();

        self::assertCount(1, $repository->executed);
        Event::assertDispatched(PartitionsCreated::class);
    }

    public function test_a_logger_swapped_after_artisan_has_loaded_reaches_the_facade(): void
    {
        Artisan::call('list');
        Partition::table('transactions');

        $this->app->instance('log', $logger = new FakeLogger);
        $this->app->instance(PartitionRepository::class, FakePartitionRepository::withPartitions([
            'p20250620' => '739788',
            'pMax' => 'MAXVALUE',
        ]));

        Partition::table('transactions')->daily('created_at')->between('2025-06-25', '2025-06-26')->sync();

        self::assertCount(1, $logger->records);
    }

    public function test_config_set_after_artisan_has_loaded_reaches_the_facade_and_the_commands(): void
    {
        Artisan::call('list');
        Partition::table('transactions');

        self::assertFalse(Partition::isConfigured('transactions'));

        $this->app->instance(PartitionRepository::class, new FakePartitionRepository);
        $this->app['config']->set('partitions.prefix', 'txn');
        $this->app['config']->set('partitions.tables', [
            'transactions' => ['strategy' => 'monthly', 'column' => 'processed_at', 'future' => 1],
        ]);

        self::assertSame('txn202506', Partition::for('transactions')->partitionNameFor('2025-06-13'));

        Artisan::call('partitions:run', ['--dry-run' => true]);

        self::assertMatchesRegularExpression('/\| transactions\s+\| transactions\s+\|/', Artisan::output());
    }

    public function test_builders_write_to_the_application_log(): void
    {
        $logger = new FakeLogger;
        $repository = FakePartitionRepository::withPartitions(['p20250620' => '739788', 'pMax' => 'MAXVALUE']);

        $this->app->instance('log', $logger);
        $this->app->instance(PartitionRepository::class, $repository);

        self::assertSame($logger, $this->app->make(LoggerInterface::class));

        Partition::table('transactions')->daily('created_at')->between('2025-06-25', '2025-06-26')->sync();

        self::assertCount(1, $repository->executed);
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertStringContainsString('[p20250621] to [p20250624], after [p20250620]', $logger->records[0]['message']);
    }

    #[DefineEnvironment('configuredTable')]
    public function test_the_manager_is_built_from_the_application_config(): void
    {
        $manager = $this->app->make(PartitionManager::class);

        self::assertSame('txn', $manager->config()['prefix']);
        self::assertTrue($manager->isConfigured('transactions'));

        $builder = Partition::for('transactions');

        self::assertSame('transactions', $builder->getTable());
        self::assertSame('processed_at', $builder->getColumn());
        self::assertInstanceOf(MonthlyStrategy::class, $builder->getStrategy());
        self::assertSame('txn202506', $builder->partitionNameFor('2025-06-13'));
        self::assertSame(['transactions'], $manager->configured()->keys()->all());
    }

    #[DefineEnvironment('tableConfiguredWithAPeriodCase')]
    public function test_a_period_case_in_the_config_resolves_to_its_strategy(): void
    {
        $builder = Partition::for('transactions');

        self::assertInstanceOf(MonthlyStrategy::class, $builder->getStrategy());
        self::assertSame('p202506', $builder->partitionNameFor('2025-06-13'));
    }

    public function test_the_artisan_commands_are_registered(): void
    {
        $commands = array_keys(Artisan::all());

        self::assertContains('partitions:list', $commands);
        self::assertContains('partitions:add', $commands);
        self::assertContains('partitions:drop', $commands);
        self::assertContains('partitions:run', $commands);
    }

    public function test_the_schema_macros_are_registered(): void
    {
        self::assertTrue(Blueprint::hasMacro('partitionByRange'));
        self::assertTrue(Blueprint::hasMacro('removePartitioning'));
        self::assertTrue(Grammar::hasMacro('compilePartitionBy'));
        self::assertTrue(Grammar::hasMacro('compileRemovePartitioning'));
    }

    public function test_nothing_is_scheduled_by_default(): void
    {
        self::assertSame([], $this->app->make(Schedule::class)->events());
    }

    #[DefineEnvironment('enabledSchedule')]
    public function test_the_maintenance_command_is_scheduled_when_enabled(): void
    {
        $events = $this->app->make(Schedule::class)->events();

        self::assertCount(1, $events);
        self::assertStringContainsString('partitions:run', $events[0]->command);
        self::assertSame('0 4 * * *', $events[0]->expression);
        self::assertTrue($events[0]->withoutOverlapping);
        self::assertTrue($events[0]->onOneServer);
    }

    #[DefineEnvironment('enabledScheduleOnEveryServer')]
    public function test_the_one_server_guard_can_be_switched_off(): void
    {
        $events = $this->app->make(Schedule::class)->events();

        self::assertCount(1, $events);
        self::assertSame('15 3 * * *', $events[0]->expression);
        self::assertTrue($events[0]->withoutOverlapping);
        self::assertFalse($events[0]->onOneServer);
    }

    #[DefineEnvironment('enabledScheduleWithNothingElse')]
    public function test_a_schedule_entry_without_the_other_keys_keeps_their_defaults(): void
    {
        $events = $this->app->make(Schedule::class)->events();

        self::assertCount(1, $events);
        self::assertSame('15 3 * * *', $events[0]->expression);
        self::assertTrue($events[0]->onOneServer);
    }

    #[DefineEnvironment('enabledScheduleWithAnEmptyCron')]
    public function test_an_empty_cron_runs_on_the_default_schedule(): void
    {
        $events = $this->app->make(Schedule::class)->events();

        self::assertCount(1, $events);
        self::assertSame('15 3 * * *', $events[0]->expression);
    }
}

<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Yurkovskiy\Partitions\Console\AddPartitionsCommand;
use Yurkovskiy\Partitions\Console\DropPartitionsCommand;
use Yurkovskiy\Partitions\Console\ListPartitionsCommand;
use Yurkovskiy\Partitions\Console\RunPartitionsCommand;
use Yurkovskiy\Partitions\Contracts\PartitionRepository;
use Yurkovskiy\Partitions\Repositories\MySqlPartitionRepository;
use Yurkovskiy\Partitions\Schema\PartitionMacros;

class PartitionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath(), 'partitions');

        $this->app->singleton(PartitionRepository::class, fn ($app) => new MySqlPartitionRepository(
            $app->make(ConnectionResolverInterface::class)
        ));

        $this->app->singleton(PartitionManager::class, fn ($app) => new PartitionManager(
            $app->make(PartitionRepository::class),
            $app->bound(Dispatcher::class) ? $app->make(Dispatcher::class) : null,
            (array) $app['config']->get('partitions', []),
            $app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null,
        ));

        $this->app->alias(PartitionManager::class, 'partitions');
    }

    public function boot(): void
    {
        PartitionMacros::register();

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([$this->configPath() => $this->app->configPath('partitions.php')], 'partitions-config');

        $this->commands([
            AddPartitionsCommand::class,
            DropPartitionsCommand::class,
            ListPartitionsCommand::class,
            RunPartitionsCommand::class,
        ]);

        $this->scheduleMaintenance();
    }

    protected function scheduleMaintenance(): void
    {
        $schedule = (array) $this->app->make(ConfigRepository::class)->get('partitions.schedule', []);

        if (! ($schedule['enabled'] ?? false)) {
            return;
        }

        $cron = (string) ($schedule['cron'] ?? '');

        $this->app->booted(function () use ($schedule, $cron): void {
            $event = $this->app->make(Schedule::class)
                ->command(RunPartitionsCommand::class)
                ->cron($cron === '' ? '15 3 * * *' : $cron)
                ->withoutOverlapping();

            if ($schedule['on_one_server'] ?? false) {
                $event->onOneServer();
            }
        });
    }

    protected function configPath(): string
    {
        return __DIR__.'/../config/partitions.php';
    }
}

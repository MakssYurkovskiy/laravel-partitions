# yurkovskiy/laravel-partitions

[![tests](https://github.com/MakssYurkovskiy/laravel-partitions/actions/workflows/tests.yml/badge.svg)](https://github.com/MakssYurkovskiy/laravel-partitions/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/yurkovskiy/laravel-partitions)](https://packagist.org/packages/yurkovskiy/laravel-partitions)
[![PHP Version](https://img.shields.io/packagist/dependency-v/yurkovskiy/laravel-partitions/php)](https://packagist.org/packages/yurkovskiy/laravel-partitions)
[![License](https://img.shields.io/packagist/l/yurkovskiy/laravel-partitions)](LICENSE)

Flexible MySQL partition management for Laravel.

Describe how a table is sliced in time, and the package takes care of the DDL: partitioning an
existing table, pre-creating the upcoming partitions, dropping the expired ones, and reporting what
is currently on disk.

Declare the layout in a migration:

```php
Schema::create('transactions', function (Blueprint $table) {
    $table->id();
    $table->dateTime('created_at');
    $table->primary(['id', 'created_at']);

    $table->partitionByRange('created_at')->daily()->future(30);
});
```

Then keep it covered and pruned, forever:

```php
use Yurkovskiy\Partitions\Facades\Partition;

Partition::table('transactions')
    ->daily('created_at')     // one partition per day
    ->future(14)                // always keep two weeks of partitions ahead
    ->retain(90)                // keep the last 90 days plus today, drop older
    ->maintain();               // add what is missing, prune what expired
```

## Contents

- [Install](#install)
- [Quick start](#quick-start)
- [Artisan commands](#artisan-commands)
- [Documentation](#documentation)
- [License](#license)

## Install

```bash
composer require yurkovskiy/laravel-partitions
php artisan vendor:publish --tag=partitions-config
```

PHP 8.2 or newer with Laravel 12, PHP 8.3 or newer with Laravel 13, on MySQL or MariaDB — the
suite runs against MySQL 8.0, MySQL 8.4 and MariaDB 11.4. Laravel 11
is not supported: its security window closed in March 2026, so every `11.x` release carries
unpatched advisories and Composer refuses to install one unless the advisory policy is switched off.

The service provider and the `Partition` facade alias are registered through package discovery.

## Quick start

Partition a table that is not partitioned yet:

```php
Partition::table('transactions')
    ->daily('created_at')
    ->between(now()->subMonths(3), now()->addDays(5))
    ->create();
```

Keep it healthy (idempotent, safe to run every night):

```php
Partition::table('transactions')->daily('created_at')->future(14)->sync();     // add
Partition::table('transactions')->daily('created_at')->retain(90)->prune();    // drop
```

Inspect it:

```php
$builder = Partition::table('transactions')->daily('created_at');

$builder->isPartitioned();              // bool
$builder->existing();                   // Collection<PartitionInfo>: name, rows, size, description
$builder->missing();                    // Collection<PendingPartition> for the configured range
$builder->plan();                       // PartitionPlan: pending (what sync() adds) and deferred (left for later)
$builder->gap();                        // PartitionGap|null: periods an explicit from() leaves uncovered
$builder->outdated();                   // Collection<PartitionInfo> prune() would drop
$builder->outdatedAfter($plan);         // Collection<PartitionInfo> maintain($plan) would drop
$builder->inRange('2025-06-01', '2025-06-10');  // Collection<PartitionInfo> dropBetween() would drop
$builder->has(now()->addDay());         // is tomorrow covered?
$builder->partitionNameFor('2025-06-13');  // "p20250613"
```

Everything can be inspected before it runs:

```php
$builder->createStatement();   // ALTER TABLE ... PARTITION BY RANGE (...)
$builder->syncStatements();    // ALTER TABLE ... REORGANIZE PARTITION `pMax` INTO (...)
$builder->dryRun()->prune();   // returns the statements, executes nothing
```

The full list of methods is in the [builder reference](docs/builder.md).

## Artisan commands

```bash
php artisan partitions:list transactions --missing                 # what is there, what is not
php artisan partitions:add transactions --future=30                # add the missing partitions
php artisan partitions:add transactions --create --force           # partition a table, unattended
php artisan partitions:drop transactions --retain=90 --dry-run     # show what retention would drop
php artisan partitions:run                                         # maintain every configured table
```

`partitions:run` walks the tables listed in `config/partitions.php` and is what you schedule; set
`PARTITIONS_SCHEDULE_ENABLED=true` and it schedules itself. `partitions:add` refuses to rebuild a
populated table unless asked with `--create`, and `partitions:drop` prints the partitions, the rows and
the disk space it is about to delete before it asks for confirmation. The three that change a table
take `--dry-run`.
The options, the confirmations and what happens to a table that was left unattended are in
[Artisan commands](docs/commands.md).

## Documentation

- [Builder reference](docs/builder.md) — every method of `Partition::table()`: target, strategy,
  range and retention, operations, and the checks that refuse a table partitioned differently than
  the builder describes.
- [Timezones](docs/timezones.md) — where `unix_timestamp` boundaries land, `verifyTimezone()`, and
  how a date you pass in is read.
- [Artisan commands](docs/commands.md) — `partitions:list`, `partitions:add`, `partitions:drop`,
  `partitions:run`; a table left longer than its retention; filling a long gap.
- [Configuration and scheduled maintenance](docs/configuration.md) — the table catalogue in
  `config/partitions.php`, how empty and `null` values are read, `Partition::for()`, the scheduler.
- [Migrations](docs/migrations.md) — `partitionByRange()` on the `Blueprint`, editing an existing
  table, how the clause compiles.
- [Events and errors](docs/events-and-errors.md) — the four events, the log warning, and every
  exception the package throws.
- [Custom strategies](docs/custom-strategies.md) — quarters, fortnights or hours through
  `PartitionStrategy`.
- [How it maps to MySQL](docs/mysql-mapping.md) — `REORGANIZE PARTITION pMax`, back filling,
  identifiers, subpartitions, read/write connections.
- [Coming from `app/Services/Partition`](docs/coming-from-app-services-partition.md) — the method
  map and the behaviour differences.
- [Tests](docs/tests.md) — the unit, feature and integration suites, and how to point the last one
  at a server.

## License

Released under the [MIT License](LICENSE).

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
- [Builder reference](#builder-reference)
- [Timezones](#timezones)
  - [Dates you pass in](#dates-you-pass-in)
- [Artisan commands](#artisan-commands)
  - [A table left longer than its retention](#a-table-left-longer-than-its-retention)
  - [Filling a long gap](#filling-a-long-gap)
- [Configuration and scheduled maintenance](#configuration-and-scheduled-maintenance)
- [Migrations](#migrations)
- [Events](#events)
  - [Log](#log)
- [Errors](#errors)
- [Custom strategies](#custom-strategies)
- [How it maps to MySQL](#how-it-maps-to-mysql)
- [Coming from app/Services/Partition](#coming-from-appservicespartition)
- [Tests](#tests)
  - [Static analysis and code style](#static-analysis-and-code-style)
- [License](#license)

## Install

```bash
composer require yurkovskiy/laravel-partitions
php artisan vendor:publish --tag=partitions-config
```

PHP 8.2 or newer with Laravel 12, PHP 8.3 or newer with Laravel 13, on MySQL or MariaDB. Laravel 11
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

## Builder reference

### Target

| Method | Description |
| --- | --- |
| `table(string\|Model $table)` | Table name, model instance or model class (the model's connection is picked up). |
| `connection(?string $name)` | Connection the table lives on, ahead of the model's own one. |
| `defaultConnection(?string $name)` | Connection used when neither `connection()` nor the model names one; the facade sets it from `partitions.connection`. |
| `column(string $column)` | Date column the partitioning key is built from. |

The connection is the first one named of: `connection()` (or `--connection`, or `connection` of a
`config/partitions.php` entry), the `$connection` of the model given to `table()`, the global
`partitions.connection` (`PARTITIONS_CONNECTION`), and finally the default connection of the
application. `null` or an empty name counts as not named, so `PARTITIONS_CONNECTION=` keeps a model
on its own connection and everything else on the default one.

A table is named the way the rest of Laravel names it — without the connection's table prefix. The
builder puts the prefix of the connection it runs on in front of the table, after the schema of a
qualified name, as `DB::table()` and `Schema::table()` do: with `'prefix' => 'app_'`, `transactions`
is the table `app_transactions` and `reporting.transactions` is `reporting.app_transactions`. A name,
a model, a key of `config/partitions.php`, the argument of an artisan command and a migration therefore
all reach the same table. Messages, exceptions and events keep the name as it was given; only the SQL
and the `information_schema` lookups carry the prefix.

### Strategy

| Method | Description |
| --- | --- |
| `daily($column)` / `weekly($column)` / `monthly($column)` / `yearly($column)` | Built-in strategies: `p20250613`, `p202524` (ISO week), `p202506`, `p2025`. |
| `strategy($strategy, $column)` | The same four by name — `daily`, `weekly`, `monthly`, `yearly`, their unit (`day`) or its initial (`d`), in any letter case and with surrounding whitespace ignored — or a `PartitionPeriod` case, or a `PartitionStrategy` class/instance. A period name is matched before a class name, so a strategy class that lives in the global namespace and is called `Month` or `D` resolves as the period instead of itself; a namespaced class never collides. |
| `prefix(string $prefix)` | Partition name prefix, `p` by default. |
| `usingToDays()` | `PARTITION BY RANGE (TO_DAYS(col))` — the default, works with `DATE`, `DATETIME`, `TIMESTAMP`. |
| `usingRangeColumns()` | `PARTITION BY RANGE COLUMNS(col)` — readable boundaries, `DATE`/`DATETIME` columns only. |
| `usingUnixTimestamp()` | `PARTITION BY RANGE (UNIX_TIMESTAMP(col))` — for `TIMESTAMP` and `DATETIME` columns. Its boundaries are instants, not calendar dates, see [Timezones](#timezones). |
| `expression($expression)` | The same three by name — `to_days`, `unix_timestamp`, `columns`, read the same way as a strategy name: any letter case, surrounding whitespace ignored — or a `PartitionExpression` case. |
| `timezone($zone)` | Timezone the `unix_timestamp` boundaries are computed in. Defaults to the connection's `timezone`, then to the application one. |
| `verifyTimezone(bool $verify = true)` | Ask the server how it reads the boundaries before running DDL, instead of writing ones that are off by the difference. |
| `tablespace(?string $tablespace)` | Adds ``TABLESPACE = `...` `` to every generated partition. |
| `withMaxPartition(bool\|string $max)` / `withoutMaxPartition()` | Catch-all `MAXVALUE` partition, `pMax` by default. The name is the one `create()`, `createStatement()`, `partitionByClause()` and a migration write, also when a migration replaces the partitioning of a table whose catch-all is named differently; `sync()` keeps the name the table already uses. `withoutMaxPartition()` describes the layout `create()` builds; on a table that already has one, `sync()` throws instead of quietly keeping it (see [below](#how-it-maps-to-mysql)). |

### Range and retention

| Method | Description |
| --- | --- |
| `from($date)` / `to($date)` / `between($from, $to)` | Explicit range, inclusive on both ends. Without `from()` the range starts right after the last existing partition, or at the current period when the table has none. A `from()` whose period lies past the end of the range — past `to()`, or past the look-ahead when `to()` is not set — throws an `InvalidRangeException` instead of describing a range with nothing in it. A `from()` past the period right after the last partition is taken as given, and the periods it skips are reported: `gap()` describes them, `sync()` writes a warning to the log. A date object counts by its own calendar date, see [Dates you pass in](#dates-you-pass-in). |
| `future(int $periods)` | Periods kept created ahead of today when `to()` is not set (default 7). Counted from today, not from the last partition: `future(10)` on a table that already reaches a week ahead adds three periods, and a second run adds none. |
| `retain(?int $periods)` | Complete periods kept by `prune()` **before** the current one: partitions whose period starts before `now - N periods` are dropped, so a daily table under `retain(90)` ends up with 91 of them — the last 90 days and today. `retain(0)` keeps the current period only, `null` disables pruning. The last partition with a boundary is never pruned, however old it is, see [a table left longer than its retention](#a-table-left-longer-than-its-retention). |
| `chunk(?int $size)` | Partitions per `DROP PARTITION` and `TRUNCATE PARTITION` statement (default 1, which keeps metadata locks short). `null` or `0` puts them all in one statement. Adding is split by `syncChunk()`. |
| `syncChunk(?int $size)` | Partitions per statement that adds them (default `null`, all in one statement; `0` means the same). A size of N splits the additions into statements of N partitions each. Every `REORGANIZE` rewrites what is still in `pMax`, so splitting trades extra work for recoverability, see [filling a long gap](#filling-a-long-gap). |
| `fillGaps(bool $fill = true)` | Start from the last covered period instead of today, so periods a table skipped while unattended are created too (default on). With `retain()` set, the skipped periods retention has already expired are not created: filling starts at the retention cutoff. An explicit `from()` is not moved back, but while gap filling is on the periods it skips are warned about; with `fillGaps(false)` the gap is left on purpose and nothing is said. |
| `maxGaps(?int $gaps)` | How many skipped periods one run fills, oldest first (default 12). Only the periods before the current one count: the current period, the look-ahead of `future()` and an explicit `to()` ahead of today are never cut short, but they wait for a later run while more periods were skipped than one run fills — a partition cannot be added below an existing one. `null` or `0` lifts the limit; `pending()` is what the next `sync()` adds, `deferred()` what is left for a later run; `plan()` returns both from one pass over the table. |
| `dryRun(bool $dryRun = true)` | Build the SQL, execute nothing. The table is still read and, with `verifyTimezone()`, the session timezone is still checked, so a dry run throws wherever the real run would before its first statement. |

### Operations

Every executing method returns a `Collection<string>` of the statements it ran — or, in dry run mode,
the ones it would have run.

| Method | Description |
| --- | --- |
| `create()` | Partition a table that is not partitioned yet. Throws when it already is. |
| `sync(?PartitionPlan $plan = null)` | Add the missing partitions of the configured range, stopping after `maxGaps()` skipped periods when the table skipped more. No-op when there is nothing to add. Given a `plan()`, it adds the pending partitions of that plan instead of planning again — the list that was shown is the list that is added. Partitions of the plan the table already has are left out, so a retry with the same plan after a failed statement sends only what is still missing. `syncStatements()` takes a plan the same way. |
| `prune()` | Drop what falls outside `retain()`, except the last partition with a boundary. |
| `dropBefore($date)` | Drop everything older than the given date — its own calendar date for a date object, see [Dates you pass in](#dates-you-pass-in). |
| `dropBetween($from, $to)` | Drop the partitions covering the range, inclusive on both ends. The bounds are normalised, so passing them the other way round selects the same partitions. |
| `maintain(?PartitionPlan $plan = null)` | `['added' => ..., 'dropped' => ...]` — `sync($plan)` plus `prune()`. What is dropped is decided before anything is added, so a dry run lists the drops of a real run. |
| `drop($partitions)` | Drop exactly these partitions — say, the list `outdated()` or `inRange()` returned and somebody looked at. |
| `truncate($partitions)` | Empty partitions without dropping them. |
| `removePartitioning()` | `ALTER TABLE ... REMOVE PARTITIONING`, keeping the data. Throws when the table is not partitioned. |

Drop operations never touch the `MAXVALUE` partition and never touch partitions whose name was not
produced by the current strategy — a hand made `p_legacy` partition is left alone. `drop()` and
`truncate()` are the exception: they take the list they are given as it is.

If the table turns out to be partitioned differently than the builder describes — another expression,
another column, `LIST`/`HASH`/`KEY` instead of `RANGE` — the operation throws instead of silently finding
nothing to do:

```
Table [transactions] is partitioned by [RANGE (unix_timestamp(created_at))] but the builder is
configured for [RANGE (to_days(created_at))].
```

The check runs on both halves of the API — adding (`missing()`, `sync()`) and dropping (`outdated()`,
`prune()`, `dropBefore()`, `dropBetween()`). Name parsing alone is not enough to make dropping safe:
when the table and the builder happen to use the same strategy, the names still decode and the drop
would go through against a table partitioned under a different scheme.

The period is checked on the same calls. A table cut into days and a builder configured for months
agree on the expression and on the column, yet `sync()` would put a month-wide partition right after
the last day, and `prune()` goes by the names alone — weekly `p202506` and monthly `p202506` are both
six digits, so a weekly builder would read a monthly table as weeks of the winter and drop them. Two
things are therefore required of the table: every partition whose name reads as a period of the
builder's strategy ends where that period ends, and the newest partition is not a well-formed period
of another built-in strategy.

```
Table [transactions] holds daily partitions — [p20250613] is one of them — but the builder is
configured for monthly ones. Set the strategy to daily, or repartition the table before managing it.
```

Only the newest partition decides for the second rule, so a table that changed its period on purpose
stays manageable: the yearly `p2023` and `p2024` in front of a monthly table are left alone by a
monthly builder, like any other name its strategy does not produce. Partitions named by hand and
partitions under another prefix are not looked at either.

## Timezones

`to_days` and `columns` boundaries are calendar dates — `TO_DAYS('2025-06-14')` and `'2025-06-14'`
mean the same day wherever the server stands. A `unix_timestamp` boundary is an instant, and MySQL
reads `UNIX_TIMESTAMP('2025-06-14 00:00:00')` in the **session** timezone, so the package has to
compute it in that same zone. Otherwise the boundary lands hours away from the midnight the
partition is named after: the partition covers a slice of the next period, or the period looks
covered while its rows keep falling into `pMax`. Nothing fails loudly — the boundaries stay strictly
increasing, so MySQL accepts the DDL either way.

The reference frame is therefore explicit rather than inherited from `date_default_timezone_get()`:

```php
Partition::table('transactions')->monthly('created_at')
    ->expression('unix_timestamp')
    ->timezone('Europe/Kyiv')   // boundaries are computed in this zone
    ->verifyTimezone()          // and the server is asked to confirm it
    ->sync();
```

`timezone()` falls back to the connection's own `timezone` (`database.connections.*.timezone` — what
Laravel puts the session in) and then to the application timezone; `partitions.timezone` sets it for
every builder, `tables.*.timezone` per table. `null` or an empty name counts as not set, so
`PARTITIONS_TIMEZONE=` falls back the same way and a table entry without a zone of its own keeps
`partitions.timezone`; `'timezone' => false` in the entry puts that one table back on the fallback. Both ends use the one zone, so a boundary read back
from `information_schema` decodes to the date it was written for.

`verifyTimezone()` (config `verify_timezone`, off by default) spends one query before the DDL: it
asks the server for `UNIX_TIMESTAMP(?)` of the current time and of every boundary about to be
written, and refuses the DDL when any of them reads differently. Checking the boundaries themselves
catches a session zone that agrees today and parts ways after a DST change — `UTC` against
`Europe/London` in winter. A datetime the server already confirmed is not asked again on the same
builder. A dry run asks the same question and throws the same `TimezoneMismatchException`, so
`--dry-run` shows a mismatch instead of a plan the real run would refuse. A `partitionByRange()` in a migration is checked the same way when it compiles, in
`Schema::create()` and `Schema::table()` alike:

```
UNIX_TIMESTAMP boundaries for table [transactions] are computed in [Europe/Kyiv], but the connection
reads [2025-06-14 00:00:00] +180 minutes away from it, so boundaries would be off by that much.
```

### Dates you pass in

Every method that takes a date — `from()`, `to()`, `between()`, `inRange()`, `outdated()`,
`dropBefore()`, `dropBetween()`, `partitionNameFor()`, `has()` — reads it as the calendar date it
carries, in its own timezone. `new DateTimeImmutable('2025-06-14', new DateTimeZone('UTC'))`,
`'2025-06-14T23:59:59-10:00'` and `'2025-06-14'` all mean 14 June, whatever the application
timezone is, and select the same partitions. The date is not converted to the application timezone
first: in `America/New_York` the UTC midnight above is still the 13th there, but it is not read as
the 13th.

This is where Laravel puts the row. Eloquent and the query builder write a date object as its own
wall-clock time, without converting it, so a row saved with `now('UTC')` lands in the partition of
the UTC date, and `has(now('UTC'))` answers for that partition. The flip side is that `now('UTC')`
at 01:00 in Kyiv is still yesterday: `dropBefore(now('UTC'))` keeps yesterday's partition until UTC
midnight. Pass a date in the application timezone when that is the day you mean.

## Artisan commands

```bash
php artisan partitions:list transactions --strategy=daily --column=created_at --missing
php artisan partitions:add transactions --from=2025-01-01 --to=2025-03-01 --dry-run
php artisan partitions:add transactions --future=30
php artisan partitions:add transactions --max-gaps=0 --chunk=6   # fill a long gap in steps of 6
php artisan partitions:add transactions --create --force         # rebuild a populated table, unattended
php artisan partitions:drop transactions --before=2025-01-01
php artisan partitions:drop transactions --retain=90 --force   # keep the last 90 days plus today
php artisan partitions:run                    # every configured table
php artisan partitions:run transactions --dry-run
```

`partitions:add` adds the missing partitions to a partitioned table. A table that is not partitioned
yet is partitioned only with `--create`; without it the command stops with an error that names the
flag, in a dry run and a non-interactive run too. Partitioning an existing table is a full
`ALTER TABLE ... PARTITION BY`: MySQL rebuilds the table and holds it locked until it finishes, so a
scheduler run against a table whose partitioning was removed, or a table name that points at the wrong
table, must not start one on its own. With `--create` the command prints the estimated rows and disk
space the rebuild has to move, lists the partitions it is about to create, and asks for confirmation.
`--create` on a table that is already partitioned fails. Adding partitions to an already partitioned
table is not confirmed — but it is not free either when the table has a `MAXVALUE` partition: see
[filling a long gap](#filling-a-long-gap).

A table that is not in `config/partitions.php` is taken for a daily one unless `--strategy` says
otherwise. When it is already partitioned by another period, the commands stop with a
`StrategyMismatchException` that names the period found, instead of adding days to a monthly table
or dropping its months as if they were weeks.

Without `--from`, a run starts right after the last partition the table has, not today:
`partitions:add transactions --future=10` on a table that ends at `p20250620` starts at `p20250621`,
whether today is a week before that partition or a month after it — in the second case the skipped
days are created too, unless `fill_gaps` is off, which makes a table that fell behind start today.
The end is counted from today: `--future=10` reads "ten periods ahead of today", so the command adds
what is missing up to that day and nothing once the table reaches it, which is what makes it safe to
repeat from a scheduler. A `--from` earlier than the last partition adds nothing under it.

A `--from` later than the end of the range stops the command with an `InvalidRangeException` before a
statement is built: `--from=2025-06-25 --to=2025-06-21`, or `--from=2025-06-25 --future=10` on a day
when ten periods ahead end on the 23rd. Such a range covers no period, and the command used to report
it as "all partitions already exist". The two ends are compared by period, so two dates of one month
are a range on a monthly table whichever comes first, and a `--to` under the last partition with no
`--from` is still answered with "already exist", which is true. `partitions:drop` reads its pair the
other way: there the bounds are order-insensitive.

A `--from` past the period right after the last partition is taken as given, with a warning. On a
table that ends at `p20250620`, `--from=2025-06-25 --to=2025-06-26` creates `p20250625` and
`p20250626` and nothing for the 21st to the 24th. A `RANGE` partition holds everything between the
boundary under it and its own, so the rows of those four days land in `p20250625` — and leave with
it when `partitions:drop` or `prune()` drops the partition named after the 25th. The command says
so before the statements, in a dry run too:

```text
The start date 2025-06-25 leaves 4 periods of table [transactions] without a partition of their own
— [p20250621] to [p20250624], after [p20250620]. Their rows land in [p20250625] and are dropped
together with it. Start at 2025-06-21, or leave the start date out, to give each period its
partition.
```

The same sentence goes to the application log as a warning once the statements have run — from
`sync()` itself, so a call from code is recorded as well as the command (see [Log](#log)). With
`fill_gaps` off the gap is left on purpose: nothing is printed and nothing is logged.

### A table left longer than its retention

When a table sat unattended for longer than `retain()` covers, the oldest periods it skipped are
already expired: creating them would only hand them to `prune()` in the same run. With `retain()` set
they are not planned at all — `missing()`, `plan()`, `sync()` and `maintain()` start at the retention
cutoff, and the first partition created there takes the rows of the periods before it. An explicit
`from()` is taken as given, as always.

The next run starts right after the last partition that has a boundary, so that partition is the one
`prune()`, `partitions:drop --retain` and `outdated()` never select: without it a table reduced to
`pMax` would be continued from today, and every row since the gap would land in today's partition.
It is dropped as soon as a newer partition exists — `maintain()` adds first, so it goes in the same
run. `dropBefore()`, `dropBetween()` and `drop()` do exactly what they are told.

### Filling a long gap

`REORGANIZE PARTITION pMax` is not a metadata-only operation: MySQL copies every row currently in
`pMax` into the partitions it is split into. On a table that was left unattended this is the whole
backlog — the rows of all the skipped periods sit in `pMax` — so the one statement that fills the gap
rewrites all of it under a metadata lock.

Two knobs bound that work:

- `--max-gaps` (`maxGaps()`, config `max_gaps`, default 12) fills the oldest skipped periods only and
  reports how many partitions are left; the next run picks up where this one stopped. `0` lifts the
  limit. It counts the periods before the current one and nothing else: on a table that is up to
  date `--future=30` adds all thirty partitions in one run. The periods from today on are added by
  the run that fills the last of the skipped ones.
- `--chunk` (`syncChunk()`, config `sync_chunk`, default all in one statement) splits the additions
  into a sequence of statements of that many partitions each. The lock is released between them and
  the partitions already added survive a failure further down the list. The builder reads the table
  again after a failure, so calling `sync()` once more, on the same builder too, sends only what is
  still missing. The total work grows, though: every statement rewrites what is still in `pMax` at
  that point. The first statement is as heavy as the unsplit one, so chunking buys recoverability,
  not a shorter worst case. On `partitions:drop` the option sets `chunk()`, the partitions dropped
  per statement.

A table with years of backlog is cheapest to fix in a maintenance window with `--max-gaps=0` (one
statement, one rewrite), or by moving the data out, rebuilding with `create()` and loading it back.

`partitions:drop` prints the partitions, the estimated row count and the disk space it is about to
delete, and asks for confirmation too. Its `--from`/`--to` pair is inclusive and order-insensitive:
swapped bounds select the same partitions rather than an empty range. The pair goes together: either
one alone stops the command before anything is selected, in a dry run and on an unpartitioned table
too. The command does not pick the missing end — an end of "now" would take the partition rows are
being written to, and a start of "the first partition" would take the whole history. What gets
dropped is the list it printed: a confirmation answered after midnight does not widen `--retain` or a
relative `--before` by the day that has just begun. Both confirmations are skipped by `--force`, by
`--dry-run` and in a non-interactive run (`--no-interaction`, a queue worker, the scheduler), so automation keeps
working unattended. Skipping the question does not skip `--create`: an unattended rebuild still has
to name it.

The first argument is either a raw table name — described by `--strategy`, `--column`,
`--expression`, `--prefix`, `--connection` — or a key from `config/partitions.php`, in which case the
options are only overrides. `--strategy` takes a period by its name or a shorthand (`daily`, `day`,
`d`) or the class name of a custom strategy, `--expression` takes `to_days`, `unix_timestamp` or
`columns`; `--help` lists both from the enums that define them. An option passed empty
(`--tablespace=`) is not an override. `--future`, `--retain`, `--chunk` and `--max-gaps` take a whole
number, zero or above: anything else — `--retain=abc`, `--future=-1` — fails the command before a
statement is built, instead of being read as `0`.

## Configuration and scheduled maintenance

`config/partitions.php` holds the defaults and the table catalogue:

```php
'tables' => [
    'transactions' => [
        'strategy' => 'daily',
        'column' => 'created_at',
        'future' => 14,
        'retain' => 90,
    ],
    'transactions_archive' => [
        'connection' => 'archive',
        'strategy' => 'monthly',
        'column' => 'created_at',
        'expression' => 'columns',
        'future' => 3,
        'retain' => 24,
    ],
    'user_transactions_v2' => [
        'strategy' => 'monthly',
        'column' => 'created_at',
        'expression' => 'unix_timestamp',
        'max_partition' => 'p_future',
        'future' => 6,
        'max_gaps' => 3,
        'sync_chunk' => 3,
    ],
],
```

`strategy` also takes a `PartitionPeriod` case, written with its full name —
`\Yurkovskiy\Partitions\Schema\PartitionPeriod::Monthly` — because the published config file imports
nothing.

An empty value counts as a key that is not there, for every setting, globally and per table. With
`'retain' => env('TRANSACTIONS_RETAIN', 90)` and a bare `TRANSACTIONS_RETAIN=` in `.env`, `env()`
returns `''` rather than `90`, and the table is not pruned at all, as if `retain` were not given —
never `retain(0)`. An empty table setting keeps the global one. `null` reads the same way for every
setting but four: for `retain`, `chunk`, `sync_chunk` and `max_gaps` it keeps the meaning listed in
the config file — no pruning, one statement, no limit. So `'timezone' => env('TRANSACTIONS_TZ')`
without the variable leaves the table on `partitions.timezone`. What turns a global `tablespace`,
`timezone` or `max_partition` off for one table is `false`.

Configured tables are addressable by key:

```php
Partition::for('transactions')->sync();
Partition::configured()->each(fn ($builder) => $builder->maintain());
```

`configured()` builds every table up front, so a single entry that cannot be built — an unknown
timezone, strategy or expression, or an entry that is not an array of settings, such as
`'transactions' => 'daily'` or a `null` left behind by a commented-out array — throws before any
table is maintained. `configuredKeys()` lists every key of `tables` without building anything, and
`isConfigured()` answers for a key that is there, whatever it holds. `partitions:run` walks those
keys and builds each table on its own: a broken entry, a table that does not exist or is not
partitioned, or a table whose maintenance fails, is reported with its error, the rest are still
maintained, and the command exits with a failure code.

A key is looked up as it is written, so a schema-qualified `'reporting.transactions' => [...]` is one
table and not a path into a nested array.

Set `PARTITIONS_SCHEDULE_ENABLED=true` to have `partitions:run` scheduled automatically
(`partitions.schedule.cron`, `withoutOverlapping()`, optionally `onOneServer()`); an empty
`PARTITIONS_SCHEDULE_CRON=` keeps the default `15 3 * * *`. Or schedule it yourself:

```php
Schedule::command('partitions:run')->dailyAt('03:15')->onOneServer();
```

## Migrations

Partitioning is declared on the `Blueprint`, next to the columns it depends on:

```php
public function up(): void
{
    Schema::create('transactions', function (Blueprint $table) {
        $table->id();
        $table->dateTime('created_at');

        // Every unique key must contain the partitioning column.
        $table->primary(['id', 'created_at']);

        $table->partitionByRange('created_at')
            ->daily()
            ->between(now()->subMonths(3), now()->addDays(5))
            ->tablespace('innodb_file_per_table');
    });
}

public function down(): void
{
    Schema::dropIfExists('transactions');
}
```

`partitionByRange($column)` returns a `PartitionDefinition` — the declarative half of the builder:
`daily()` / `weekly()` / `monthly()` / `yearly()` / `strategy()`, `prefix()`, `usingToDays()` /
`usingUnixTimestamp()` / `usingRangeColumns()` / `expression()`, `timezone()`, `from()` / `to()` /
`verifyTimezone()`, `between()` / `future()`, `withMaxPartition()` / `withoutMaxPartition()`,
`tablespace()`, `replacePartitioning()`. The executing methods are deliberately **not** exposed: inside a `Schema::create` callback the table does not exist yet, so nothing may run
there. With no range declared, the configured `future` is used (7 periods from today by default).

The definition starts from `config/partitions.php`, the way `Partition::for()` does. A table with an
entry under `tables` — matched by its `table`, or by the key when the entry names none — starts from
that entry on top of the global settings: `strategy`, `expression`, `timezone`, `verify_timezone`,
`prefix`, `max_partition`, `tablespace` and `future`. Any other table starts from the global settings
alone. What the migration declares goes on top of both, and the column is always the one given to
`partitionByRange()`. This is what keeps the boundaries a migration writes in line with the ones
`partitions:run` adds later — a `timezone` set for the table only would otherwise be missing from
the migration. An entry that names a connection, its own or its model's, is used on that connection
only; an entry that names none is used wherever the migration runs.

### Editing an existing table

The same declaration works inside `Schema::table()`, which is how a table that already exists gets
partitioned:

```php
Schema::table('transactions', function (Blueprint $table) {
    $table->partitionByRange('created_at')->daily()->from('2025-01-01')->future(30);
});
```

To un-partition a table, keeping the data:

```php
Schema::table('transactions', fn (Blueprint $table) => $table->removePartitioning());
```

Because the table exists in this case, its actual state is checked when the statement compiles:

| Declaration | Table is partitioned | Table is not partitioned |
| --- | --- | --- |
| `partitionByRange(...)` | throws — the ALTER would discard the current layout | compiles; partitions the table |
| `removePartitioning()` | compiles | throws — nothing to remove |

Re-declaring the layout of an already partitioned table is possible, but it has to be asked for,
because MySQL replaces the whole partitioning and rebuilds the table:

```php
Schema::table('transactions', function (Blueprint $table) {
    $table->partitionByRange('created_at')
        ->monthly()
        ->replacePartitioning()   // yes, discard the daily layout and rebuild
        ->from('2025-01-01');
});
```

Only `Schema::table()` is checked — a `Schema::create()` describes a table that does not exist yet,
so there is nothing to inspect. With `verify_timezone` on and `unix_timestamp` boundaries, both also
ask the server for its session timezone (see [Timezones](#timezones)). Every one of these checks is
skipped under `migrate --pretend`, where every read returns an empty set and the table would look
unpartitioned whatever it really is.

### How it compiles

The clause is emitted as a **separate** `ALTER TABLE ... PARTITION BY RANGE (...)` statement, run
right after the `CREATE TABLE` of the same blueprint:

```sql
create table `transactions` (`id` bigint unsigned not null auto_increment primary key, ...)
ALTER TABLE `transactions`
PARTITION BY RANGE (TO_DAYS(`created_at`)) (
    PARTITION `p20250613` VALUES LESS THAN (739781),
    PARTITION `pMax` VALUES LESS THAN MAXVALUE
)
```

`ALTER TABLE ... PARTITION BY` rebuilds the table — but the table a migration has just created is
empty, so the rebuild is free. On `Schema::table()` of a populated table it is not: that is the same
full rebuild `create()` does, and it needs a maintenance window.

Two details worth knowing:

- The declaration is compiled through `Blueprint` and schema-grammar **macros**, which is a supported
  extension point (`Blueprint::toSql()` resolves `compile{Command}` through `$grammar::hasMacro()` as
  well as `method_exists()`) — no grammar is subclassed or replaced.
- Grammar macros are stored per `Illuminate\Database\Grammar`, so they are visible to every driver's
  grammar. The driver is therefore checked when the command compiles: a non-MySQL connection throws
  instead of being handed MySQL DDL. Guard a mixed-driver migration yourself if it has to be portable.

### Migrations are not maintenance

A migration runs once; `p20260312` has to exist on 2026-03-11, and every day after that. So the
Blueprint layer declares the **initial** layout only — keeping the table covered and pruned stays
with `partitions:run` / `Partition::for(...)->maintain()` and the table catalogue in
`config/partitions.php`.

Need the raw clause instead (for a hand written `CREATE TABLE`)?

```php
$clause = Partition::table('transactions')->daily('created_at')->future(30)->partitionByClause();
```

## Events

Every operation that changed something dispatches an event after a successful run — never in dry run
mode, and never when there was nothing to do. All four carry the same payload: the table, the
connection, the partition names and the statements.

| Event | Dispatched by |
| --- | --- |
| `Yurkovskiy\Partitions\Events\PartitionsCreated` | `create()`, `sync()` |
| `PartitionsDropped` | `prune()`, `dropBefore()`, `dropBetween()`, `drop()` |
| `PartitionsTruncated` | `truncate()` |
| `PartitioningRemoved` | `removePartitioning()`, with the partitions the table had before |

The manager behind `Partition::` and the artisan commands is built again every time it is resolved,
from the container and the config as they are at that moment. So in the tests of an application
`Event::fake()`, `Log::spy()`, `config(['partitions.tables' => ...])` and a `PartitionRepository`
bound to a fake apply to the next `Partition::` call and the next command run, however early the
package was first used. A `PartitionManager` injected into a long-lived object of your own keeps
what it was built with.

### Log

`sync()` writes one thing to the log: a warning when an explicit `from()` skipped periods after the
last partition, so their rows share the first partition created. The message is the one
`partitions:add` prints, and the context carries the same facts as an array — `table`, `from`,
`last`, `first_skipped`, `last_skipped`, `skipped` (a count), `holder` (the partition the rows land
in) and `continue_at` (the start that leaves no gap). Like the events, it is written after a
successful run only — never in dry run mode, and never with `fillGaps(false)`.

The logger is the application's `Psr\Log\LoggerInterface`, handed to every builder the manager or
the facade makes. A builder constructed by hand takes it as the third argument and stays silent
without one:

```php
new PartitionBuilder($repository, $events, $logger);
```

`gap()` answers the same question before anything runs: it returns a `PartitionGap` — the fields
above as properties, `message()` and `toArray()` — or `null` when the range skips nothing.

## Errors

Everything the package refuses to do throws an exception from `Yurkovskiy\Partitions\Exceptions`.
They all extend `PartitionException`, so `catch (PartitionException)` still covers the package as a
whole, while a single case can be caught on its own.

| Exception | Thrown when |
| --- | --- |
| `UnknownTableException` | the table key is missing from `config/partitions.php` — `Partition::for()`, `partitions:run {table}` |
| `InvalidTableConfigException` | the table key is in `config/partitions.php`, but holds something other than an array of settings — `Partition::for()`, `configured()`, the artisan commands, and a migration of the table named by that key |
| `UnknownStrategyException` | `strategy()` got a name that is neither daily/weekly/monthly/yearly nor a `PartitionStrategy` class, or a configured table's `strategy` is not a name, a `PartitionPeriod` case or a strategy at all |
| `UnknownExpressionException` | `expression()` got something other than `to_days`, `unix_timestamp` or `columns` |
| `MissingStrategyException` | the builder reached SQL without a strategy |
| `MissingTableException` | the builder reached SQL without a table |
| `MissingDefinitionException` | the `partitionBy` blueprint command lost its `PartitionDefinition` |
| `InvalidIdentifierException` | a table, column, partition or tablespace name is outside `[A-Za-z0-9_$]` |
| `InvalidTimezoneException` | `timezone()` got an identifier PHP does not know |
| `InvalidDateException` | a date given as a string is blank or is not one PHP can read — `from()`, `to()`, `between()`, `has()`, `partitionNameFor()`, `inRange()`, `outdated()`, `dropBefore()`, `dropBetween()`, the same methods in a migration, and the `--from`, `--to` and `--before` options; Carbon's own exception is kept as `getPrevious()` |
| `InvalidRangeException` | `from()` lies past the end of the range — `to()`, or today plus `future()` when `to()` is not set — so `create()`, `sync()` or a migration would cover no period |
| `TimezoneMismatchException` | `verifyTimezone()` found the session timezone away from the configured one, on the builder or in a migration |
| `AlreadyPartitionedException` | `create()` on a partitioned table, or `partitionByRange()` in a migration without `replacePartitioning()` |
| `NotPartitionedException` | `removePartitioning()` on a table that is not partitioned, from the builder or from a migration |
| `MaxPartitionConflictException` | `sync()` with `withoutMaxPartition()` against a table that has a `MAXVALUE` partition |
| `ExpressionMismatchException` | the table is partitioned by another expression, column or method than the builder describes |
| `StrategyMismatchException` | the table was cut by another period than the builder's strategy — a daily table under `monthly()` — or a partition named like a period of the strategy ends somewhere else |
| `UnsupportedGrammarException` | a blueprint command compiles on a non-MySQL grammar |
| `UnsupportedDriverException` | a read or a statement runs on a non-MySQL connection |

## Custom strategies

The four built-in periods live in the `Yurkovskiy\Partitions\Schema\PartitionPeriod` enum, and that is
the whole list. For anything else — quarters, fortnights, hours — implement
`Yurkovskiy\Partitions\Contracts\PartitionStrategy`, or extend `AbstractStrategy` and define three
methods — `start()`, `next()` and `format()` — then pass the class anywhere a strategy name is
accepted. `AbstractStrategy` names a strategy after the `PartitionPeriod` case that builds it, and a
strategy no case builds after its own class, so `name()` is worth overriding only to give it a
shorter name. A class named instead of an instance is constructed with no arguments, so its
constructor has to run without any — `AbstractStrategy` already defaults the prefix — and the
builder's `prefix()` is put on the instance afterwards:

```php
/** Quarters named after their first month: p202501, p202504, p202507, p202510. */
class QuarterlyStrategy extends AbstractStrategy
{
    public function name(): string
    {
        return 'quarterly';
    }

    public function start(DateTimeInterface $date): CarbonImmutable
    {
        return CarbonImmutable::instance($date)->firstOfQuarter()->startOfDay();
    }

    public function next(DateTimeInterface $date): CarbonImmutable
    {
        return $this->start($this->start($date)->addQuarter());
    }

    protected function format(): string
    {
        return 'Ym';
    }
}

Partition::table('transactions')->strategy(QuarterlyStrategy::class, 'created_at')->sync();
```

`next()` goes through `start()` twice on purpose. Where a clock change skips midnight —
`America/Santiago`, `Africa/Cairo` — that day starts at 01:00, and a period added to it has to be
brought back to the midnight the next one starts at; otherwise a `unix_timestamp` partition ends an
hour late.

A partition name is the prefix followed by the period start in `format()`, and it is held to the
same `[A-Za-z0-9_$]` as every other identifier: `Y_m` names `p2025_06`, while `Y-m` makes
`partitionName()` throw an `InvalidIdentifierException` that names the strategy, before any SQL is
built. Names are read back with `CarbonImmutable::createFromFormat()` and taken only when they format
back to the same name, which is what lets retention and `partitions:drop --from/--to` find them; a
format `createFromFormat()` cannot read, such as the ISO week `oW`, needs `decode()` overridden the
way `WeeklyStrategy` does.

## How it maps to MySQL

- Partitions are `RANGE` partitions. A partition named after day `D` ends at the boundary of `D + 1`,
  because `VALUES LESS THAN` is exclusive: `p20250613 VALUES LESS THAN (TO_DAYS('2025-06-14'))`.
- `TO_DAYS()` values are computed in PHP (verified against MySQL 8.0), so building the SQL needs no
  database round trip.
- Adding partitions to a table that has a `MAXVALUE` partition is done with
  `REORGANIZE PARTITION pMax INTO (..., PARTITION pMax VALUES LESS THAN MAXVALUE)`; the `MAXVALUE`
  partition is always re-created, otherwise MySQL refuses to shrink the covered range. It is
  re-created under the name the table already uses, whatever `withMaxPartition()` names — this is
  the only place the existing name is looked up. Without a
  `MAXVALUE` partition the package emits a plain `ADD PARTITION`. The reorganisation rewrites the
  rows held by `pMax`, so it is only cheap while `pMax` is close to empty — `maxGaps()` and `syncChunk()`
  bound it when it is not (see [filling a long gap](#filling-a-long-gap)).
- Because of that re-creation, `withoutMaxPartition()` and an existing `MAXVALUE` partition are a
  contradiction `sync()` cannot resolve: reorganising `pMax` into ranges that stop short of
  `MAXVALUE` is what MySQL rejects as soon as a single row sits above the last range, and keeping it
  ignores the configuration. So `sync()` throws and names the way out — `withMaxPartition()` to keep
  the partition, or `removePartitioning()` plus `create()` to rebuild the layout without it.
  `create()` itself honours `withoutMaxPartition()`, because there is nothing to reorganise yet.
- Ranges below the highest existing boundary are skipped: MySQL cannot insert a range under an
  existing one, so back filling is impossible by design and never attempted. Periods *between* the
  last boundary and today are created (see `fillGaps()`), which keeps partition names truthful —
  otherwise the rows of the skipped days end up in the next partition created. An explicit `from()`
  past those periods is the one way to skip them while gap filling is on: it is taken as given, and
  warned about in the command output and in the log.
- MySQL requirements that the package cannot lift: every unique/primary key must contain the
  partitioning column, foreign keys are not supported on partitioned tables, and `create()` rebuilds
  the whole table — run it on an empty table or during a maintenance window.
- Table, column, partition and tablespace names are validated (`[A-Za-z0-9_$]`) and back-quoted:
  identifiers cannot be bound as query parameters, so they are checked instead. The pattern is
  deliberately narrower than MySQL's own rules — a name that is legal only while quoted, such as
  `my-table`, `order date` or a non-ASCII one, is rejected with an `InvalidIdentifierException`
  instead of being passed into DDL. Only a table name may be qualified: it is split on the dot and
  each part checked separately, so `reporting.transactions` is fine. A column, partition or
  tablespace name with a dot in it, such as `p.max`, is rejected like any other.
- Subpartitions are folded away when the partition list is read: `information_schema.PARTITIONS`
  holds one row per *sub*partition, so a subpartitioned table repeats every `PARTITION_NAME` and
  splits its row estimate and its size across those rows. The package reports one entry per
  partition, with the subpartition rows and sizes summed into it, so `partitions:list` and the
  "will be deleted" figures of `partitions:drop` stay truthful. Subpartitions themselves are not
  managed — the DDL only ever names partitions.
- Every read and every statement checks the driver of the connection first: a non-MySQL connection
  throws an `UnsupportedDriverException` naming it, instead of sending an
  `information_schema.PARTITIONS` query no other server has. MariaDB counts as MySQL.
- Every read goes to the write connection. On a connection split into `read` and `write` hosts the
  partition list, the table lookup and the timezone probe are asked of the primary, the server the
  DDL runs on, with or without `sticky`: a replica that lags would otherwise report partitions that
  are already there as missing, or a partitioned table as a plain one.

## Coming from `app/Services/Partition`

| Before | Now |
| --- | --- |
| `Partition::daily($table, $column)` | `Partition::daily($table, $column)` (unchanged) |
| `->from($date)->to($date)` | unchanged |
| `->definition()` | `->createStatement()` |
| `->generate()` | `->missing()` (objects) or `->syncStatements()` (SQL) |
| `->dropBetween($from, $to)` (returned SQL) | `->dropBetween($from, $to)` executes; `->dryRun()->dropBetween(...)` returns the SQL |
| `PartitionHelper::executeReorganize()` | `->sync()` |
| `artisan partitions:add {table} --type=day` | `artisan partitions:add {table} --strategy=daily` |
| `artisan partitions:drop {table} --type=day` | `artisan partitions:drop {table} --strategy=daily` |

Behaviour differences worth knowing:

- `sync()` re-creates `pMax` inside the `REORGANIZE`, so it no longer fails on tables that already
  have a `MAXVALUE` partition, and no longer emits `pMax` twice.
- Partitions are matched by parsing their names with the active strategy instead of stripping every
  `p` from the string, so unrelated partitions are never dropped.
- Existing partitions are ordered by `PARTITION_ORDINAL_POSITION` rather than by the textual
  `PARTITION_DESCRIPTION`.
- Failures are no longer swallowed: a failing `ALTER` throws instead of returning `false`.

## Tests

```bash
composer install
vendor/bin/phpunit
```

CI runs the suite across PHP 8.2 / 8.3 / 8.4 / 8.5 and Laravel 12 / 13, plus two `--prefer-lowest` rows
that hold the lower bounds of the declared constraints honest. The package is a library, so
`composer.lock` is not committed and CI resolves the dependencies with `composer update` on every
run.

No database required: the DDL is generated against a fake partition repository, so
every statement, boundary and name is asserted as a string. The migration layer is covered the same
way — the `Blueprint` is compiled to SQL and asserted, without a connection to a server. The
commands are driven through Symfony's `CommandTester`, so their prompts, tables and exit codes are
asserted too, and `tests/Feature` boots the service provider inside a real application with
`orchestra/testbench` to cover the config merge, the container bindings, the registered commands and
the scheduled maintenance.

PHPUnit 11 is required — the suite uses `#[DataProvider]` attributes, which PHPUnit 9 ignores.

### Static analysis and code style

```bash
composer analyse   # PHPStan (larastan) at level 8 over src
composer lint      # Pint, report only
composer fix       # Pint, rewrite the files
composer test      # PHPUnit
```

PHPStan runs at level 8 with the Larastan extension, so the generics in the annotations
(`Collection<int, PartitionInfo>`, `array{added: ..., dropped: ...}`) are checked against the code
instead of being decoration. Pint uses the `laravel` preset with no overrides. Both run in CI next
to the test matrix, on every push and pull request.

## License

Released under the [MIT License](LICENSE).

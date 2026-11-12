# Changelog

All notable changes to this package are documented here.

## [1.0.0] - 2026-11-12

### Fixed

- The limit of 8192 partitions in one table was checked against the periods of one call only, so
  two layouts passed it and were refused by the server with error 1499 instead of a
  `TooManyPartitionsException`. `create()`, `createStatement()`, `partitionByClause()` and a
  migration now count the `MAXVALUE` partition they add: a range of exactly 8192 periods is refused
  unless `withoutMaxPartition()` is set. `sync()`, `syncStatements()` and `maintain()` count the
  partitions the table already has: when those and the ones to add come to more than 8192, the
  exception is thrown before the first statement, in a dry run too. A `sync()` split by
  `syncChunk()` used to apply the chunks that still fitted and fail on the next one.

## [0.1.3] - 2026-11-12

Fixes from a review of 0.1.2, the first one made against real servers. Several of them turn a
silent or half-done run into a refusal, so read "Changed" before upgrading.

### Added

- `tests/Integration`: the operations run against a server — every period and expression through
  the builder and through migrations — and CI runs them on MySQL 8.0, MySQL 8.4 and MariaDB 11.4.
  Without `PARTITIONS_DB_HOST` the suite is skipped.
- `InvalidConfigValueException`, `DropsEveryPartitionException` and `TooManyPartitionsException`,
  described under "Changed".
- `PartitionBuilder::assertDroppable($partitions)`: the check `drop()` makes, for code that shows a
  selection before dropping it.
- `Partition::forTable()` is declared on the facade.

### Changed

- `future`, `retain`, `chunk`, `sync_chunk` and `max_gaps` in `config/partitions.php` take a whole
  number, zero or above — an integer or a string of digits. Anything else — `false`, a word, a
  negative number, a float — throws `InvalidConfigValueException` when the builder is made, instead
  of being cast. `partitions:run` reports such a table as failed and maintains the rest.
- `sync()`, `syncStatements()` and `maintain()` throw `NotPartitionedException` on a table that is
  not partitioned, in a dry run too. They used to build an `ADD PARTITION` the server refused.
- `drop()`, `dropBefore()`, `dropBetween()`, `dropStatements()` and `partitions:drop` throw
  `DropsEveryPartitionException` when the selection is every partition the table has, before the
  first statement.
- When a statement of `drop()`, `truncate()` or a chunked `sync()` fails after others ran, the event
  is dispatched for the statements that ran — their partitions and their SQL — before the exception
  reaches the caller. It used not to be dispatched at all.
- A range or a `future()` of more than 8192 periods throws `TooManyPartitionsException` before the
  periods are walked.
- `partitions:drop` takes one of `--before`, `--from`/`--to` and `--retain`; two together stop the
  command. `--before` used to win without a word.
- `partitions:list` and `partitions:drop` end with `Table [...] does not exist.` and a failure code
  for a table the connection does not know, instead of calling it not partitioned.
- A numeric option too long for an integer fails the command like any other malformed number.

### Fixed

- `'retain' => false` — like `'abc'`, `-5` or `[]` — was read as `retain(0)`, and the next
  `partitions:run` dropped every complete period before the current one.
- On a table without a `MAXVALUE` partition, `dropBefore()` or `partitions:drop --before` past the
  last partition dropped all partitions but one and then failed on the last with MySQL error 1508.
- A published config whose `schedule` array had no `on_one_server` key scheduled `partitions:run`
  without `onOneServer()`, although the default is `true`.
- `--future=99999999999999999999` hung the command instead of failing it.
- A `retain` far past anything a table can hold — `--retain=999999999999` — walked back one period
  at a time and did not finish. The walk now stops once the cutoff is before the year 1000, the
  earliest date MySQL stores; what `outdated()` selects is unchanged.

## [0.1.2] - 2026-11-12

Fixes from a review of 0.1.1. Several of them change what a configuration or a call does, so read
"Changed" before upgrading.

### Added

- `InvalidDateException`: a date string that is blank or that PHP cannot read is refused by `from()`,
  `to()`, `between()`, `has()`, `partitionNameFor()`, `inRange()`, `outdated()`, `dropBefore()`,
  `dropBetween()`, the same methods of a migration definition and the `--from`, `--to` and `--before`
  options. Carbon's exception is kept as `getPrevious()`.
- `InvalidTableConfigException`: an entry under `tables` that is not an array of settings is
  reported by `Partition::for()`, `configured()`, the commands and a migration of that table.
- `PartitionDefinition::verifyTimezone()`, so a migration can turn the session check on or off.
- `PartitionManager::forTable($table, $connection)`: the builder of the `tables` entry whose table
  matches, or one built from the global settings when there is none.
- `outdatedAfter(PartitionPlan $plan)`: the partitions `maintain($plan)` drops.
- `'timezone' => false` and `'tablespace' => false` in a table entry turn the global value off for
  that table, the way `'max_partition' => false` does.

### Changed

- A config value of `''` counts as a missing key everywhere, and so does `null` for every key except
  `retain`, `chunk`, `sync_chunk` and `max_gaps`, where `null` keeps its meaning. Two settings read
  differently now: `'max_partition' => null` keeps the default `pMax`, and `'tablespace' => null` in
  a table entry keeps the global tablespace. Use `false` for both.
- `partitionByRange()` in a migration starts from the `tables` entry of its table — `strategy`,
  `expression`, `timezone`, `verify_timezone`, `prefix`, `max_partition`, `tablespace`, `future` —
  and what the migration declares goes on top. An entry that names a connection is used on that
  connection only. A table without an entry is built from the global settings, as before.
- `createStatement()`, `partitionByClause()`, `create()` and a migration write the configured name of
  the `MAXVALUE` partition and no longer read the table for it. A migration with
  `replacePartitioning()` and no `withMaxPartition()` used to adopt the name the table already had;
  it now writes the configured one. `sync()` still re-creates the partition under its existing name.
- `max_gaps` counts only the periods skipped before the current one. The current period, the
  `future()` look-ahead and an explicit `to()` ahead of today are never cut short by it. While more
  periods are skipped than one run fills, everything after them is deferred with them, and
  `partitions:add` reports "N partitions are left for the next run".
- With `retain()` set and no `from()`, gap filling starts at the retention cutoff when the last
  partition is older than that. `outdated()` without a date, `prune()` and `partitions:drop --retain`
  never select the last partition with a boundary, so the next run has one to continue from.
- A column, partition or tablespace name with a dot throws `InvalidIdentifierException`; only a table
  name may be qualified. The tablespace is back-quoted in the DDL.
- Every read — the partition list, the table lookup, the timezone probe — goes to the write
  connection.
- `PartitionManager` is bound, not shared: `app(PartitionManager::class)` and `app('partitions')`
  return a new instance each time, and the `Partition` facade does not cache it. `PartitionCommand`
  has no constructor; `$this->manager` is set before `handle()`.
- `PartitionRepository::unixTimestamp()` is replaced by
  `unixTimestamps(?string $connection, array $datetimes): array`, and
  `TimezoneMismatchException::__construct()` takes the datetime as a third argument. Both matter
  only to a custom repository or to code that builds the exception itself.
- The dates the builder hands to a strategy, and `start` and `upperBound` of a `PendingPartition`,
  are in UTC with the wall clock of the calendar date they stand for. They used to be in the
  application timezone.
- `partitions:run` exits with a failure code for a configured table that is not partitioned or does
  not exist, and says which of the two it is.
- A table partitioned by `KEY`, `LINEAR KEY`, `HASH`, `LINEAR HASH` or `LIST` is refused with
  `ExpressionMismatchException` whether or not it reports a partitioning expression.

### Fixed

- A blank variable in `.env` turned `'retain' => env('TRANSACTIONS_RETAIN', 90)` into `retain(0)`,
  and the next `partitions:run` dropped every complete period before today. Other keys broke the
  same way: a blank `future` became `0`, a blank `chunk` or `max_gaps` lifted the limit, a blank
  `prefix` or `max_partition` produced empty names.
- A table left unattended for longer than its retention lost every bounded partition on the next
  `maintain()`: the oldest skipped periods were created and pruned in the same run, the following
  run started at today, and the rows since the gap stayed in one partition. A dry run and the
  Dropped column of `partitions:run` reported fewer drops than the real run made.
- `'timezone' => null` or `'tablespace' => null` in a table entry reset the global value instead of
  keeping it, so `unix_timestamp` boundaries of that table moved to the application timezone.
- A migration ignored `tables.*.timezone` and `tables.*.verify_timezone`, so it wrote
  `unix_timestamp` boundaries in another zone than `Partition::for()` used for the same table later.
- `verifyTimezone()` compared the session with the current time only. A session zone with the same
  offset today and other DST rules passed, and boundaries after the next clock change were written
  an hour off. The server is now asked about every boundary the statement writes, in one query, and
  the exception names the datetime that reads differently.
- With `verify_timezone` on, a dry run returned before the session timezone was checked, so
  `--dry-run` printed a clean plan on a connection where the real run throws.
- On a connection split into `read` and `write` hosts a lagging replica made a retry send a partition
  that already existed, and let `create()` rebuild a table that was already partitioned.
- Where a clock change skips midnight — `America/Santiago`, `Africa/Cairo`, `Asia/Beirut`,
  `America/Havana` — a `unix_timestamp` partition ended at 01:00 instead of midnight, so the rows of
  that hour were dropped a day early. This held both for the period that starts on the skipped
  midnight and, when `timezone()` names another zone than the application's, for the period before
  it. Partitions already written with the late boundary are left as they are.
- A `PartitionPlan` given to `sync()`, `syncStatements()` or `maintain()` was sent as it was, so a
  retry after a partial failure sent the partitions that had already been created. The plan is now
  filtered against the table, and `PartitionsCreated` lists only what was added.
- An entry under `tables` that is not an array was skipped silently: `partitions:run` maintained the
  rest and exited with 0, and the other commands took the key for a raw table name.
- `withMaxPartition('p.max')` and `daily('x.created_at')` compiled to DDL MySQL rejects; inside
  `Schema::create()` that left the table created and not partitioned. The name is now refused before
  any statement runs.
- `createStatement()` and `partitionByClause()` queried `information_schema`, although building that
  SQL needs no connection, and a migration that replaced the partitioning of a table wrote the old
  `MAXVALUE` name while `migrate --pretend` showed the new one.
- `plan()` cut the look-ahead at `max_gaps`: on a table that was up to date,
  `partitions:add --future=30` added 12 partitions and reported skipped periods that did not exist.
- `Event::fake()`, `Log::spy()`, a `PartitionRepository` bound to a fake and config set at runtime
  were ignored by `Partition::` and by the commands once Artisan had been loaded, which is every
  test that uses `RefreshDatabase`.
- A date string Carbon could not parse left the package as `InvalidFormatException`, not as a
  `PartitionException`. A blank string was read as "now", so `dropBefore('')` dropped every
  partition before today.
- A table partitioned by `KEY()` reached MySQL with `RANGE` statements and failed with a raw MySQL
  error.

## [0.1.1] - 2026-11-11

Documentation only; the code is the same as in 0.1.0.

### Changed

- The README no longer has a section on a third-party package, and the configuration example no
  longer points at it.

### Fixed

- The PHP version badge in the README renders again: it used a shields.io endpoint that no longer
  exists.

## [0.1.0] - 2026-11-11 [YANKED]

The first release, replaced by 0.1.1 half an hour after it was tagged. The package is extracted from
the `app/Services/Partition` namespace of an application and rebuilt as a standalone partition layer
for MySQL and MariaDB `RANGE` partitioning by date. What differs from that namespace is listed in the
README, under [Coming from `app/Services/Partition`](README.md#coming-from-appservicespartition).

### Added

#### Declaring partitions in migrations

- `$table->partitionByRange('created_at')->daily()->future(30)` on a `Blueprint`, in
  `Schema::create()` and `Schema::table()`, and `$table->removePartitioning()`. `partitionByRange()`
  returns a `PartitionDefinition` — the declarative half of the builder: the strategy, the prefix, the
  expression, the range, the `MAXVALUE` partition, the tablespace, the timezone and
  `replacePartitioning()`. Nothing executes from inside a blueprint callback.
- The clause compiles to a separate `ALTER TABLE ... PARTITION BY`, run right after the
  `CREATE TABLE`, while the table is still empty and the rebuild is free. It goes through `Blueprint`
  and schema-grammar macros, so no grammar is replaced; a non-MySQL grammar throws instead of being
  handed MySQL DDL.
- Inside `Schema::table()` the table is checked when the statement compiles: `partitionByRange()` on
  a partitioned table throws unless `replacePartitioning()` confirms the rebuild, and
  `removePartitioning()` on an unpartitioned one throws. With `verify_timezone` on and
  `unix_timestamp` boundaries, `Schema::create()` and `Schema::table()` both ask the server for its
  session timezone. Every check is skipped under `migrate --pretend`.

#### The builder

- `Partition::table($table)` — a table name, a model or a model class — returns a fluent
  `PartitionBuilder`; `Partition::daily()`, `weekly()`, `monthly()`, `yearly()` and `model()` are
  shortcuts for it.
- Daily, weekly (ISO week), monthly and yearly periods, as the `PartitionPeriod` enum (`Daily`,
  `Weekly`, `Monthly`, `Yearly`) or by name: `daily`, its unit `day` or its initial `d`, in any letter
  case and with surrounding whitespace ignored. Custom periods through the `PartitionStrategy`
  contract or `AbstractStrategy`, which needs `start()`, `next()` and `format()`, plus `decode()`
  when the name format is one `createFromFormat()` cannot read back, such as the ISO week `oW`. A
  partition name holds `[A-Za-z0-9_$]`, and a name is read back only when it formats back to itself.
- Three partitioning expressions: `RANGE (TO_DAYS(col))` (the default), `RANGE COLUMNS(col)` and
  `RANGE (UNIX_TIMESTAMP(col))` — `usingToDays()`, `usingRangeColumns()`, `usingUnixTimestamp()`, or
  `expression()` with a `PartitionExpression` case or its name.
- Range and retention: `from()` / `to()` / `between()`, `future()` (periods kept ahead of today,
  default 7), `retain()` (complete periods kept before the current one, so `retain(90)` on a daily
  table leaves 91 partitions), `fillGaps()` (periods skipped while a table was unattended are
  created too) and `maxGaps()` (how many of them one run fills, default 12).
- Operations: `create()`, `sync()`, `prune()`, `maintain()`, `dropBefore()`, `dropBetween()`,
  `drop()`, `truncate()` and `removePartitioning()`, each returning the statements it ran.
  `drop()` and `truncate()` take the list they are given as it is; the other drops never touch the
  `MAXVALUE` partition or a name the current strategy did not produce.
- Inspection: `isPartitioned()`, `existing()`, `missing()`, `plan()` (`pending()` — what `sync()`
  adds — and `deferred()` — what is left for a later run — from one pass over the table), `gap()`,
  `outdated()`, `inRange()`, `has()`, `partitionNameFor()`, `createStatement()`,
  `syncStatements()` and `partitionByClause()`. `sync()`, `syncStatements()` and `maintain()` accept
  a `PartitionPlan`, so the partitions that were shown are the ones that are added.
- `dryRun()` on every operation: the SQL is built and returned, nothing is executed. The table is
  still read, so a dry run throws wherever the real run would.
- Statements are split by two settings: `chunk()` — partitions per `DROP PARTITION` and
  `TRUNCATE PARTITION`, default 1 — and `syncChunk()` — partitions per statement that adds them,
  default all in one. `null` or `0` puts everything in one statement. After a failed statement the
  builder reads the table again, so a retry sends only what is still missing.
- A `MAXVALUE` catch-all partition, `pMax` by default, renamed with `withMaxPartition('p_future')` or
  left out with `withoutMaxPartition()`; `tablespace()` for the generated partitions.
- The connection is the first one named of: `connection()` (`--connection`, `tables.*.connection`),
  the model's own `$connection`, `defaultConnection()` (`partitions.connection`), the application
  default. A table is named without the connection's table prefix, and the builder puts the prefix
  in front of it in the DDL and in the `information_schema` lookups, so a name, a model, a
  configured key, a command argument and a migration all reach the same table.
- Every method that takes a date reads it as the calendar date it carries, in its own timezone — the
  date Eloquent writes the row under.

#### Timezones

- `timezone()` (config `timezone`, `tables.*.timezone`) sets the zone `unix_timestamp` boundaries
  are computed and read back in; it defaults to the connection's `timezone`, then to the application
  one. `to_days` and `columns` boundaries are calendar dates and do not depend on it.
- `verifyTimezone()` (config `verify_timezone`, off by default) asks the server what it makes of a
  datetime literal before running DDL, and throws `TimezoneMismatchException` when the session reads
  it in another zone.

#### Artisan commands

- `partitions:list {table} [--missing]` — the partitions, their estimated rows and size.
- `partitions:add {table}` with `--from`, `--to`, `--future`, `--tablespace`, `--chunk`,
  `--max-gaps`, `--create`, `--force` and `--dry-run`. A table that is not partitioned yet is
  partitioned only with `--create`, after the rows and disk space the rebuild moves are printed and
  confirmed; without the flag the command stops, in a dry run and a non-interactive run too.
  Without `--from` a run continues right after the last partition — or starts at today when
  `fill_gaps` is off — and `--future` is counted from today.
- `partitions:drop {table}` with `--before`, `--from` and `--to` (both ends, inclusive and
  order-insensitive), `--retain`, `--chunk`, `--force` and `--dry-run`. It prints the partitions, the
  estimated rows and the disk space it is about to delete, asks for confirmation and drops exactly
  the list it printed.
- `partitions:run [table] [--dry-run]` — `maintain()` on every configured table. Each table is built
  and maintained on its own: a broken entry or a failing table is reported, the rest are still
  maintained, and the command exits with a failure code.
- The table argument is a raw table name, described by `--strategy`, `--column`, `--expression`,
  `--prefix` and `--connection`, or a key from `config/partitions.php`, which those options
  override. An option passed empty is not an override, and the numeric options take a whole number,
  zero or above. Confirmations are skipped by `--force`, `--dry-run` and a non-interactive run.

#### Configuration and scheduled maintenance

- `config/partitions.php` (`php artisan vendor:publish --tag=partitions-config`): global defaults —
  `connection`, `prefix`, `max_partition`, `tablespace`, `expression`, `timezone`,
  `verify_timezone`, `future`, `chunk`, `sync_chunk`, `fill_gaps`, `max_gaps` — and a `tables`
  catalogue where each entry overrides them and adds `table`, `strategy`, `column` and `retain`. An
  empty value from `.env` counts as not set.
- `Partition::for($key)`, `configured()` and `configuredKeys()`. A key is looked up as written, so
  `'reporting.transactions'` is one table.
- Optional scheduled `partitions:run`: `PARTITIONS_SCHEDULE_ENABLED=true`, `schedule.cron` (default
  `15 3 * * *`), `withoutOverlapping()` and `onOneServer()`.

#### Events and log

- `PartitionsCreated`, `PartitionsDropped`, `PartitionsTruncated` and `PartitioningRemoved`, each
  carrying the table, the connection, the partition names and the statements. They are dispatched
  after a successful run only — never in a dry run, never when there was nothing to do.
- `sync()` logs a warning, with the facts as context, when an explicit `from()` skips periods after
  the last partition, so their rows land in the first partition created; `partitions:add` prints the
  same sentence before the statements. Nothing is logged in a dry run, though the command still
  prints it, and with `fill_gaps` off the gap is left on purpose and neither says anything. `gap()`
  returns the warning beforehand as a `PartitionGap`. The logger is the application's
  `Psr\Log\LoggerInterface`.

#### Exceptions

- One exception per failure, all extending `Yurkovskiy\Partitions\Exceptions\PartitionException`:
  `UnknownTableException`, `UnknownStrategyException`, `UnknownExpressionException`,
  `MissingStrategyException`, `MissingTableException`, `MissingDefinitionException`,
  `InvalidIdentifierException`, `InvalidTimezoneException`, `InvalidRangeException`,
  `TimezoneMismatchException`, `AlreadyPartitionedException`, `NotPartitionedException`,
  `MaxPartitionConflictException`, `ExpressionMismatchException`, `StrategyMismatchException`,
  `UnsupportedGrammarException` and `UnsupportedDriverException`.

#### Safety guards

- A table partitioned by another expression, column or method than the builder describes is refused
  by both the adding and the dropping half of the API (`ExpressionMismatchException`), and so is a
  table cut by another period (`StrategyMismatchException`): weekly `p202506` and monthly `p202506`
  are spelled alike, so names alone cannot make a drop safe.
- `create()` refuses a partitioned table and `removePartitioning()` an unpartitioned one; `sync()`
  refuses to reorganise an existing `MAXVALUE` partition when the builder is configured without one.
- A `from()` past the end of the range throws `InvalidRangeException` instead of describing a range
  that covers no period.
- Table, column, partition and tablespace names are validated against `[A-Za-z0-9_$]`, and all but
  the tablespace are quoted.
- Every read and statement checks the connection's driver and throws `UnsupportedDriverException`
  on anything but MySQL or MariaDB.
- A subpartitioned table is read as one entry per partition, with the subpartition rows and sizes
  summed.

#### Requirements and tooling

- PHP 8.2 or newer with Laravel 12, PHP 8.3 or newer with Laravel 13. Laravel 11 is not supported:
  every `11.x` release carries unpatched security advisories.
- Released under the MIT License.
- 535 PHPUnit tests, none of which needs a database: the DDL and the compiled `Blueprint` are
  asserted as strings against a fake repository, the commands run through `CommandTester`, and the
  service provider is booted with `orchestra/testbench`.
- A GitHub Actions workflow over PHP 8.2–8.5 and Laravel 12–13, with two `--prefer-lowest` rows, a
  `composer validate --strict` job, PHPStan with Larastan at level 8 and Pint with the `laravel`
  preset — available locally as `composer test`, `composer analyse`, `composer lint` and
  `composer fix`.

# Changelog

All notable changes to this package are documented here.

## [0.1.0] - 2026-11-11

The first release. The package is extracted from the `app/Services/Partition` namespace of an
application and rebuilt as a standalone partition layer for MySQL and MariaDB `RANGE` partitioning by
date.

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
  contract or `AbstractStrategy`, which needs `start()`, `next()` and `format()` only. A partition
  name holds `[A-Za-z0-9_$]`, and a name is read back only when it formats back to itself.
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
  Without `--from` a run continues right after the last partition, and `--future` is counted from
  today.
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
  same sentence before the statements. `gap()` returns it beforehand as a `PartitionGap`. The
  logger is the application's `Psr\Log\LoggerInterface`.

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
- Table, column, partition and tablespace names are validated against `[A-Za-z0-9_$]` and quoted.
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

### Compared to `app/Services/Partition`

- `->definition()` is `createStatement()`, `->generate()` is `missing()` or `syncStatements()`,
  `PartitionHelper::executeReorganize()` is `sync()`, and `--type=day` on the commands is
  `--strategy=daily`.
- `dropBetween()` executes the drop; `dryRun()->dropBetween()` returns the SQL it used to return.
- `REORGANIZE PARTITION pMax` re-creates the `MAXVALUE` partition inside the statement, so adding
  partitions no longer fails on a table that has one, and no longer emits `pMax` twice on a table
  that has none.
- Partition names are decoded with the active strategy and verified by re-encoding them, instead of
  stripping every `p` from the string, so one strategy cannot claim another one's partitions and
  unrelated partitions are never dropped.
- Monthly names are no longer built with `Carbon::createFromFormat('Ym', ...)`, which inherited the
  current day of the month and shifted the name on long months.
- Partitions whose range falls below the highest existing boundary are skipped instead of being sent
  to MySQL, which would reject the whole statement.
- Existing partitions are ordered by `PARTITION_ORDINAL_POSITION` instead of the textual
  `PARTITION_DESCRIPTION`.
- Failing DDL throws instead of being swallowed and reported as `false`.
- `partitions:drop` asks for confirmation and reports the rows and disk space it is about to delete.
- Identifiers are validated and quoted, and boundaries are computed in PHP, so building the SQL
  needs no round trip to the database.

# Builder reference

## Target

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

## Strategy

| Method | Description |
| --- | --- |
| `daily($column)` / `weekly($column)` / `monthly($column)` / `yearly($column)` | Built-in strategies: `p20250613`, `p202524` (ISO week), `p202506`, `p2025`. |
| `strategy($strategy, $column)` | The same four by name — `daily`, `weekly`, `monthly`, `yearly`, their unit (`day`) or its initial (`d`), in any letter case and with surrounding whitespace ignored — or a `PartitionPeriod` case, or a `PartitionStrategy` class/instance. A period name is matched before a class name, so a strategy class that lives in the global namespace and is called `Month` or `D` resolves as the period instead of itself; a namespaced class never collides. |
| `prefix(string $prefix)` | Partition name prefix, `p` by default. |
| `usingToDays()` | `PARTITION BY RANGE (TO_DAYS(col))` — the default, works with `DATE`, `DATETIME`, `TIMESTAMP`. |
| `usingRangeColumns()` | `PARTITION BY RANGE COLUMNS(col)` — readable boundaries, `DATE`/`DATETIME` columns only. |
| `usingUnixTimestamp()` | `PARTITION BY RANGE (UNIX_TIMESTAMP(col))` — for `TIMESTAMP` and `DATETIME` columns. Its boundaries are instants, not calendar dates, see [Timezones](timezones.md). |
| `expression($expression)` | The same three by name — `to_days`, `unix_timestamp`, `columns`, read the same way as a strategy name: any letter case, surrounding whitespace ignored — or a `PartitionExpression` case. |
| `timezone($zone)` | Timezone the `unix_timestamp` boundaries are computed in. Defaults to the connection's `timezone`, then to the application one. |
| `verifyTimezone(bool $verify = true)` | Ask the server how it reads the boundaries before running DDL, instead of writing ones that are off by the difference. |
| `tablespace(?string $tablespace)` | Adds ``TABLESPACE = `...` `` to every generated partition. |
| `withMaxPartition(bool\|string $max)` / `withoutMaxPartition()` | Catch-all `MAXVALUE` partition, `pMax` by default. The name is the one `create()`, `createStatement()`, `partitionByClause()` and a migration write, also when a migration replaces the partitioning of a table whose catch-all is named differently; `sync()` keeps the name the table already uses. `withoutMaxPartition()` describes the layout `create()` builds; on a table that already has one, `sync()` throws instead of quietly keeping it (see [How it maps to MySQL](mysql-mapping.md)). |

## Range and retention

| Method | Description |
| --- | --- |
| `from($date)` / `to($date)` / `between($from, $to)` | Explicit range, inclusive on both ends. Without `from()` the range starts right after the last existing partition, or at the current period when the table has none. A `from()` whose period lies past the end of the range — past `to()`, or past the look-ahead when `to()` is not set — throws an `InvalidRangeException` instead of describing a range with nothing in it. A `from()` past the period right after the last partition is taken as given, and the periods it skips are reported: `gap()` describes them, `sync()` writes a warning to the log. A date object counts by its own calendar date, see [Dates you pass in](timezones.md#dates-you-pass-in). |
| `future(int $periods)` | Periods kept created ahead of today when `to()` is not set (default 7). Counted from today, not from the last partition: `future(10)` on a table that already reaches a week ahead adds three periods, and a second run adds none. |
| `retain(?int $periods)` | Complete periods kept by `prune()` **before** the current one: partitions whose period starts before `now - N periods` are dropped, so a daily table under `retain(90)` ends up with 91 of them — the last 90 days and today. `retain(0)` keeps the current period only, `null` disables pruning. The last partition with a boundary is never pruned, however old it is, see [a table left longer than its retention](commands.md#a-table-left-longer-than-its-retention). |
| `chunk(?int $size)` | Partitions per `DROP PARTITION` and `TRUNCATE PARTITION` statement (default 1, which keeps metadata locks short). `null` or `0` puts them all in one statement. Adding is split by `syncChunk()`. |
| `syncChunk(?int $size)` | Partitions per statement that adds them (default `null`, all in one statement; `0` means the same). A size of N splits the additions into statements of N partitions each. Every `REORGANIZE` rewrites what is still in `pMax`, so splitting trades extra work for recoverability, see [filling a long gap](commands.md#filling-a-long-gap). |
| `fillGaps(bool $fill = true)` | Start from the last covered period instead of today, so periods a table skipped while unattended are created too (default on). With `retain()` set, the skipped periods retention has already expired are not created: filling starts at the retention cutoff. An explicit `from()` is not moved back, but while gap filling is on the periods it skips are warned about; with `fillGaps(false)` the gap is left on purpose and nothing is said. |
| `maxGaps(?int $gaps)` | How many skipped periods one run fills, oldest first (default 12). Only the periods before the current one count: the current period, the look-ahead of `future()` and an explicit `to()` ahead of today are never cut short, but they wait for a later run while more periods were skipped than one run fills — a partition cannot be added below an existing one. `null` or `0` lifts the limit; `pending()` is what the next `sync()` adds, `deferred()` what is left for a later run; `plan()` returns both from one pass over the table. |
| `dryRun(bool $dryRun = true)` | Build the SQL, execute nothing. The table is still read and, with `verifyTimezone()`, the session timezone is still checked, so a dry run throws wherever the real run would before its first statement. |

## Operations

Every executing method returns a `Collection<string>` of the statements it ran — or, in dry run mode,
the ones it would have run.

| Method | Description |
| --- | --- |
| `create()` | Partition a table that is not partitioned yet. Throws when it already is. |
| `sync(?PartitionPlan $plan = null)` | Add the missing partitions of the configured range, stopping after `maxGaps()` skipped periods when the table skipped more. No-op when there is nothing to add. Given a `plan()`, it adds the pending partitions of that plan instead of planning again — the list that was shown is the list that is added. Partitions of the plan the table already has are left out, so a retry with the same plan after a failed statement sends only what is still missing. `syncStatements()` takes a plan the same way. Throws a `NotPartitionedException` on a table that is not partitioned, in a dry run too: partitioning a table is `create()`, never a side effect of `sync()`. |
| `prune()` | Drop what falls outside `retain()`, except the last partition with a boundary. |
| `dropBefore($date)` | Drop everything older than the given date — its own calendar date for a date object, see [Dates you pass in](timezones.md#dates-you-pass-in). |
| `dropBetween($from, $to)` | Drop the partitions covering the range, inclusive on both ends. The bounds are normalised, so passing them the other way round selects the same partitions. |
| `maintain(?PartitionPlan $plan = null)` | `['added' => ..., 'dropped' => ...]` — `sync($plan)` plus `prune()`. What is dropped is decided before anything is added, so a dry run lists the drops of a real run. Throws on a table that is not partitioned, like `sync()`. |
| `drop($partitions)` | Drop exactly these partitions — say, the list `outdated()` or `inRange()` returned and somebody looked at. A selection that covers every partition of the table throws a `DropsEveryPartitionException` before the first statement — MySQL keeps at least one — and that goes for `dropBefore()` and `dropBetween()` too, which can only reach that far on a table without a `MAXVALUE` partition. |
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

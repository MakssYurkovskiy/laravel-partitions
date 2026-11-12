# Configuration and scheduled maintenance

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
    'payments' => [
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

A number is read as strictly as an option of the commands. `future`, `retain`, `chunk`, `sync_chunk`
and `max_gaps` take a whole number, zero or above — an integer, or a string of digits, which is what
`env()` returns. Anything else — `false`, `'abc'`, `-1`, `1.5` — throws an
`InvalidConfigValueException` naming the setting and the table when the builder is made, before a
statement is built, instead of being cast to `0`: `'retain' => false` does not become `retain(0)`,
which would hand the whole history to `prune()`. A table that is not pruned has `'retain' => null`,
or no `retain` at all. `partitions:run` reports a table with such a value as failed and maintains the
rest.

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
(`partitions.schedule.cron`, `withoutOverlapping()`, and `onOneServer()` unless
`partitions.schedule.on_one_server` is `false` — a `schedule` array that leaves the key out keeps it
on); an empty
`PARTITIONS_SCHEDULE_CRON=` keeps the default `15 3 * * *`. Or schedule it yourself:

```php
Schedule::command('partitions:run')->dailyAt('03:15')->onOneServer();
```

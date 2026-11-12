# Timezones

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

The zone decides where a boundary lands and nothing else. Periods are counted on the calendar, in a
frame without clock changes, so a day whose midnight the application timezone skips —
`America/Santiago` on the first Sunday of September — still starts at 00:00 in `timezone('UTC')`.

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

## Dates you pass in

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

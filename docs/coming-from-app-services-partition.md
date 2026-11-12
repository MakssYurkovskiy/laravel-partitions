# Coming from `app/Services/Partition`

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
- Partitions are matched by parsing their names with the active strategy and formatting them back,
  instead of stripping every `p` from the string, so a partition the strategy did not name is left
  alone. Names alone do not tell a weekly `p202506` from a monthly one; a table cut by another
  period is refused by its boundaries, with a `StrategyMismatchException`.
- Monthly names are no longer built with `Carbon::createFromFormat('Ym', ...)`, which inherited the
  current day of the month and shifted the name on long months.
- Partitions whose range falls below the highest existing boundary are skipped instead of being sent
  to MySQL, which would reject the whole statement.
- Existing partitions are ordered by `PARTITION_ORDINAL_POSITION` rather than by the textual
  `PARTITION_DESCRIPTION`.
- Failures are no longer swallowed: a failing `ALTER` throws instead of returning `false`.
- `partitions:drop` asks for confirmation and reports the rows and disk space it is about to delete.
- Identifiers are validated and quoted, and boundaries are computed in PHP, so building the SQL
  needs no round trip to the database.

# Events and errors

## Events

Every operation that changed something dispatches an event once its statements have run — never in
dry run mode, and never when there was nothing to do. All four carry the same payload: the table, the
connection, the partition names and the statements.

| Event | Dispatched by |
| --- | --- |
| `Yurkovskiy\Partitions\Events\PartitionsCreated` | `create()`, `sync()` |
| `PartitionsDropped` | `prune()`, `dropBefore()`, `dropBetween()`, `drop()` |
| `PartitionsTruncated` | `truncate()` |
| `PartitioningRemoved` | `removePartitioning()`, with the partitions the table had before |

An operation that runs several statements — `drop()` and `truncate()` under `chunk()`, `sync()` under
`syncChunk()` — can fail part way: DDL is not rolled back, so the statements before the failing one
have already changed the table. The event is dispatched then too, before the exception reaches the
caller, and carries only what ran: the partitions of the statements that succeeded and those
statements. A listener that archives or audits therefore hears of every partition that was dropped,
never of one that was not. When the first statement fails, nothing changed and nothing is
dispatched.

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
in) and `continue_at` (the start that leaves no gap). It is written after a run in which every
statement succeeded — never in dry run mode, and never with `fillGaps(false)`.

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
| `InvalidConfigValueException` | `future`, `retain`, `chunk`, `sync_chunk` or `max_gaps` in `config/partitions.php` is not a whole number, zero or above — `false`, a word, a negative number, a float — globally or for a table; thrown when the builder is made |
| `UnknownStrategyException` | `strategy()` got a name that is neither daily/weekly/monthly/yearly nor a `PartitionStrategy` class, or a configured table's `strategy` is not a name, a `PartitionPeriod` case or a strategy at all |
| `UnknownExpressionException` | `expression()` got something other than `to_days`, `unix_timestamp` or `columns` |
| `MissingStrategyException` | the builder reached SQL without a strategy |
| `MissingTableException` | the builder reached SQL without a table |
| `MissingDefinitionException` | the `partitionBy` blueprint command lost its `PartitionDefinition` |
| `InvalidIdentifierException` | a table, column, partition or tablespace name is outside `[A-Za-z0-9_$]` |
| `InvalidTimezoneException` | `timezone()` got an identifier PHP does not know |
| `InvalidDateException` | a date given as a string is blank or is not one PHP can read — `from()`, `to()`, `between()`, `has()`, `partitionNameFor()`, `inRange()`, `outdated()`, `dropBefore()`, `dropBetween()`, the same methods in a migration, and the `--from`, `--to` and `--before` options; Carbon's own exception is kept as `getPrevious()` |
| `InvalidRangeException` | `from()` lies past the end of the range — `to()`, or today plus `future()` when `to()` is not set — so `create()`, `sync()` or a migration would cover no period |
| `TooManyPartitionsException` | the table would end up with more than 8192 partitions, the number MySQL allows in one table: the range, or `future()`, covers more than 8192 periods (thrown before the periods are walked); the range covers exactly 8192 and `create()`, `createStatement()`, `partitionByClause()` or a migration adds the `MAXVALUE` partition to them; or the partitions `sync()`, `syncStatements()` or `maintain()` is about to add do not fit next to the ones the table has, the `MAXVALUE` partition included. Thrown before the first statement, in a dry run and with any `syncChunk()` alike, so nothing is added; partitions held back by `maxGaps()` are not counted |
| `TimezoneMismatchException` | `verifyTimezone()` found the session timezone away from the configured one, on the builder or in a migration |
| `AlreadyPartitionedException` | `create()` on a partitioned table, or `partitionByRange()` in a migration without `replacePartitioning()` |
| `NotPartitionedException` | `removePartitioning()` on a table that is not partitioned, from the builder or from a migration; `sync()`, `syncStatements()` or `maintain()` on such a table, before any statement is built |
| `MaxPartitionConflictException` | `sync()` with `withoutMaxPartition()` against a table that has a `MAXVALUE` partition |
| `DropsEveryPartitionException` | `drop()`, `dropBefore()`, `dropBetween()`, `dropStatements()` or `partitions:drop` selected every partition the table has; nothing is dropped, in a dry run and with any `chunk()` alike |
| `ExpressionMismatchException` | the table is partitioned by another expression, column or method than the builder describes |
| `StrategyMismatchException` | the table was cut by another period than the builder's strategy — a daily table under `monthly()` — or a partition named like a period of the strategy ends somewhere else |
| `UnsupportedGrammarException` | a blueprint command compiles on a non-MySQL grammar |
| `UnsupportedDriverException` | a read or a statement runs on a non-MySQL connection |

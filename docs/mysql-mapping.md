# How it maps to MySQL

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
  bound it when it is not (see [filling a long gap](commands.md#filling-a-long-gap)).
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

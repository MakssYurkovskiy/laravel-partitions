# Migrations

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

## Editing an existing table

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
ask the server for its session timezone (see [Timezones](timezones.md)). Every one of these checks is
skipped under `migrate --pretend`, where every read returns an empty set and the table would look
unpartitioned whatever it really is.

## How it compiles

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

## Migrations are not maintenance

A migration runs once; `p20260312` has to exist on 2026-03-11, and every day after that. So the
Blueprint layer declares the **initial** layout only — keeping the table covered and pruned stays
with `partitions:run` / `Partition::for(...)->maintain()` and the table catalogue in
`config/partitions.php`.

Need the raw clause instead (for a hand written `CREATE TABLE`)?

```php
$clause = Partition::table('transactions')->daily('created_at')->future(30)->partitionByClause();
```

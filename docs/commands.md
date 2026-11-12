# Artisan commands

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
`sync()` itself, so a call from code is recorded as well as the command (see [Log](events-and-errors.md#log)). With
`fill_gaps` off the gap is left on purpose: nothing is printed and nothing is logged.

## A table left longer than its retention

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

## Filling a long gap

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
delete, and asks for confirmation too. `--before`, the `--from`/`--to` pair and `--retain` are three
ways of selecting what to drop, and the command takes one: two of them together stop it before
anything is selected, rather than one quietly winning. Its `--from`/`--to` pair is inclusive and order-insensitive:
swapped bounds select the same partitions rather than an empty range. The pair goes together: either
one alone stops the command before anything is selected, in a dry run and on an unpartitioned table
too. A selection that takes every partition of the table — possible only without a `MAXVALUE`
partition — stops the command before the list is printed: MySQL refuses to drop the last partition,
so the run would end half done. The command does not pick the missing end — an end of "now" would take the partition rows are
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
number, zero or above: anything else — `--retain=abc`, `--future=-1`, a number too long for an
integer — fails the command before a statement is built, instead of being read as `0`.

`partitions:list` and `partitions:drop` tell a table that is not partitioned from one that is not
there: a name the connection does not know ends the command with `Table [...] does not exist.` and a
failure code, so a misspelt name does not pass for a plain table.

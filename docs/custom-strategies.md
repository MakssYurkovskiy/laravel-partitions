# Custom strategies

The four built-in periods live in the `Yurkovskiy\Partitions\Schema\PartitionPeriod` enum, and that is
the whole list. For anything else — quarters, fortnights, hours — implement
`Yurkovskiy\Partitions\Contracts\PartitionStrategy`, or extend `AbstractStrategy` and define three
methods — `start()`, `next()` and `format()` — plus `decode()` when the name format is one
`createFromFormat()` cannot read back (see below), then pass the class anywhere a strategy name is
accepted. `AbstractStrategy` names a strategy after the `PartitionPeriod` case that builds it, and a
strategy no case builds after its own class, so `name()` is worth overriding only to give it a
shorter name. A class named instead of an instance is constructed with no arguments, so its
constructor has to run without any — `AbstractStrategy` already defaults the prefix — and the
builder's `prefix()` is put on the instance afterwards:

```php
/** Quarters named after their first month: p202501, p202504, p202507, p202510. */
class QuarterlyStrategy extends AbstractStrategy
{
    public function name(): string
    {
        return 'quarterly';
    }

    public function start(DateTimeInterface $date): CarbonImmutable
    {
        return CarbonImmutable::instance($date)->firstOfQuarter()->startOfDay();
    }

    public function next(DateTimeInterface $date): CarbonImmutable
    {
        return $this->start($this->start($date)->addQuarter());
    }

    protected function format(): string
    {
        return 'Ym';
    }
}

Partition::table('transactions')->strategy(QuarterlyStrategy::class, 'created_at')->sync();
```

The builder counts periods on the calendar alone. Every date it hands to a strategy is in UTC and
carries the wall clock of the calendar date it stands for, so no clock change falls inside a period,
and only the `unix_timestamp` boundary is tied to `timezone()` afterwards. Keep the zone of the date
you are given, as `CarbonImmutable::instance($date)` does, instead of moving it to the application
timezone. The dates a strategy returns come back the same way: `start` and `upperBound` of a pending
partition are in UTC with those digits.

`next()` goes through `start()` twice so the strategy is also right for a date in a real timezone,
when you call it yourself. Where a clock change skips midnight — `America/Santiago`,
`Africa/Cairo` — that day starts at 01:00, and a period added to it has to be brought back to the
midnight the next one starts at.

A partition name is the prefix followed by the period start in `format()`, and it is held to the
same `[A-Za-z0-9_$]` as every other identifier: `Y_m` names `p2025_06`, while `Y-m` makes
`partitionName()` throw an `InvalidIdentifierException` that names the strategy, before any SQL is
built. Names are read back with `CarbonImmutable::createFromFormat()` and taken only when they format
back to the same name, which is what lets retention and `partitions:drop --from/--to` find them; a
format `createFromFormat()` cannot read, such as the ISO week `oW`, needs `decode()` overridden the
way `WeeklyStrategy` does.

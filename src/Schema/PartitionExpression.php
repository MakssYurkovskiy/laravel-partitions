<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Schema;

use DateTimeInterface;
use DateTimeZone;
use Yurkovskiy\Partitions\Exceptions\UnknownExpressionException;
use Yurkovskiy\Partitions\Support\Identifier;
use Yurkovskiy\Partitions\Support\Keyword;
use Yurkovskiy\Partitions\Support\MySqlDate;

enum PartitionExpression: string
{
    case ToDays = 'to_days';

    case UnixTimestamp = 'unix_timestamp';

    case Columns = 'columns';

    public static function make(self|string $expression): self
    {
        if ($expression instanceof self) {
            return $expression;
        }

        return self::tryFrom(Keyword::normalize($expression)) ?? throw new UnknownExpressionException($expression);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    public function rangeClause(string $column): string
    {
        $column = Identifier::quote($column);

        return match ($this) {
            self::ToDays => sprintf('RANGE (TO_DAYS(%s))', $column),
            self::UnixTimestamp => sprintf('RANGE (UNIX_TIMESTAMP(%s))', $column),
            self::Columns => sprintf('RANGE COLUMNS(%s)', $column),
        };
    }

    public function boundary(DateTimeInterface $upperBound, ?DateTimeZone $timezone = null): string
    {
        return match ($this) {
            self::ToDays => (string) MySqlDate::toDays($upperBound),
            self::UnixTimestamp => (string) self::epoch($upperBound, $timezone),
            self::Columns => sprintf("'%s'", $upperBound->format('Y-m-d')),
        };
    }

    public function parseBoundary(?string $description, ?DateTimeZone $timezone = null): ?\DateTimeImmutable
    {
        $description = trim((string) $description, " \t'\"");

        if ($description === '' || strcasecmp($description, 'MAXVALUE') === 0) {
            return null;
        }

        return match ($this) {
            self::ToDays => ctype_digit($description) ? MySqlDate::fromDays((int) $description) : null,
            self::UnixTimestamp => ctype_digit($description)
                ? (new \DateTimeImmutable('@'.$description))->setTimezone(self::zone($timezone))
                : null,
            self::Columns => preg_match('/^\d{4}-\d{2}-\d{2}/', $description) === 1
                ? new \DateTimeImmutable(substr($description, 0, 10), new DateTimeZone('UTC'))
                : null,
        };
    }

    private static function epoch(DateTimeInterface $upperBound, ?DateTimeZone $timezone): int
    {
        return (new \DateTimeImmutable($upperBound->format('Y-m-d H:i:s'), self::zone($timezone)))->getTimestamp();
    }

    private static function zone(?DateTimeZone $timezone): DateTimeZone
    {
        return $timezone ?? new DateTimeZone(date_default_timezone_get());
    }

    public function method(): string
    {
        return $this === self::Columns ? 'RANGE COLUMNS' : 'RANGE';
    }

    public function normalized(string $column): string
    {
        $column = strtolower($column);

        return match ($this) {
            self::ToDays => "to_days($column)",
            self::UnixTimestamp => "unix_timestamp($column)",
            self::Columns => $column,
        };
    }

    public static function normalize(string $expression): string
    {
        return strtolower((string) preg_replace('/[\s`]+/', '', $expression));
    }
}

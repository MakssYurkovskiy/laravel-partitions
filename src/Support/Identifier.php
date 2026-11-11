<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Support;

use Yurkovskiy\Partitions\Exceptions\InvalidIdentifierException;

final class Identifier
{
    /**
     * @throws InvalidIdentifierException
     */
    public static function quote(string $identifier): string
    {
        return collect(explode('.', $identifier))
            ->map(fn (string $part) => '`'.self::validate($part).'`')
            ->implode('.');
    }

    /**
     * @throws InvalidIdentifierException
     */
    public static function validate(string $identifier): string
    {
        if (! self::isValid($identifier)) {
            throw new InvalidIdentifierException($identifier);
        }

        return $identifier;
    }

    public static function isValid(string $identifier): bool
    {
        return preg_match('/^[A-Za-z0-9_$]+$/', $identifier) === 1;
    }

    public static function prefixTable(string $table, string $prefix): string
    {
        $dot = strrpos($table, '.');

        return $dot === false
            ? $prefix.$table
            : substr_replace($table, '.'.$prefix, $dot, 1);
    }

    public static function tableName(string $table): string
    {
        $parts = explode('.', $table);

        return self::validate((string) end($parts));
    }

    public static function schemaName(string $table): ?string
    {
        $parts = explode('.', $table);

        return count($parts) > 1 ? self::validate($parts[0]) : null;
    }
}

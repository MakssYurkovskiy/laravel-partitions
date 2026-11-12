<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Support;

final class WholeNumber
{
    public static function parse(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (! is_string($value) || ! ctype_digit($value)) {
            return null;
        }

        $digits = ltrim($value, '0');
        $number = (int) $value;

        return (string) $number === ($digits === '' ? '0' : $digits) ? $number : null;
    }
}

<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Support;

final class Keyword
{
    public static function normalize(string $keyword): string
    {
        return strtolower(trim($keyword));
    }
}

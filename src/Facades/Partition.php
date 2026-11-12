<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Facades;

use Illuminate\Support\Facades\Facade;
use Yurkovskiy\Partitions\PartitionManager;

/**
 * @method static \Yurkovskiy\Partitions\PartitionBuilder newBuilder()
 * @method static \Yurkovskiy\Partitions\PartitionBuilder table(\Illuminate\Database\Eloquent\Model|string $table)
 * @method static \Yurkovskiy\Partitions\PartitionBuilder model(\Illuminate\Database\Eloquent\Model|string $model)
 * @method static \Yurkovskiy\Partitions\PartitionBuilder daily(\Illuminate\Database\Eloquent\Model|string $table, string $column = 'created_at')
 * @method static \Yurkovskiy\Partitions\PartitionBuilder weekly(\Illuminate\Database\Eloquent\Model|string $table, string $column = 'created_at')
 * @method static \Yurkovskiy\Partitions\PartitionBuilder monthly(\Illuminate\Database\Eloquent\Model|string $table, string $column = 'created_at')
 * @method static \Yurkovskiy\Partitions\PartitionBuilder yearly(\Illuminate\Database\Eloquent\Model|string $table, string $column = 'created_at')
 * @method static \Yurkovskiy\Partitions\PartitionBuilder for(string $key)
 * @method static \Yurkovskiy\Partitions\PartitionBuilder forTable(string $table, ?string $connection = null)
 * @method static bool isConfigured(string $key)
 * @method static list<string> configuredKeys()
 * @method static \Illuminate\Support\Collection<string, \Yurkovskiy\Partitions\PartitionBuilder> configured()
 * @method static array<string, mixed> config()
 */
class Partition extends Facade
{
    protected static $cached = false;

    protected static function getFacadeAccessor(): string
    {
        return PartitionManager::class;
    }
}

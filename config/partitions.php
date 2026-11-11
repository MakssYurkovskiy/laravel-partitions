<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    |
    | Applied to every builder created through the Partition facade. Each of
    | them can be overridden per table below, or fluently on the builder.
    |
    */

    // Connection used when neither the table nor its model names one
    // (null = the default connection of the application).
    'connection' => env('PARTITIONS_CONNECTION'),

    // Prefix of generated partition names: p20250613, p202524, p202506, p2025.
    'prefix' => 'p',

    // Name of the catch all MAXVALUE partition (false = work without one).
    'max_partition' => 'pMax',

    // Tablespace clause added to every partition, e.g. 'innodb_file_per_table'.
    'tablespace' => env('PARTITIONS_TABLESPACE'),

    // How the partitioning key is derived: 'to_days', 'columns' or 'unix_timestamp'.
    // Read like 'strategy' below: any letter case, surrounding whitespace ignored.
    'expression' => 'to_days',

    // Timezone 'unix_timestamp' boundaries are computed in, which has to be the
    // one the MySQL session uses (null = connection timezone, then app timezone).
    'timezone' => env('PARTITIONS_TIMEZONE'),

    // Ask the server what it makes of a datetime literal before running DDL for
    // 'unix_timestamp', and refuse to write boundaries when the answer is off.
    'verify_timezone' => false,

    // How many periods ahead of today partitions are pre-created.
    'future' => 7,

    // Partitions dropped or truncated per ALTER statement (null = all in one).
    'chunk' => 1,

    // Partitions added per ALTER statement (null = all in one).
    'sync_chunk' => null,

    // Start from the last covered period, so skipped periods are created as well.
    'fill_gaps' => true,

    // How many skipped periods a single run fills, oldest first (null = no limit).
    'max_gaps' => 12,

    /*
    |--------------------------------------------------------------------------
    | Scheduled maintenance
    |--------------------------------------------------------------------------
    |
    | When enabled, "partitions:run" is scheduled automatically: it adds the
    | upcoming partitions and drops the ones outside the retention window.
    |
    */

    'schedule' => [
        'enabled' => env('PARTITIONS_SCHEDULE_ENABLED', false),
        'cron' => env('PARTITIONS_SCHEDULE_CRON', '15 3 * * *'),
        'on_one_server' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tables
    |--------------------------------------------------------------------------
    |
    | Each entry is addressable by key: Partition::for('transactions')->sync() and
    | php artisan partitions:run transactions.
    |
    |   'table'            Table name, defaults to the key.
    |   'strategy'         daily | weekly | monthly | yearly ('day' and 'd' work
    |                      too), a \Yurkovskiy\Partitions\Schema\PartitionPeriod
    |                      case, or the class name of a custom PartitionStrategy
    |                      implementation. Names are read in any letter case,
    |                      surrounding whitespace ignored, and a period name is
    |                      matched before a class called the same.
    |   'column'           Date column the partitioning key is built from.
    |   'expression'       to_days | columns | unix_timestamp, read the same way
    |                      as 'strategy'.
    |   'timezone'         Reference frame for unix_timestamp boundaries.
    |   'verify_timezone'  Check the session timezone before DDL.
    |   'future'           Periods pre-created ahead of today.
    |   'retain'           Complete periods kept before the current one, which
    |                      is always kept (null = keep everything).
    |   'connection'       Connection the table lives on, ahead of the model's
    |                      own one and of the default above (null = not given).
    |   'fill_gaps'        Create the periods the table skipped.
    |   'max_gaps'         Skipped periods filled per run (null = no limit).
    |   'chunk'            Partitions dropped per ALTER statement (null = all
    |                      in one).
    |   'sync_chunk'       Partitions added per ALTER statement (null = all in
    |                      one).
    |
    */

    'tables' => [
        // 'transactions' => [
        //     'strategy' => 'daily',
        //     'column' => 'created_at',
        //     'future' => 14,
        //     'retain' => 90,
        // ],
    ],

];

<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Support;

use Illuminate\Database\Eloquent\Model;

class ArchiveEvent extends Model
{
    protected $table = 'events';

    protected $connection = 'archive';
}

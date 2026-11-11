<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Support;

use Illuminate\Database\Eloquent\Model;

class PlainEvent extends Model
{
    protected $table = 'events';
}

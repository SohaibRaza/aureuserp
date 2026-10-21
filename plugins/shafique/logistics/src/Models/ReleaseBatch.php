<?php

namespace Shafique\Logistics\Models;

use Illuminate\Database\Eloquent\Model;

class ReleaseBatch extends Model
{
    protected $table = 'logistics_release_batches';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['operation_results' => 'array', 'released_at' => 'immutable_datetime'];
    }
}

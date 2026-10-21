<?php

namespace Shafique\Logistics\Models;

use Illuminate\Database\Eloquent\Model;

class DispatchCheck extends Model
{
    protected $table = 'logistics_dispatch_checks';

    public $timestamps = false;

    // Preserve the time zone when PostgreSQL stores time-zone-aware timestamps.
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['loading_deadline' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime'];
    }
}

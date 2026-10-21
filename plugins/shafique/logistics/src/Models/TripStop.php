<?php

namespace Shafique\Logistics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TripStop extends Model
{
    protected $table = 'logistics_trip_stops';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['address_snapshot' => 'array'];
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(TripAllocation::class, 'stop_id');
    }
}

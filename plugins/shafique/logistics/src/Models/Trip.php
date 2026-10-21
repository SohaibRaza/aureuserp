<?php

namespace Shafique\Logistics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Webkul\Inventory\Models\Location;
use Webkul\Support\Traits\BelongsToCompany;

class Trip extends Model
{
    use BelongsToCompany;

    protected $table = 'logistics_trips';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'verified_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime'];
    }

    public function stops(): HasMany
    {
        return $this->hasMany(TripStop::class, 'trip_id')->orderBy('sequence');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(TripAllocation::class, 'trip_id');
    }

    public function loadRevision(): BelongsTo
    {
        return $this->belongsTo(LoadRevision::class, 'load_revision_id');
    }

    public function loadVerification(): HasOne
    {
        return $this->hasOne(LoadVerificationEvent::class, 'load_revision_id', 'load_revision_id');
    }

    public function releaseBatches(): HasMany
    {
        return $this->hasMany(ReleaseBatch::class, 'trip_id');
    }

    public function recoveryEvents(): HasMany
    {
        return $this->hasMany(TripRecoveryEvent::class, 'trip_id')->orderBy('id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(ReceiptObservation::class, 'trip_id');
    }

    public function sourceLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'source_location_id');
    }
}

<?php

namespace Shafique\Logistics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoadRevision extends Model
{
    protected $table = 'logistics_load_revisions';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['trip_revision' => 'integer', 'revision' => 'integer', 'recorded_at' => 'immutable_datetime', 'verified_at' => 'immutable_datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(LoadItem::class, 'load_revision_id');
    }
}

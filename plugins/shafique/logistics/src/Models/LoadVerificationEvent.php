<?php

namespace Shafique\Logistics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoadVerificationEvent extends Model
{
    protected $table = 'logistics_load_verification_events';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['verified_at' => 'immutable_datetime'];
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Evidence::class, 'evidence_id');
    }
}

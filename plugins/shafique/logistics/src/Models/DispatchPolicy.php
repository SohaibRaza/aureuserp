<?php

namespace Shafique\Logistics\Models;

use Illuminate\Database\Eloquent\Model;

class DispatchPolicy extends Model
{
    protected $table = 'logistics_dispatch_policies';

    public $timestamps = false;

    // Preserve the time zone when PostgreSQL stores time-zone-aware timestamps.
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['source_location_ids' => 'array', 'product_rules' => 'array', 'require_load_evidence' => 'boolean', 'require_receipt_evidence' => 'boolean', 'approved_at' => 'immutable_datetime'];
    }
}

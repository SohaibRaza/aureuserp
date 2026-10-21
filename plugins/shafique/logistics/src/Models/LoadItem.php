<?php

namespace Shafique\Logistics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Inventory\Models\Move;
use Webkul\Inventory\Models\MoveLine;

class LoadItem extends Model
{
    protected $table = 'logistics_load_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['stock_quantity' => 'decimal:4', 'native_line_snapshot' => 'array'];
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(TripAllocation::class, 'allocation_id');
    }

    public function move(): BelongsTo
    {
        return $this->belongsTo(Move::class, 'move_id');
    }

    public function moveLine(): BelongsTo
    {
        return $this->belongsTo(MoveLine::class, 'move_line_id');
    }
}

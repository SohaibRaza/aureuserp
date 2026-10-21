<?php

namespace Shafique\Logistics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Product\Models\Product;
use Webkul\Sale\Models\OrderLine;
use Webkul\Sale\Models\QuantityCommitment;

class TripAllocation extends Model
{
    protected $table = 'logistics_trip_allocations';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['stock_quantity' => 'decimal:4', 'loaded_quantity' => 'decimal:4', 'released_quantity' => 'decimal:4'];
    }

    public function quantityCommitment(): BelongsTo
    {
        return $this->belongsTo(QuantityCommitment::class, 'quantity_commitment_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class, 'order_line_id');
    }
}

<?php

namespace Webkul\Sale\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Support\Traits\BelongsToCompany;

class QuantityCommitment extends Model
{
    use BelongsToCompany;

    protected $table = 'sales_quantity_commitments';

    protected $guarded = ['*'];

    public const UPDATED_AT = null;

    protected $casts = [
        'data'       => 'array',
        'revision'   => 'integer',
        'created_at' => 'immutable_datetime',
    ];

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }
}

<?php

namespace Shafique\Logistics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Support\Models\Company;

class CompanyControl extends Model
{
    protected $table = 'logistics_company_controls';

    protected $fillable = [
        'company_id',
        'revision',
        'accept_new_activity',
    ];

    protected function casts(): array
    {
        return [
            'revision'            => 'integer',
            'accept_new_activity' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function changes(): HasMany
    {
        return $this->hasMany(ControlChange::class, 'company_id', 'company_id');
    }
}

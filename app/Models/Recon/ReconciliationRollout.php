<?php

namespace App\Models\Recon;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Support\Models\Company;

class ReconciliationRollout extends Model
{
    protected $fillable = [
        'company_id', 'phase', 'backup_confirmed_at', 'staging_verified_at',
        'controller_signed_off_at', 'controller_name', 'enabled_at', 'enabled_by',
        'disabled_at', 'disabled_by', 'disable_reason',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    protected function casts(): array
    {
        return [
            'backup_confirmed_at'      => 'datetime',
            'staging_verified_at'      => 'datetime',
            'controller_signed_off_at' => 'datetime',
            'enabled_at'               => 'datetime',
            'disabled_at'              => 'datetime',
        ];
    }
}

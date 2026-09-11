<?php

namespace App\Models\Recon;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Account\Models\Journal;
use Webkul\Support\Models\Company;

class ReconciliationRule extends Model
{
    protected $fillable = [
        'company_id', 'journal_id', 'name', 'priority', 'conditions', 'action', 'approval_threshold',
        'version', 'active', 'active_from', 'active_until', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'conditions'         => 'array',
            'approval_threshold' => 'decimal:2',
            'active'             => 'boolean',
            'active_from'        => 'date',
            'active_until'       => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }
}

<?php

namespace App\Models\Recon;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class ReconciliationSnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'recon_reconciliation_snapshots';

    protected $fillable = [
        'company_id', 'reconciliation_id', 'version', 'payload', 'payload_hash', 'created_by',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return [
            'payload'    => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('Reconciliation snapshots are immutable.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('Reconciliation snapshots are immutable.');
        });
    }
}

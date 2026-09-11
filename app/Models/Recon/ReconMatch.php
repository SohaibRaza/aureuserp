<?php

namespace App\Models\Recon;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReconMatch extends Model
{
    public const STATUSES = ['proposed', 'confirmed', 'rejected', 'reversed'];

    protected $table = 'recon_matches';

    protected $fillable = [
        'company_id', 'reconciliation_id', 'status', 'match_type', 'amount', 'variance', 'notes',
        'confirmed_at', 'confirmed_by', 'reversed_at', 'reversed_by', 'reversal_reason', 'creator_id', 'rule_id',
        'accounting_mode', 'accounting_partial_ids', 'accounting_full_ids', 'ledger_previous_residuals',
        'accounting_applied_amount', 'accounting_applied_at', 'accounting_applied_by',
        'accounting_reversed_at', 'accounting_reversed_by', 'idempotency_key', 'idempotency_fingerprint',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(ReconMatchLine::class, 'match_id');
    }

    public function bankLines(): HasMany
    {
        return $this->hasMany(ReconMatchLine::class, 'match_id')->where('side', 'bank');
    }

    public function ledgerLines(): HasMany
    {
        return $this->hasMany(ReconMatchLine::class, 'match_id')->where('side', 'ledger');
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class, 'reconciliation_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(ReconciliationRule::class, 'rule_id');
    }

    protected function casts(): array
    {
        return [
            'amount'                    => 'decimal:4',
            'variance'                  => 'decimal:4',
            'confirmed_at'              => 'datetime',
            'reversed_at'               => 'datetime',
            'accounting_partial_ids'    => 'array',
            'accounting_full_ids'       => 'array',
            'ledger_previous_residuals' => 'array',
            'accounting_applied_amount' => 'decimal:4',
            'accounting_applied_at'     => 'datetime',
            'accounting_reversed_at'    => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $match): void {
            $match->company_id ??= $match->reconciliation()->value('company_id');
        });
    }
}

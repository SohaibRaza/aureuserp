<?php

namespace App\Models\Recon;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconAdjustment extends Model
{
    public const TYPES = [
        'bank_fee'      => 'Bank fee',
        'interest'      => 'Interest earned',
        'direct_charge' => 'Direct charge / debit',
        'nsf'           => 'NSF / returned check',
        'fx_difference' => 'FX difference',
        'transfer'      => 'Bank transfer',
        'correction'    => 'Book correction',
        'other'         => 'Other',
    ];

    protected $table = 'recon_adjustments';

    protected $fillable = [
        'company_id', 'reconciliation_id', 'currency_id', 'type', 'amount', 'counterpart_account_id',
        'move_id', 'statement_line_id', 'notes', 'posted_at', 'reversed_at', 'reversed_by',
        'reversal_reason', 'creator_id',
    ];

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class, 'reconciliation_id');
    }

    protected function casts(): array
    {
        return [
            'amount'      => 'decimal:4',
            'posted_at'   => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $adjustment): void {
            $reconciliation = $adjustment->reconciliation()->first(['company_id', 'currency_id']);
            $adjustment->company_id ??= $reconciliation?->company_id;
            $adjustment->currency_id ??= $reconciliation?->currency_id;
        });
    }
}

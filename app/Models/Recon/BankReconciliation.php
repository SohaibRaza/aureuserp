<?php

namespace App\Models\Recon;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\BankStatement;
use Webkul\Account\Models\Journal;
use Webkul\Partner\Models\BankAccount;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

class BankReconciliation extends Model
{
    public const STATUSES = [
        'draft', 'in_progress', 'review', 'completed', 'reopen_requested',
        'reopened', 'stale', 'cancelled', 'legacy_unverified',
    ];

    protected $table = 'recon_bank_reconciliations';

    protected $fillable = [
        'company_id', 'journal_id', 'bank_account_id', 'liquidity_account_id', 'currency_id',
        'statement_id', 'name', 'status', 'statement_date', 'period_start', 'period_end',
        'prior_reconciliation_id', 'continuity_type', 'continuity_reason',
        'balance_start', 'balance_end_real',
        'gl_balance_start', 'gl_balance_end', 'difference',
        'submitted_at', 'submitted_by', 'reviewed_at', 'reviewed_by',
        'completed_at', 'completed_by', 'reopen_requested_at', 'reopen_requested_by',
        'reopen_request_reason', 'reopened_at', 'reopened_by', 'reopen_reason',
        'stale_at', 'stale_reason', 'cancelled_at', 'cancelled_by', 'cancellation_reason',
        'legacy_verified_at', 'creator_id',
    ];

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'journal_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function liquidityAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'liquidity_account_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'statement_id');
    }

    public function priorReconciliation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'prior_reconciliation_id');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(ReconMatch::class, 'reconciliation_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(ReconAdjustment::class, 'reconciliation_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ReconEvent::class, 'reconciliation_id')->orderBy('created_at')->orderBy('id');
    }

    public function importBatches(): HasMany
    {
        return $this->hasMany(ReconImportBatch::class, 'reconciliation_id');
    }

    public static function logEvent(self $reconciliation, string $event, array $payload = []): void
    {
        ReconEvent::create([
            'reconciliation_id' => $reconciliation->id,
            'company_id'        => $reconciliation->company_id,
            'actor_id'          => auth()->id(),
            'event'             => $event,
            'payload'           => $payload,
        ]);
    }

    public function reconcilingItems(): HasMany
    {
        return $this->hasMany(ReconcilingItem::class, 'reconciliation_id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(ReconciliationSnapshot::class, 'reconciliation_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    protected function casts(): array
    {
        return [
            'statement_date'      => 'date',
            'period_start'        => 'date',
            'period_end'          => 'date',
            'balance_start'       => 'decimal:4',
            'balance_end_real'    => 'decimal:4',
            'gl_balance_start'    => 'decimal:4',
            'gl_balance_end'      => 'decimal:4',
            'difference'          => 'decimal:4',
            'submitted_at'        => 'datetime',
            'reviewed_at'         => 'datetime',
            'completed_at'        => 'datetime',
            'reopen_requested_at' => 'datetime',
            'reopened_at'         => 'datetime',
            'stale_at'            => 'datetime',
            'cancelled_at'        => 'datetime',
            'legacy_verified_at'  => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $reconciliation): void {
            $journal = Journal::query()->find($reconciliation->journal_id);

            if (! $journal) {
                return;
            }

            $reconciliation->company_id ??= $journal->company_id;
            $reconciliation->bank_account_id ??= $journal->bank_account_id;
            $reconciliation->liquidity_account_id ??= $journal->default_account_id;
            $reconciliation->currency_id ??= $journal->currency_id ?: $journal->company?->currency_id;
        });
    }
}

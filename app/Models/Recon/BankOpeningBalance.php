<?php

namespace App\Models\Recon;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Partner\Models\BankAccount;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

class BankOpeningBalance extends Model
{
    protected $table = 'recon_bank_opening_balances';

    protected $fillable = [
        'company_id', 'bank_account_id', 'journal_id', 'liquidity_account_id',
        'counterpart_account_id', 'currency_id', 'move_id', 'effective_date',
        'amount', 'notes', 'creator_id',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'amount'         => 'decimal:4',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function liquidityAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'liquidity_account_id');
    }

    public function counterpartAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'counterpart_account_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function move(): BelongsTo
    {
        return $this->belongsTo(Move::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('Bank opening-balance evidence is immutable. Post a documented reversing entry to correct it.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('Bank opening-balance evidence is immutable. Post a documented reversing entry to correct it.');
        });
    }
}

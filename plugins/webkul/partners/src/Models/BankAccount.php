<?php

namespace Webkul\Partner\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Webkul\Partner\Database\Factories\BankAccountFactory;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Bank;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

class BankAccount extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'partners_bank_accounts';

    protected $fillable = [
        'account_number',
        'account_holder_name',
        'is_active',
        'can_send_money',
        'creator_id',
        'partner_id',
        'company_id',
        'currency_id',
        'bank_id',
        'source_type',
        'source_identifier',
    ];

    protected $casts = [
        'is_active'      => 'boolean',
        'can_send_money' => 'boolean',
    ];

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (BankAccount $bankAccount): void {
            $bankAccount->creator_id ??= Auth::id();
        });

        static::saving(function (BankAccount $bankAccount): void {
            $bankAccount->account_holder_name = $bankAccount->company?->name
                ?? $bankAccount->partner?->name
                ?? throw new \LogicException('A bank account must belong to a company or partner.');
        });
    }

    protected static function newFactory(): BankAccountFactory
    {
        return BankAccountFactory::new();
    }
}

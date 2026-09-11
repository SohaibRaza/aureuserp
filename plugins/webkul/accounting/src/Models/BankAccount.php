<?php

namespace Webkul\Accounting\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Partner\Models\BankAccount as BaseBankAccount;

class BankAccount extends BaseBankAccount
{
    public function journals(): HasMany
    {
        return $this->hasMany(Journal::class, 'bank_account_id');
    }
}

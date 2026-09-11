<?php

namespace App\Policies;

use App\Models\Recon\BankReconciliation;
use Webkul\Security\Models\User;

class BankReconciliationPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'reconciliation.view');
    }

    public function view(User $user, BankReconciliation $reconciliation): bool
    {
        return $this->sameCompany($user, $reconciliation) && $this->allowed($user, 'reconciliation.view');
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'reconciliation.prepare');
    }

    public function postOpeningBalance(User $user): bool
    {
        return $this->allowed($user, 'reconciliation.opening_balance.post');
    }

    public function update(User $user, BankReconciliation $reconciliation): bool
    {
        return $this->sameCompany($user, $reconciliation) && $this->allowed($user, 'reconciliation.prepare');
    }

    public function review(User $user, BankReconciliation $reconciliation): bool
    {
        return $this->sameCompany($user, $reconciliation) && $this->allowed($user, 'reconciliation.review');
    }

    public function complete(User $user, BankReconciliation $reconciliation): bool
    {
        return $this->sameCompany($user, $reconciliation) && $this->allowed($user, 'reconciliation.complete');
    }

    public function reopen(User $user, BankReconciliation $reconciliation): bool
    {
        return $this->sameCompany($user, $reconciliation) && $this->allowed($user, 'reconciliation.reopen');
    }

    private function sameCompany(User $user, BankReconciliation $reconciliation): bool
    {
        return (int) ($user->default_company_id ?: $user->company_id) === (int) $reconciliation->company_id
            || $user->allowedCompanies()->whereKey($reconciliation->company_id)->exists();
    }

    private function allowed(User $user, string $permission): bool
    {
        return $user->hasRole('Admin') || $user->can($permission);
    }
}

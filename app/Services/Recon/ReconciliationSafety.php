<?php

namespace App\Services\Recon;

use App\Models\Recon\ReconciliationRollout;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class ReconciliationSafety
{
    public function financialMutationsEnabled(?int $companyId = null): bool
    {
        if (! config('reconciliation.financial_mutations_enabled', false)) {
            return false;
        }

        if (! config('reconciliation.require_company_rollout', false)) {
            return true;
        }

        $companyId ??= current_company_id();

        return $companyId
            && Schema::hasTable('reconciliation_rollouts')
            && ReconciliationRollout::query()
                ->where('company_id', $companyId)
                ->whereNotNull('enabled_at')
                ->whereNull('disabled_at')
                ->exists();
    }

    public function assertFinancialMutationsEnabled(?int $companyId = null): void
    {
        if (! $this->financialMutationsEnabled($companyId)) {
            throw new InvalidArgumentException('Financial reconciliation actions are temporarily disabled while reconciliation controls are being upgraded.');
        }
    }
}

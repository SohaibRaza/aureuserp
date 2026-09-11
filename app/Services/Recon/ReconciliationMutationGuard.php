<?php

namespace App\Services\Recon;

use App\Models\Recon\BankReconciliation;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class ReconciliationMutationGuard
{
    public const EDITABLE_STATUSES = ['draft', 'in_progress', 'reopened'];

    public function __construct(private readonly ReconciliationSafety $safety) {}

    public function assert(
        BankReconciliation $reconciliation,
        string $ability = 'update',
        array $allowedStatuses = self::EDITABLE_STATUSES,
        string $lockedMessage = 'Reconciliation is locked. Reopen it before making changes.',
    ): void {
        $this->safety->assertFinancialMutationsEnabled($reconciliation->company_id);
        Gate::authorize($ability, $reconciliation);

        if (current_company_id() && (int) current_company_id() !== (int) $reconciliation->company_id) {
            throw new InvalidArgumentException('The reconciliation is outside the active company.');
        }

        if (! in_array($reconciliation->status, $allowedStatuses, true)) {
            throw new InvalidArgumentException($lockedMessage);
        }
    }
}

<?php

namespace Webkul\Account\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Webkul\Account\Models\Move;

class AccountingDateGuard
{
    public function assertReconciliationPeriodOpen(Move $move, mixed $effectiveDate = null): void
    {
        if (! $move->company_id || ! $move->date || ! Schema::hasTable('recon_bank_reconciliations')) {
            return;
        }

        $accountIds = $move->lines()->pluck('account_id');
        if ($accountIds->isEmpty()) {
            return;
        }

        $locked = DB::table('recon_bank_reconciliations')
            ->where('company_id', $move->company_id)
            ->where('status', 'completed')
            ->whereDate('statement_date', '>=', $effectiveDate ?? $move->date)
            ->whereIn('liquidity_account_id', $accountIds)
            ->exists();

        if ($locked) {
            throw new RuntimeException('This transaction affects a completed bank reconciliation period. Reopen the reconciliation first.');
        }
    }
}

<?php

namespace Webkul\Account\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LogicException;
use Webkul\Account\Models\Move;

class AccountingDateGuard
{
    /**
     * Posting and reconciliation retain a shared gate until commit; completion takes it exclusively.
     * Never wait here: callers may already hold different accounting or reconciliation row locks.
     */
    public function lockPeriodBoundary(int $companyId, bool $completing = false): void
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() === 0) {
            throw new LogicException('Accounting period locks require a transaction.');
        }

        if ($connection->getDriverName() === 'mysql') {
            $isolation = $connection->selectOne('SELECT @@transaction_isolation AS isolation')->isolation;
            if (strtolower(str_replace('-', ' ', $isolation)) !== 'read committed') {
                throw new LogicException('Accounting period boundaries require READ COMMITTED isolation.');
            }
            try {
                // The Company exists before any journal; row locks survive the enclosing transaction.
                $company = $connection->table('companies')->where('id', $companyId)
                    ->lock($completing ? 'FOR UPDATE NOWAIT' : 'FOR SHARE NOWAIT')->first(['id']);
            } catch (QueryException $exception) {
                if ((int) ($exception->errorInfo[1] ?? 0) !== 3572) {
                    throw $exception;
                }
                throw ValidationException::withMessages(['date' => __('accounts::system.move.bank-period-busy')]);
            }
            if (! $company) {
                throw new LogicException('Accounting period locks require an existing Company.');
            }

            return;
        }
        if ($connection->getDriverName() !== 'pgsql') {
            throw new LogicException('Accounting period locks require PostgreSQL or MySQL.');
        }

        $function = $completing ? 'pg_try_advisory_xact_lock' : 'pg_try_advisory_xact_lock_shared';
        // A two-integer namespace is separate from the payment registrar's bigint advisory keys.
        $lock = $connection->selectOne("SELECT current_setting('transaction_isolation') AS isolation, {$function}(?, ?) AS acquired", [0x41525044, $companyId]);
        if ($lock->isolation !== 'read committed') {
            throw new LogicException('Accounting period boundaries require READ COMMITTED isolation.');
        }
        if (! $lock->acquired) {
            throw ValidationException::withMessages(['date' => __('accounts::system.move.bank-period-busy')]);
        }
    }

    /**
     * Line-level reconciliation supplies only the lines it mutates; lifecycle changes affect the whole entry.
     *
     * @param  list<int>|null  $affectedLineIds
     */
    public function assertReconciliationPeriodOpen(Move $move, mixed $effectiveDate = null, ?array $affectedLineIds = null): void
    {
        if (! $move->company_id || ! $move->date || ! Schema::hasTable('recon_bank_reconciliations')) {
            return;
        }

        $this->lockPeriodBoundary((int) $move->company_id);

        $accountIds = $move->lines()->when($affectedLineIds !== null, fn ($query) => $query->whereKey($affectedLineIds))->pluck('account_id');
        if ($accountIds->isEmpty()) {
            return;
        }

        $locked = DB::table('recon_bank_reconciliations')
            ->where('company_id', $move->company_id)
            ->whereIn('status', ['completed', 'reopen_requested'])
            ->whereDate('statement_date', '>=', $effectiveDate ?? $move->date)
            ->whereIn('liquidity_account_id', $accountIds)
            ->exists();

        if ($locked) {
            throw ValidationException::withMessages(['date' => __('accounts::system.move.completed-bank-period')]);
        }
    }
}

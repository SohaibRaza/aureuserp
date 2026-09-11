<?php

namespace App\Services\Recon;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LegacyReconciliationAuditor
{
    /** @return array<int, array{code: string, count: int, message: string}> */
    public function findings(?int $companyId = null): array
    {
        if (! Schema::hasTable('recon_bank_reconciliations')) {
            return [$this->finding('schema_missing', 1, 'Reconciliation schema is not installed.')];
        }

        $findings = [];
        $reconciliations = $this->scoped('recon_bank_reconciliations as reconciliation', $companyId, 'reconciliation.company_id');
        $this->add($findings, 'legacy_unverified', (clone $reconciliations)->where('reconciliation.status', 'legacy_unverified')->count(), 'Legacy reconciliations still require controller disposition.');
        $this->add($findings, 'invalid_period', (clone $reconciliations)->where(function (Builder $query): void {
            $query->whereNull('reconciliation.period_start')->orWhereNull('reconciliation.period_end')
                ->orWhereColumn('reconciliation.period_end', '<', 'reconciliation.period_start');
        })->count(), 'Reconciliations have missing or invalid periods.');
        $this->add($findings, 'orphan_identity', (clone $reconciliations)
            ->leftJoin('accounts_journals as journal', 'journal.id', '=', 'reconciliation.journal_id')
            ->leftJoin('accounts_bank_statements as statement', 'statement.id', '=', 'reconciliation.statement_id')
            ->leftJoin('partners_bank_accounts as bank_account', 'bank_account.id', '=', 'reconciliation.bank_account_id')
            ->leftJoin('accounts_accounts as liquidity_account', 'liquidity_account.id', '=', 'reconciliation.liquidity_account_id')
            ->where(function (Builder $query): void {
                $query->whereNull('reconciliation.company_id')->orWhereNull('reconciliation.currency_id')
                    ->orWhereNull('journal.id')->orWhereNull('bank_account.id')->orWhereNull('liquidity_account.id')
                    ->orWhereColumn('journal.company_id', '<>', 'reconciliation.company_id')
                    ->orWhere(function (Builder $statement): void {
                        $statement->whereNotNull('reconciliation.statement_id')->where(function (Builder $mismatch): void {
                            $mismatch->whereNull('statement.id')
                                ->orWhereColumn('statement.company_id', '<>', 'reconciliation.company_id')
                                ->orWhereColumn('statement.journal_id', '<>', 'reconciliation.journal_id');
                        });
                    });
            })->count(), 'Reconciliations have missing or cross-company account identity.');

        $orphans = DB::table('recon_matches as match')
            ->leftJoin('recon_bank_reconciliations as reconciliation', 'reconciliation.id', '=', 'match.reconciliation_id')
            ->whereNull('reconciliation.id')->when($companyId, fn (Builder $query): Builder => $query->where('match.company_id', $companyId))->count();
        $orphans += DB::table('recon_match_lines as line')->leftJoin('recon_matches as match', 'match.id', '=', 'line.match_id')
            ->whereNull('match.id')->when($companyId, fn (Builder $query): Builder => $query->where('line.company_id', $companyId))->count();
        $this->add($findings, 'orphan_relation', $orphans, 'Reconciliation child records have no valid parent.');

        $duplicates = $this->scoped('recon_bank_reconciliations', $companyId)
            ->whereNotNull('bank_account_id')->whereIn('status', ['draft', 'in_progress', 'review', 'reopen_requested', 'reopened'])
            ->select(['company_id', 'bank_account_id'])
            ->groupBy('company_id', 'bank_account_id')->havingRaw('COUNT(*) > 1')->get()->count();
        $this->add($findings, 'duplicate_active_session', $duplicates, 'A bank account has more than one active reconciliation.');

        if (Schema::hasTable('accounts_partial_reconciles') && Schema::hasColumn('accounts_partial_reconciles', 'recon_match_id')) {
            $partialReconciles = DB::table('accounts_partial_reconciles as partial')
                ->join('recon_matches as match', 'match.id', '=', 'partial.recon_match_id')
                ->join('recon_bank_reconciliations as reconciliation', 'reconciliation.id', '=', 'match.reconciliation_id')
                ->where('reconciliation.status', 'legacy_unverified')
                ->when($companyId, fn (Builder $query): Builder => $query->where('reconciliation.company_id', $companyId))->count();
            $this->add($findings, 'unverified_partial_reconcile', $partialReconciles, 'Legacy reconciliation matches have accounting partial reconciles.');
        }

        $effects = DB::table('recon_adjustments as adjustment')
            ->join('recon_bank_reconciliations as reconciliation', 'reconciliation.id', '=', 'adjustment.reconciliation_id')
            ->where('reconciliation.status', 'legacy_unverified')->whereNotNull('adjustment.move_id')
            ->when($companyId, fn (Builder $query): Builder => $query->where('reconciliation.company_id', $companyId))->count();
        $effects += DB::table('accounts_bank_statement_lines as line')
            ->join('recon_bank_reconciliations as reconciliation', 'reconciliation.statement_id', '=', 'line.statement_id')
            ->where('reconciliation.status', 'legacy_unverified')
            ->where(fn (Builder $query): Builder => $query->where('line.is_reconciled', true)->orWhereNotNull('line.move_id'))
            ->when($companyId, fn (Builder $query): Builder => $query->where('reconciliation.company_id', $companyId))->count();
        $this->add($findings, 'legacy_posted_effect', $effects, 'Legacy reconciliation adjustments reference posted accounting moves.');

        return $findings;
    }

    /** @return array<int, array{code: string, count: int, message: string}> */
    public function configurationFindings(int $companyId): array
    {
        $bankJournals = DB::table('accounts_journals as journal')
            ->leftJoin('companies as company', 'company.id', '=', 'journal.company_id')
            ->where('journal.company_id', $companyId)
            ->whereIn('journal.type', ['bank', 'credit']);

        $journalCount = (clone $bankJournals)->count();
        $missing = (clone $bankJournals)
            ->where(function (Builder $query): void {
                $query->whereNull('journal.bank_account_id')->orWhereNull('journal.default_account_id')
                    ->orWhereRaw('COALESCE(journal.currency_id, company.currency_id) IS NULL');
            })->count();

        if ($journalCount === 0) {
            $missing = 1;
        }

        return $missing ? [$this->finding('missing_bank_configuration', $missing, 'At least one bank journal is required, and every bank journal must have a bank account, liquidity account, and currency.')] : [];
    }

    private function scoped(string $table, ?int $companyId, string $column = 'company_id'): Builder
    {
        return DB::table($table)->when($companyId, fn (Builder $query): Builder => $query->where($column, $companyId));
    }

    /** @param array<int, array{code: string, count: int, message: string}> $findings */
    private function add(array &$findings, string $code, int $count, string $message): void
    {
        if ($count > 0) {
            $findings[] = $this->finding($code, $count, $message);
        }
    }

    /** @return array{code: string, count: int, message: string} */
    private function finding(string $code, int $count, string $message): array
    {
        return compact('code', 'count', 'message');
    }
}

<?php

namespace App\Services\Recon;

use App\Models\Recon\BankReconciliation;
use App\Models\Recon\ReconMatchLine;
use App\Models\Recon\StatementLine as BankStatementLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinalizationService
{
    public function __construct(
        private readonly ReconciliationCalculator $calculator,
        private readonly ReconciliationMutationGuard $mutationGuard,
    ) {}

    public function computeDifference(BankReconciliation $r): array
    {
        return $this->calculator->calculate($r);
    }

    /**
     * Journal liquidity account, resolved via DB facade (immune to company
     * global scopes that can hide the relation in web context).
     */
    public function liquidityAccountId(BankReconciliation $r): int
    {
        return $this->calculator->liquidityAccountId($r);
    }

    /**
     * Refresh stored difference on the session row.
     */
    public function refreshDifference(BankReconciliation $r): array
    {
        return DB::transaction(function () use ($r): array {
            $r = BankReconciliation::query()->lockForUpdate()->findOrFail($r->id);
            $this->mutationGuard->assert($r);
            $data = $this->computeDifference($r);

            $r->update([
                'gl_balance_start' => $data['gl_balance_start'],
                'gl_balance_end'   => $data['gl_balance_end'],
                'difference'       => $data['difference'],
            ]);

            return $data;
        });
    }

    /**
     * All checks that gate completion.
     */
    public function completionCheck(BankReconciliation $r): array
    {
        $issues = [];

        $statementLines = BankStatementLine::where('statement_id', $r->statement_id)->get();

        if ($statementLines->isEmpty()) {
            $issues[] = 'No statement lines imported.';
        }

        $calculation = $this->computeDifference($r);
        $unmatched = Schema::hasColumn('accounts_bank_statement_lines', 'terminal_treatment')
            ? $statementLines->whereNull('terminal_treatment')
            : $statementLines->where('is_reconciled', false);
        if ($unmatched->isNotEmpty()) {
            $issues[] = sprintf('%d statement line(s) have no approved terminal treatment.', $unmatched->count());
        }

        $invalidTreatments = $statementLines->filter(fn (BankStatementLine $line): bool => ! $this->hasValidTerminalReference($r, $line));
        if ($invalidTreatments->isNotEmpty()) {
            $issues[] = sprintf('%d statement line treatment(s) have missing, reversed, or invalid source evidence.', $invalidTreatments->count());
        }

        $proposed = $r->matches()->where('status', 'proposed')->count();
        if ($proposed > 0) {
            $issues[] = sprintf('%d proposal(s) awaiting review.', $proposed);
        }

        $arith = abs(
            ((float) $r->balance_start + (float) $statementLines->sum('amount')) - (float) $r->balance_end_real
        );

        if ($arith > 0.005) {
            $issues[] = sprintf('Statement arithmetic off by %.2f (start + Σ lines ≠ declared closing).', $arith);
        }

        $diff = $calculation;

        if (! $diff['is_balanced']) {
            $issues[] = sprintf('Difference of the day is %.2f — must be zero to complete.', $diff['difference']);
        }

        if ($diff['blocking_item_ids']) {
            $issues[] = sprintf('%d reconciling item(s) have an unsupported classification, side, or source.', count($diff['blocking_item_ids']));
        }

        return ['can_complete' => empty($issues), 'issues' => $issues, 'difference' => $diff];
    }

    /**
     * Finalize: lock everything.
     */
    public function complete(BankReconciliation $r): BankReconciliation
    {
        return app(ReconciliationLifecycleService::class)->complete($r);
    }

    /**
     * Reopen a completed reconciliation (permissioned upstream; reason required).
     */
    public function reopen(BankReconciliation $r, string $reason): BankReconciliation
    {
        return app(ReconciliationLifecycleService::class)->reopen($r, $reason);
    }

    /**
     * Exception queue for the workspace: everything that needs attention.
     */
    public function exceptions(BankReconciliation $r): array
    {
        $statementLines = BankStatementLine::where('statement_id', $r->statement_id)->orderBy('id')->get();

        $matchedStatementIds = ReconMatchLine::whereIn('match_id', function ($q) use ($r) {
            $q->select('id')->from('recon_matches')
                ->where('reconciliation_id', $r->id)
                ->whereIn('status', ['proposed', 'confirmed']);
        })
            ->where('lineable_type', ReconMatchLine::TYPE_STATEMENT_LINE)
            ->pluck('lineable_id')
            ->all();

        $unmatched = $statementLines->reject(fn ($l) => $l->terminal_treatment || in_array($l->id, $matchedStatementIds) || $l->is_reconciled);

        $duplicates = $statementLines->filter(function ($l) {
            $details = json_decode((string) $l->transaction_details, true);

            return ($details['duplicate'] ?? false) === true;
        });

        $proposed = $r->matches()->where('status', 'proposed')->with('lines')->get();

        return [
            'unmatched_lines'   => $unmatched->values(),
            'duplicate_lines'   => $duplicates->values(),
            'proposed_matches'  => $proposed,
        ];
    }

    private function hasValidTerminalReference(BankReconciliation $reconciliation, BankStatementLine $line): bool
    {
        if (! $line->terminal_treatment) {
            return true;
        }

        return match ($line->terminal_treatment) {
            'matched' => $line->is_reconciled
                && bccomp(ltrim((string) ($line->amount_residual ?? $line->amount), '-'), '0.0050', 4) <= 0
                && $line->terminal_reference_type === 'match'
                && DB::table('recon_matches as recon_match')
                    ->join('recon_match_lines as match_line', 'match_line.match_id', '=', 'recon_match.id')
                    ->where('recon_match.id', $line->terminal_reference_id)
                    ->where('recon_match.reconciliation_id', $reconciliation->id)
                    ->where('recon_match.company_id', $reconciliation->company_id)
                    ->where('recon_match.status', 'confirmed')
                    ->whereNull('recon_match.reversed_at')
                    ->where('match_line.lineable_type', ReconMatchLine::TYPE_STATEMENT_LINE)
                    ->where('match_line.lineable_id', $line->id)
                    ->whereNull('match_line.reversed_at')
                    ->exists(),
            'adjustment' => $line->is_reconciled
                && bccomp(ltrim((string) ($line->amount_residual ?? $line->amount), '-'), '0.0050', 4) <= 0
                && $line->terminal_reference_type === 'adjustment'
                && DB::table('recon_adjustments')
                    ->where('id', $line->terminal_reference_id)
                    ->where('reconciliation_id', $reconciliation->id)
                    ->where('company_id', $reconciliation->company_id)
                    ->where('statement_line_id', $line->id)
                    ->whereNull('reversed_at')
                    ->whereNotNull('posted_at')
                    ->exists(),
            'bank_error' => $this->hasApprovedException($reconciliation, $line, 'bank_error', 'resolved'),
            'duplicate'  => $this->hasApprovedException($reconciliation, $line, 'duplicate', 'excluded'),
            default      => false,
        };
    }

    private function hasApprovedException(BankReconciliation $reconciliation, BankStatementLine $line, string $classification, string $status): bool
    {
        return $line->terminal_reference_type === 'reconciling_item'
            && DB::table('recon_reconciling_items')
                ->where('id', $line->terminal_reference_id)
                ->where('reconciliation_id', $reconciliation->id)
                ->where('company_id', $reconciliation->company_id)
                ->where('statement_line_id', $line->id)
                ->where('classification', $classification)
                ->where('status', $status)
                ->whereNotNull('resolved_at')
                ->exists();
    }
}

<?php

namespace App\Services\Recon;

use App\Models\Recon\BankReconciliation;
use App\Models\Recon\ReconciliationSnapshot;
use App\Models\Recon\StatementLine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Webkul\Account\Services\AccountingDateGuard;

class ReconciliationLifecycleService
{
    public function __construct(
        private readonly ReconciliationSafety $safety,
        private readonly ReconciliationMutationGuard $mutationGuard,
    ) {}

    public function startReview(BankReconciliation $reconciliation): BankReconciliation
    {
        return $this->transition($reconciliation, 'review', ReconciliationMutationGuard::EDITABLE_STATUSES, 'review_started', 'review', [
            'submitted_at' => now(), 'submitted_by' => Auth::id(),
        ]);
    }

    public function returnToPreparation(BankReconciliation $reconciliation): BankReconciliation
    {
        return $this->transition($reconciliation, 'in_progress', ['review'], 'review_returned', 'review');
    }

    public function complete(BankReconciliation $reconciliation): BankReconciliation
    {
        $this->safety->assertFinancialMutationsEnabled($reconciliation->company_id);

        return DB::transaction(function () use ($reconciliation): BankReconciliation {
            $reconciliation = BankReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);
            $this->mutationGuard->assert($reconciliation, 'complete', ['review'], 'Only a reconciliation in review can be completed.');
            app(AccountingDateGuard::class)->lockPeriodBoundary((int) $reconciliation->company_id, completing: true);
            if (config('reconciliation.require_different_reviewer', false) && $reconciliation->submitted_by === Auth::id()) {
                throw new InvalidArgumentException('The preparer cannot complete this reconciliation.');
            }

            $check = app(FinalizationService::class)->completionCheck($reconciliation);
            if (! $check['can_complete']) {
                throw new InvalidArgumentException(implode(' ', $check['issues']));
            }

            $calculation = $check['difference'];
            $payload = $this->snapshotPayload($reconciliation, $calculation);
            $snapshot = ReconciliationSnapshot::create([
                'company_id'        => $reconciliation->company_id,
                'reconciliation_id' => $reconciliation->id,
                'version'           => ((int) $reconciliation->snapshots()->max('version')) + 1,
                'payload'           => $payload,
                'payload_hash'      => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                'created_by'        => Auth::id(),
            ]);

            DB::table('accounts_bank_statements')->where('id', $reconciliation->statement_id)->update([
                'is_completed' => true, 'balance_end' => $reconciliation->balance_end_real,
            ]);
            $reconciliation->update([
                'status'           => 'completed',
                'gl_balance_start' => $calculation['gl_balance_start'],
                'gl_balance_end'   => $calculation['gl_balance_end'],
                'difference'       => $calculation['difference'],
                'reviewed_at'      => now(), 'reviewed_by' => Auth::id(),
                'completed_at'     => now(), 'completed_by' => Auth::id(),
            ]);
            BankReconciliation::logEvent($reconciliation, 'completed', ['snapshot_id' => $snapshot->id]);

            return $reconciliation->refresh();
        });
    }

    public function requestReopen(BankReconciliation $reconciliation, string $reason): BankReconciliation
    {
        $this->safety->assertFinancialMutationsEnabled($reconciliation->company_id);
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A reopen reason is required.');
        }

        return DB::transaction(function () use ($reconciliation, $reason): BankReconciliation {
            $reconciliation = BankReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);
            $this->mutationGuard->assert($reconciliation, 'reopen', ['completed'], 'Only a completed reconciliation can be submitted for reopening.');
            $impact = $this->dependentReconciliations($reconciliation, lock: true);

            $reconciliation->update([
                'status'                => 'reopen_requested',
                'reopen_requested_at'   => now(),
                'reopen_requested_by'   => Auth::id(),
                'reopen_request_reason' => $reason,
            ]);
            BankReconciliation::logEvent($reconciliation, 'reopen_requested', [
                'reason' => $reason,
                'impact' => $this->impactPayload($impact),
            ]);

            return $reconciliation->refresh();
        });
    }

    public function reopen(BankReconciliation $reconciliation, string $reason): BankReconciliation
    {
        $this->safety->assertFinancialMutationsEnabled($reconciliation->company_id);
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A reopen reason is required.');
        }

        return DB::transaction(function () use ($reconciliation, $reason): BankReconciliation {
            $reconciliation = BankReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);
            $this->mutationGuard->assert($reconciliation, 'reopen', ['reopen_requested'], 'A completed reconciliation must be submitted for reopening before it can be approved.');
            if (config('reconciliation.require_different_reviewer', false) && $reconciliation->reopen_requested_by === Auth::id()) {
                throw new InvalidArgumentException('The requester cannot approve reopening this reconciliation.');
            }

            $impact = $this->dependentReconciliations($reconciliation, lock: true);
            DB::table('accounts_bank_statements')->where('id', $reconciliation->statement_id)->update(['is_completed' => false]);
            $staleReason = 'Prior reconciliation reopened: '.$reconciliation->name;
            foreach ($impact as $dependent) {
                $previousStatus = $dependent->status;
                $dependent->update(['status' => 'stale', 'stale_at' => now(), 'stale_reason' => $staleReason]);
                BankReconciliation::logEvent($dependent, 'marked_stale', [
                    'cause_reconciliation_id'  => $reconciliation->id,
                    'cause_event'              => 'reopened',
                    'previous_status'          => $previousStatus,
                    'reason'                   => $staleReason,
                ]);
            }
            $reconciliation->update([
                'status' => 'reopened', 'reopened_at' => now(), 'reopened_by' => Auth::id(), 'reopen_reason' => $reason,
            ]);
            BankReconciliation::logEvent($reconciliation, 'reopened', [
                'reason'                => $reason,
                'request_reason'        => $reconciliation->reopen_request_reason,
                'requested_by'          => $reconciliation->reopen_requested_by,
                'approved_by'           => Auth::id(),
                'stale_reconciliations' => $this->impactPayload($impact),
            ]);

            return $reconciliation->refresh();
        });
    }

    public function reworkStale(BankReconciliation $reconciliation, string $reason): BankReconciliation
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A stale-rework reason is required.');
        }

        return DB::transaction(function () use ($reconciliation, $reason): BankReconciliation {
            $reconciliation = BankReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);
            $this->mutationGuard->assert(
                $reconciliation,
                'reopen',
                ['stale'],
                'Only a stale reconciliation can enter controlled rework.',
            );

            if ($reconciliation->statement_id) {
                DB::table('accounts_bank_statements')
                    ->where('id', $reconciliation->statement_id)
                    ->update(['is_completed' => false]);
            }

            $previousReason = $reconciliation->stale_reason;
            $reconciliation->update([
                'status'        => 'reopened',
                'reopened_at'   => now(),
                'reopened_by'   => Auth::id(),
                'reopen_reason' => $reason,
            ]);
            BankReconciliation::logEvent($reconciliation, 'stale_rework_started', [
                'reason'       => $reason,
                'stale_reason' => $previousReason,
                'actor_id'     => Auth::id(),
            ]);

            return $reconciliation->refresh();
        });
    }

    /**
     * Return the later reconciliation periods whose conclusions would no longer be reliable.
     * This is read-only and is used to show the approver the impact before reopening.
     *
     * @return array<int, array<string, mixed>>
     */
    public function previewReopenImpact(BankReconciliation $reconciliation): array
    {
        Gate::authorize('reopen', $reconciliation);

        return $this->impactPayload($this->dependentReconciliations($reconciliation));
    }

    /**
     * Invalidate reconciliation conclusions affected by a dated accounting correction.
     * The caller must make the accounting change and this propagation in the same transaction.
     *
     * @return array<int, array<string, mixed>>
     */
    public function markAffectedByHistoricalCorrection(
        BankReconciliation $context,
        mixed $effectiveDate,
        string $reason,
        string $sourceEvent,
    ): array {
        $this->mutationGuard->assert($context, lockedMessage: 'Historical corrections require an editable reconciliation.');
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A historical-correction reason is required.');
        }

        $affected = BankReconciliation::query()
            ->where('id', '<>', $context->id)
            ->where('company_id', $context->company_id)
            ->where('liquidity_account_id', $context->liquidity_account_id)
            ->whereDate('period_end', '>=', $effectiveDate)
            ->whereIn('status', ['review', 'completed', 'reopen_requested'])
            ->orderBy('period_end')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($affected as $item) {
            $previousStatus = $item->status;
            $item->update(['status' => 'stale', 'stale_at' => now(), 'stale_reason' => $reason]);
            BankReconciliation::logEvent($item, 'marked_stale', [
                'cause_reconciliation_id' => $context->id,
                'cause_event'             => $sourceEvent,
                'effective_date'          => (string) $effectiveDate,
                'previous_status'         => $previousStatus,
                'reason'                  => $reason,
            ]);
        }

        return $this->impactPayload($affected);
    }

    private function transition(BankReconciliation $reconciliation, string $to, array $from, string $event, string $ability, array $values = [], array $payload = []): BankReconciliation
    {
        $this->safety->assertFinancialMutationsEnabled($reconciliation->company_id);

        return DB::transaction(function () use ($reconciliation, $to, $from, $event, $ability, $values, $payload): BankReconciliation {
            $reconciliation = BankReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);
            $this->mutationGuard->assert($reconciliation, $ability, $from, "Cannot move reconciliation from {$reconciliation->status} to {$to}.");
            $reconciliation->update(['status' => $to] + $values);
            BankReconciliation::logEvent($reconciliation, $event, $payload);

            return $reconciliation->refresh();
        });
    }

    private function snapshotPayload(BankReconciliation $reconciliation, array $calculation): array
    {
        return [
            'reconciliation'  => $reconciliation->only(['id', 'company_id', 'journal_id', 'bank_account_id', 'liquidity_account_id', 'currency_id', 'name', 'statement_date', 'period_start', 'period_end', 'balance_start', 'balance_end_real']),
            'calculation'     => $calculation,
            'statement_lines' => StatementLine::query()
                ->where('statement_id', $reconciliation->statement_id)
                ->where('company_id', $reconciliation->company_id)
                ->orderBy('id')
                ->get(['id', 'transaction_date', 'partner_name', 'payment_reference', 'external_id', 'amount', 'amount_residual', 'is_reconciled'])
                ->toArray(),
            'matches'           => $reconciliation->matches()
                ->where('status', 'confirmed')
                ->with(['lines' => fn ($query) => $query->orderBy('id')])
                ->orderBy('id')
                ->get([
                    'id', 'status', 'match_type', 'amount', 'variance', 'notes',
                    'accounting_mode', 'accounting_partial_ids', 'confirmed_at', 'confirmed_by',
                ])
                ->map(fn ($match): array => [
                    ...$match->toArray(),
                    'lines' => $match->lines->map->only([
                        'id', 'lineable_type', 'lineable_id', 'side', 'amount_applied',
                        'applied_ledger_amount', 'previous_ledger_residual', 'previous_ledger_residual_currency',
                    ])->all(),
                ])->all(),
            'adjustments'       => $reconciliation->adjustments()->whereNull('reversed_at')->orderBy('id')->get(['id', 'type', 'amount', 'notes', 'move_id'])->toArray(),
            'reconciling_items' => $reconciliation->reconcilingItems()->orderBy('id')->get(['id', 'classification', 'side', 'amount', 'status', 'origin_period_end'])->toArray(),
        ];
    }

    /** @return Collection<int, BankReconciliation> */
    private function dependentReconciliations(BankReconciliation $reconciliation, bool $lock = false): Collection
    {
        $query = BankReconciliation::query()
            ->where('company_id', $reconciliation->company_id)
            ->where('bank_account_id', $reconciliation->bank_account_id)
            ->where('liquidity_account_id', $reconciliation->liquidity_account_id)
            ->where('period_end', '>', $reconciliation->period_end)
            ->whereIn('status', ['draft', 'in_progress', 'review', 'completed', 'reopen_requested', 'reopened'])
            ->orderBy('period_end')
            ->orderBy('id');

        return ($lock ? $query->lockForUpdate() : $query)->get();
    }

    /**
     * @param  Collection<int, BankReconciliation>  $reconciliations
     * @return array<int, array<string, mixed>>
     */
    private function impactPayload(Collection $reconciliations): array
    {
        return $reconciliations->map(fn (BankReconciliation $item): array => [
            'id'                      => $item->id,
            'name'                    => $item->name,
            'status'                  => $item->status,
            'period_start'            => $item->period_start?->toDateString(),
            'period_end'              => $item->period_end?->toDateString(),
            'latest_snapshot_version' => $item->snapshots()->max('version'),
            'latest_snapshot_hash'    => $item->snapshots()->latest('version')->value('payload_hash'),
        ])->all();
    }
}

<?php

namespace App\Services\Recon;

use App\Models\Recon\BankReconciliation;
use App\Models\Recon\ReconMatch;
use App\Models\Recon\ReconMatchLine;
use App\Models\Recon\StatementLine as BankStatementLine;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Services\Reconciler;

class MatchService
{
    public function __construct(
        private readonly ReconciliationSafety $safety,
        private readonly StatementLineTreatment $statementLineTreatment,
        private readonly ReconciliationMutationGuard $mutationGuard,
    ) {}

    public function createMatch(BankReconciliation $reconciliation, array $bankLineIds, array $ledgerLineIds, ?string $notes = null, array $allocations = [], ?int $ruleId = null, ?string $idempotencyKey = null): ReconMatch
    {
        $this->safety->assertFinancialMutationsEnabled($reconciliation->company_id);

        if ($idempotencyKey !== null) {
            $existing = $this->findIdempotentMatch($reconciliation, $idempotencyKey, $bankLineIds, $ledgerLineIds, $notes, $allocations, $ruleId, 'confirmed');

            if ($existing) {
                return $existing;
            }
        }

        try {
            $match = DB::transaction(function () use ($reconciliation, $bankLineIds, $ledgerLineIds, $notes, $allocations, $ruleId, $idempotencyKey): ReconMatch {
                $reconciliation = BankReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);
                $this->authorizeMutation($reconciliation);
                $this->lockSources($bankLineIds, $ledgerLineIds);
                $engine = app(MatchingEngine::class);
                $check = $engine->validateBasket($reconciliation, $bankLineIds, $ledgerLineIds, $allocations);
                if (! $check['balanced']) {
                    throw new InvalidArgumentException(sprintf(
                        'Match is not balanced: bank %s vs ledger %s (variance %s exceeds tolerance).',
                        $check['bank_total'], $check['ledger_total'], $check['variance'],
                    ));
                }

                $this->assertLedgerScopes($reconciliation, array_keys($check['allocations']['ledger']));

                $match = ReconMatch::create([
                    'company_id'              => $reconciliation->company_id,
                    'reconciliation_id'       => $reconciliation->id,
                    'status'                  => 'confirmed',
                    'match_type'              => 'manual',
                    'amount'                  => $check['bank_total'],
                    'variance'                => $check['variance'],
                    'notes'                   => $notes,
                    'creator_id'              => Auth::id(),
                    'rule_id'                 => $ruleId,
                    'confirmed_at'            => now(),
                    'confirmed_by'            => Auth::id(),
                    'idempotency_key'         => $idempotencyKey,
                    'idempotency_fingerprint' => $idempotencyKey === null ? null : $this->commandFingerprint('confirmed', $bankLineIds, $ledgerLineIds, $notes, $allocations, $ruleId),
                ]);
                $this->createLines($match, $check['allocations']);
                $this->applyAccountingEffects($reconciliation, $match, $check['allocations']);
                $this->finalizeMatchEffects($match);
                BankReconciliation::logEvent($reconciliation, 'match_confirmed', [
                    'match_id'        => $match->id,
                    'accounting_mode' => $match->refresh()->accounting_mode,
                    'partial_ids'     => $match->accounting_partial_ids ?? [],
                ]);

                return $match->refresh();
            });
        } catch (QueryException $e) {
            if ($idempotencyKey !== null && $this->isUniqueViolation($e)) {
                $existing = $this->findIdempotentMatch($reconciliation, $idempotencyKey, $bankLineIds, $ledgerLineIds, $notes, $allocations, $ruleId, 'confirmed');

                if ($existing) {
                    return $existing;
                }
            }

            throw $e;
        }

        return $match;
    }

    public function proposeMatch(BankReconciliation $reconciliation, array $bankLineIds, array $ledgerLineIds, ?string $notes = null, array $allocations = [], ?int $ruleId = null, ?string $idempotencyKey = null): ReconMatch
    {
        $this->safety->assertFinancialMutationsEnabled($reconciliation->company_id);

        if ($idempotencyKey !== null) {
            $existing = $this->findIdempotentMatch($reconciliation, $idempotencyKey, $bankLineIds, $ledgerLineIds, $notes, $allocations, $ruleId, 'proposed');

            if ($existing) {
                return $existing;
            }
        }

        try {
            return DB::transaction(function () use ($reconciliation, $bankLineIds, $ledgerLineIds, $notes, $allocations, $ruleId, $idempotencyKey): ReconMatch {
                $reconciliation = BankReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);
                $this->authorizeMutation($reconciliation);
                $this->lockSources($bankLineIds, $ledgerLineIds);
                $check = app(MatchingEngine::class)->validateBasket($reconciliation, $bankLineIds, $ledgerLineIds, $allocations);

                if (! $check['balanced']) {
                    throw new InvalidArgumentException('The proposed match is no longer balanced.');
                }

                $this->assertLedgerScopes($reconciliation, array_keys($check['allocations']['ledger']));

                $match = ReconMatch::create([
                    'company_id'              => $reconciliation->company_id,
                    'reconciliation_id'       => $reconciliation->id,
                    'status'                  => 'proposed',
                    'match_type'              => 'auto',
                    'amount'                  => $check['bank_total'],
                    'variance'                => $check['variance'],
                    'notes'                   => $notes,
                    'creator_id'              => Auth::id(),
                    'rule_id'                 => $ruleId,
                    'idempotency_key'         => $idempotencyKey,
                    'idempotency_fingerprint' => $idempotencyKey === null ? null : $this->commandFingerprint('proposed', $bankLineIds, $ledgerLineIds, $notes, $allocations, $ruleId),
                ]);
                $this->createLines($match, $check['allocations']);
                BankReconciliation::logEvent($reconciliation, 'match_proposed', ['match_id' => $match->id]);

                return $match->refresh();
            });
        } catch (QueryException $e) {
            if ($idempotencyKey !== null && $this->isUniqueViolation($e)) {
                $existing = $this->findIdempotentMatch($reconciliation, $idempotencyKey, $bankLineIds, $ledgerLineIds, $notes, $allocations, $ruleId, 'proposed');

                if ($existing) {
                    return $existing;
                }
            }

            throw $e;
        }
    }

    public function confirm(ReconMatch $match): ReconMatch
    {
        $this->safety->assertFinancialMutationsEnabled($match->company_id);

        $match = DB::transaction(function () use ($match): ReconMatch {
            $match = ReconMatch::query()->lockForUpdate()->findOrFail($match->id);
            $reconciliation = BankReconciliation::query()->lockForUpdate()->findOrFail($match->reconciliation_id);
            $this->authorizeMutation($reconciliation);
            $this->assertSameCompany($match, $reconciliation);
            if ($match->status === 'confirmed') {
                return $match;
            }
            if ($match->status !== 'proposed') {
                throw new InvalidArgumentException('Only proposed matches can be confirmed.');
            }

            $allocations = ['bank' => [], 'ledger' => []];
            foreach ($match->bankLines as $line) {
                $allocations['bank'][$line->lineable_id] = $this->absolute((string) $line->amount_applied);
            }
            foreach ($match->ledgerLines as $line) {
                $allocations['ledger'][$line->lineable_id] = $this->absolute((string) $line->amount_applied);
            }
            $this->lockSources(array_keys($allocations['bank']), array_keys($allocations['ledger']));
            $check = app(MatchingEngine::class)->validateBasket(
                $reconciliation,
                $match->bankLines->pluck('lineable_id')->all(),
                $match->ledgerLines->pluck('lineable_id')->all(),
                $allocations,
                $match->id,
            );
            if (! $check['balanced']) {
                throw new InvalidArgumentException('Cannot confirm: the allocated basket no longer balances or has insufficient remaining amounts.');
            }

            $this->assertLedgerScopes($reconciliation, array_keys($check['allocations']['ledger']));

            $match->update(['status' => 'confirmed', 'confirmed_at' => now(), 'confirmed_by' => Auth::id()]);
            $this->applyAccountingEffects($reconciliation, $match->refresh(), $check['allocations']);
            $this->finalizeMatchEffects($match->refresh());
            BankReconciliation::logEvent($reconciliation, 'match_confirmed', [
                'match_id'        => $match->id,
                'accounting_mode' => $match->refresh()->accounting_mode,
                'partial_ids'     => $match->accounting_partial_ids ?? [],
            ]);

            return $match->refresh();
        });

        return $match;
    }

    public function reject(ReconMatch $match): ReconMatch
    {
        $this->safety->assertFinancialMutationsEnabled($match->company_id);

        return DB::transaction(function () use ($match): ReconMatch {
            $match = ReconMatch::query()->lockForUpdate()->findOrFail($match->id);
            $reconciliation = BankReconciliation::query()->lockForUpdate()->findOrFail($match->reconciliation_id);
            $this->authorizeMutation($reconciliation);

            if ($match->status !== 'proposed') {
                throw new InvalidArgumentException('Only proposed matches can be rejected.');
            }

            $match->update(['status' => 'rejected']);
            BankReconciliation::logEvent($reconciliation, 'match_rejected', ['match_id' => $match->id]);

            return $match->refresh();
        });
    }

    public function unmatch(ReconMatch $match, ?string $reason = null): ReconMatch
    {
        $this->safety->assertFinancialMutationsEnabled($match->company_id);

        $match = DB::transaction(function () use ($match, $reason): ReconMatch {
            $match = ReconMatch::query()->lockForUpdate()->findOrFail($match->id);
            $reconciliation = BankReconciliation::query()->lockForUpdate()->findOrFail($match->reconciliation_id);
            $this->authorizeMutation($reconciliation);
            $this->assertSameCompany($match, $reconciliation);

            if ($match->status === 'reversed' || $match->reversed_at) {
                return $match;
            }

            if ($match->status !== 'confirmed') {
                throw new InvalidArgumentException('Only an active confirmed match can be unmatched.');
            }

            $reason = trim((string) $reason);
            if (mb_strlen($reason) < 3) {
                throw new InvalidArgumentException('A meaningful reason is required to unmatch a confirmed reconciliation.');
            }

            $bankIds = $match->bankLines()->whereNull('reversed_at')->pluck('lineable_id')->all();
            $ledgerIds = $match->ledgerLines()->whereNull('reversed_at')->pluck('lineable_id')->all();
            $this->lockSources($bankIds, $ledgerIds);
            $this->assertBankScopes($reconciliation, $bankIds);
            $this->reverseAccountingEffects($reconciliation, $match->refresh());

            $match->lines()->whereNull('reversed_at')->update(['reversed_at' => now(), 'reversed_by' => Auth::id()]);
            $match->update([
                'status'                 => 'reversed',
                'reversed_at'            => now(),
                'reversed_by'            => Auth::id(),
                'accounting_reversed_at' => now(),
                'accounting_reversed_by' => Auth::id(),
                'reversal_reason'        => $reason,
                'notes'                  => trim(($match->notes ?? '').' [unmatched: '.$reason.']'),
            ]);
            foreach (array_unique($bankIds) as $statementLineId) {
                $this->recalculateBankLine((int) $statementLineId);
            }
            BankReconciliation::logEvent($reconciliation, 'unmatched', [
                'match_id'        => $match->id,
                'accounting_mode' => $match->accounting_mode,
                'partial_ids'     => $match->accounting_partial_ids ?? [],
                'reason'          => $reason,
            ]);

            return $match->refresh();
        });

        return $match;
    }

    /**
     * U13: Accounting-owned side effects for a confirmed match.
     *
     * Allocations remain the audit truth. This records provenance (snapshots,
     * applied amounts, actor/timestamps, created partial/full IDs) and invokes
     * the accounting Reconciler ONLY where appropriate:
     *
     * - allocation_only (bank clearing): no partials/full reconciliations are
     *   created, so
     *   timing differences never post journals (R7/R23) and AR/AP residuals
     *   are untouched (R16/R17). For a sole-consumer 1:1 full consumption we
     *   stamp accounting-owned statement linkage + RB matching number so the
     *   line disappears from both the recon workspace and the accounting open
     *   items; partials/splits keep remaining visible with no linkage.
     *
     * No method here writes MoveLine residuals, partials, or payment state
     * directly; statement linkage is delegated to the accounting service.
     */
    private function applyAccountingEffects(BankReconciliation $reconciliation, ReconMatch $match, array $allocations): void
    {
        $reconciler = app(Reconciler::class);
        $ledgerIds = array_map('intval', array_keys($allocations['ledger']));
        $bankIds = array_map('intval', array_keys($allocations['bank']));

        $ledgerModels = MoveLine::query()->withoutGlobalScopes()
            ->whereKey($ledgerIds)->orderBy('id')->lockForUpdate()->get();
        $ledgerById = $ledgerModels->keyBy('id');

        $ledgerSnapshots = [];
        $bankSnapshots = [];

        foreach ($ledgerIds as $ledgerId) {
            $line = $ledgerById->get($ledgerId);

            if (! $line) {
                throw new InvalidArgumentException("Ledger line {$ledgerId} is outside this reconciliation.");
            }

            $ledgerSnapshots[$ledgerId] = [
                'amount_residual'          => (string) ($line->amount_residual ?? '0.0000'),
                'amount_residual_currency' => (string) ($line->amount_residual_currency ?? '0.0000'),
                'reconciled'               => (bool) $line->reconciled,
                'matching_number'          => $line->matching_number,
                'statement_line_id'        => $line->statement_line_id,
                'statement_id'             => $line->statement_id,
                'parent_state'             => (string) ($line->parent_state?->value ?? $line->parent_state),
                'move_id'                  => $line->move_id,
                'account_id'               => $line->account_id,
                'currency_id'              => $line->currency_id,
                'company_id'               => $line->company_id,
            ];
        }

        foreach ($bankIds as $bankId) {
            $bank = BankStatementLine::query()->lockForUpdate()->findOrFail($bankId);
            $bankSnapshots[$bankId] = ['amount' => (string) $bank->amount];
        }

        $mode = 'allocation_only';
        $partialIds = [];
        $fullIds = [];
        $linkedLedgerIds = [];

        // Bank clearing is allocation-only. Never create accounting partials/full
        // reconciliations from a statement match; legacy ledger_group records are
        // handled by the guarded reverse path below, not created here.
        $linkCandidate = $this->soleConsumerFullLink($reconciliation, $match, $allocations, $ledgerById);

        if ($linkCandidate !== null) {
            $reconciler->linkBankStatementLines(
                $ledgerModels->whereIn('id', $linkCandidate['ledger_ids'])->values(),
                $linkCandidate['statement_line_id'],
                (int) $reconciliation->statement_id,
                MoveLine::bankMatchNumber($match->id),
            );
            $linkedLedgerIds = $linkCandidate['ledger_ids'];
        }

        $appliedLedgerTotal = '0.0000';
        foreach ($allocations['ledger'] as $amount) {
            $appliedLedgerTotal = bcadd($appliedLedgerTotal, $this->absolute((string) $amount), 4);
        }

        $match->update([
            'accounting_mode'             => $mode,
            'accounting_partial_ids'      => $partialIds,
            'accounting_full_ids'         => $fullIds,
            'ledger_previous_residuals'   => ['ledger' => $ledgerSnapshots, 'bank' => $bankSnapshots, 'linked_ledger_ids' => $linkedLedgerIds],
            'accounting_applied_amount'   => $appliedLedgerTotal,
            'accounting_applied_at'       => now(),
            'accounting_applied_by'       => Auth::id(),
            'accounting_reversed_at'      => null,
            'accounting_reversed_by'      => null,
        ]);

        foreach ($match->ledgerLines()->whereNull('reversed_at')->get() as $matchLine) {
            $ledgerId = (int) $matchLine->lineable_id;
            $snapshot = $ledgerSnapshots[$ledgerId] ?? null;

            $matchLine->update([
                'previous_ledger_residual'          => $snapshot['amount_residual'] ?? null,
                'previous_ledger_residual_currency' => $snapshot['amount_residual_currency'] ?? null,
                'applied_ledger_amount'             => isset($allocations['ledger'][$ledgerId])
                    ? $this->absolute((string) $allocations['ledger'][$ledgerId])
                    : $this->absolute((string) $matchLine->amount_applied),
            ]);
        }
    }

    /**
     * U13: Reverse only the reconciliation-owned accounting effect.
     *
     * Verifies ownership and detects external modification before touching
     * anything: missing/foreign partials, extra unrelated partials, changed
     * residuals, reversed moves, or foreign linkage all throw a safe conflict
     * requiring a controlled correction workflow.
     */
    private function reverseAccountingEffects(BankReconciliation $reconciliation, ReconMatch $match): void
    {
        $reconciler = app(Reconciler::class);
        $provenance = $match->ledger_previous_residuals ?? [];
        $ledgerSnapshots = $provenance['ledger'] ?? (is_array($provenance) ? $provenance : []);
        $bankSnapshots = $provenance['bank'] ?? [];
        $linkedLedgerIds = $provenance['linked_ledger_ids'] ?? [];
        $mode = $match->accounting_mode ?? 'allocation_only';

        if ($mode === 'ledger_group') {
            throw new InvalidArgumentException('This legacy match changed accounting reconciliation state and cannot be reversed through ordinary bank unmatch. Use the controlled accounting correction workflow.');
        }

        $expectedStatementLineId = $linkedLedgerIds !== []
            ? (int) $match->bankLines()->whereNull('reversed_at')->value('lineable_id')
            : null;
        $expectedStatementId = $linkedLedgerIds !== [] ? (int) $reconciliation->statement_id : null;

        $ledgerIds = $match->ledgerLines()->whereNull('reversed_at')->pluck('lineable_id')->map(fn ($id): int => (int) $id)->all();
        $ledgerModels = MoveLine::query()->withoutGlobalScopes()
            ->whereKey($ledgerIds)->orderBy('id')->lockForUpdate()->get();
        $ledgerById = $ledgerModels->keyBy('id');

        foreach ($ledgerIds as $ledgerId) {
            $line = $ledgerById->get($ledgerId);

            if (! $line) {
                throw new InvalidArgumentException("Ledger line {$ledgerId} no longer exists; use a controlled correction workflow.");
            }

            if ((int) $line->company_id !== (int) $reconciliation->company_id) {
                throw new InvalidArgumentException("Ledger line {$ledgerId} belongs to another company.");
            }

            $moveState = $line->move?->state?->value ?? null;
            $parentState = (string) ($line->parent_state?->value ?? $line->parent_state ?? '');

            if ($moveState !== null && $moveState !== 'posted') {
                throw new InvalidArgumentException("The source move for ledger line {$ledgerId} is no longer posted; use a controlled correction workflow.");
            }

            if ($moveState === null && $parentState !== '' && strtolower($parentState) !== 'posted') {
                throw new InvalidArgumentException("Ledger line {$ledgerId} is no longer posted; use a controlled correction workflow.");
            }

            $snapshot = $ledgerSnapshots[$ledgerId] ?? $ledgerSnapshots[(string) $ledgerId] ?? null;

            if (is_array($snapshot)) {
                $this->assertLedgerUnchanged(
                    $ledgerId,
                    $line,
                    $snapshot,
                    in_array($ledgerId, $linkedLedgerIds, true) ? MoveLine::bankMatchNumber($match->id) : null,
                    $expectedStatementLineId,
                    $expectedStatementId,
                );
            }
        }

        foreach ($match->bankLines()->whereNull('reversed_at')->get() as $bankMatchLine) {
            $bankId = (int) $bankMatchLine->lineable_id;
            $snapshot = $bankSnapshots[$bankId] ?? $bankSnapshots[(string) $bankId] ?? null;

            if (is_array($snapshot) && isset($snapshot['amount'])) {
                $current = BankStatementLine::query()->lockForUpdate()->findOrFail($bankId);

                if (bccomp((string) $current->amount, (string) $snapshot['amount'], 4) !== 0) {
                    throw new InvalidArgumentException("Bank line {$bankId} was modified outside this match; use a controlled correction workflow.");
                }
            }
        }

        if ($linkedLedgerIds !== []) {
            $previous = [];

            foreach ($linkedLedgerIds as $ledgerId) {
                $snapshot = $ledgerSnapshots[$ledgerId] ?? $ledgerSnapshots[(string) $ledgerId] ?? null;

                if (! is_array($snapshot)) {
                    throw new InvalidArgumentException("No provenance snapshot for ledger line {$ledgerId}; cannot safely unmatch.");
                }

                $previous[$ledgerId] = [
                    'statement_line_id' => $snapshot['statement_line_id'],
                    'statement_id'      => $snapshot['statement_id'],
                    'matching_number'   => $snapshot['matching_number'],
                ];
            }

            $reconciler->unlinkBankStatementLines(
                $ledgerModels->whereIn('id', $linkedLedgerIds)->values(),
                $previous,
                MoveLine::bankMatchNumber($match->id),
            );
        }

        $match->ledgerLines()->whereNull('reversed_at')->update([
            'accounting_reversed_at' => now(),
            'accounting_reversed_by' => Auth::id(),
        ]);
    }

    /**
     * U13: Sole-consumer 1:1 full link candidate.
     *
     * Returns null unless exactly one bank line and one ledger line are both
     * fully consumed and no other active allocation touches the ledger line.
     * This keeps split/partial reuse safe: shared ledger lines never take a
     * single-FK linkage that would hide their remaining amount.
     *
     * @return array{ledger_ids: array<int>, statement_line_id: int}|null
     */
    private function soleConsumerFullLink(BankReconciliation $reconciliation, ReconMatch $match, array $allocations, $ledgerById): ?array
    {
        if (count($allocations['bank']) !== 1 || count($allocations['ledger']) !== 1) {
            return null;
        }

        $bankId = (int) array_key_first($allocations['bank']);
        $ledgerId = (int) array_key_first($allocations['ledger']);
        $ledger = $ledgerById->get($ledgerId);

        if (! $ledger || $reconciliation->statement_id === null) {
            return null;
        }

        if ($ledger->reconciled || $ledger->statement_line_id !== null || $ledger->statement_id !== null || $ledger->matching_number !== null) {
            return null;
        }

        $otherActive = DB::table('recon_match_lines as allocation')
            ->join('recon_matches as owner', 'owner.id', '=', 'allocation.match_id')
            ->where('allocation.lineable_type', ReconMatchLine::TYPE_MOVE_LINE)
            ->where('allocation.lineable_id', $ledgerId)
            ->where('owner.company_id', $reconciliation->company_id)
            ->whereIn('owner.status', ['proposed', 'confirmed'])
            ->whereNull('allocation.reversed_at')
            ->whereNull('owner.reversed_at')
            ->where('allocation.match_id', '<>', $match->id)
            ->exists();

        if ($otherActive) {
            return null;
        }

        $preRemaining = app(MatchingEngine::class)->ledgerRemainingFor($reconciliation, $ledgerId, $match->id);
        $applied = $this->absolute((string) $allocations['ledger'][$ledgerId]);

        if (bccomp($applied, $preRemaining, 4) !== 0) {
            return null;
        }

        $bank = BankStatementLine::query()->find($bankId);

        if (! $bank) {
            return null;
        }

        $bankRemaining = $this->absolute((string) ($bank->amount_residual ?? $bank->amount));

        if (bccomp($this->absolute((string) $allocations['bank'][$bankId]), $bankRemaining, 4) !== 0) {
            return null;
        }

        return ['ledger_ids' => [$ledgerId], 'statement_line_id' => $bankId];
    }

    private function assertLedgerUnchanged(
        int $ledgerId,
        MoveLine $line,
        array $snapshot,
        ?string $expectedMatchNumber,
        ?int $expectedStatementLineId,
        ?int $expectedStatementId,
    ): void {
        if ($expectedMatchNumber !== null) {
            if ($line->matching_number !== $expectedMatchNumber
                || (int) $line->statement_line_id !== $expectedStatementLineId
                || (int) $line->statement_id !== $expectedStatementId) {
                throw new InvalidArgumentException("Ledger line {$ledgerId} was modified outside this match; use a controlled correction workflow.");
            }

            if (bccomp((string) ($line->amount_residual ?? '0'), (string) ($snapshot['amount_residual'] ?? '0'), 4) !== 0) {
                throw new InvalidArgumentException("Ledger line {$ledgerId} residual changed outside this match; use a controlled correction workflow.");
            }

            if ((bool) $line->reconciled !== (bool) ($snapshot['reconciled'] ?? false)) {
                throw new InvalidArgumentException("Ledger line {$ledgerId} reconciliation state changed outside this match.");
            }

            return;
        }

        if (bccomp((string) ($line->amount_residual ?? '0'), (string) ($snapshot['amount_residual'] ?? '0'), 4) !== 0) {
            throw new InvalidArgumentException("Ledger line {$ledgerId} residual changed outside this match; use a controlled correction workflow.");
        }

        if ((bool) $line->reconciled !== (bool) ($snapshot['reconciled'] ?? false)) {
            throw new InvalidArgumentException("Ledger line {$ledgerId} reconciliation state changed outside this match.");
        }

        if ($line->matching_number !== $snapshot['matching_number']
            || $line->statement_line_id !== $snapshot['statement_line_id']
            || $line->statement_id !== $snapshot['statement_id']) {
            throw new InvalidArgumentException("Ledger line {$ledgerId} linkage changed outside this match.");
        }
    }

    private function assertLedgerScopes(BankReconciliation $reconciliation, array $ledgerIds): void
    {
        $cutoff = ($reconciliation->statement_date ?? $reconciliation->period_end)?->toDateString();

        foreach (MoveLine::query()->withoutGlobalScopes()->whereKey(array_map('intval', $ledgerIds))->get() as $line) {
            if ((int) $line->company_id !== (int) $reconciliation->company_id) {
                throw new InvalidArgumentException("Ledger line {$line->id} belongs to another company.");
            }

            if ((int) $line->account_id !== (int) $reconciliation->liquidity_account_id) {
                throw new InvalidArgumentException("Ledger line {$line->id} is not on this reconciliation liquidity account.");
            }

            if ((int) $line->currency_id !== (int) $reconciliation->currency_id) {
                throw new InvalidArgumentException("Ledger line {$line->id} uses a different currency.");
            }

            if (($line->move?->state?->value ?? (string) $line->parent_state) !== 'posted' && strtolower((string) ($line->parent_state?->value ?? $line->parent_state)) !== 'posted') {
                throw new InvalidArgumentException("Ledger line {$line->id} is not posted.");
            }

            if ($cutoff && (($line->date?->toDateString() ?? '') > $cutoff || ($line->move?->date?->toDateString() ?? '') > $cutoff)) {
                throw new InvalidArgumentException("Ledger line {$line->id} is dated after the statement cutoff.");
            }
        }
    }

    private function assertBankScopes(BankReconciliation $reconciliation, array $bankIds): void
    {
        foreach (BankStatementLine::query()->whereKey(array_map('intval', $bankIds))->get() as $line) {
            if ((int) $line->company_id !== (int) $reconciliation->company_id
                || (int) $line->statement_id !== (int) $reconciliation->statement_id
                || (int) $line->journal_id !== (int) $reconciliation->journal_id) {
                throw new InvalidArgumentException("Bank line {$line->id} is outside this reconciliation company, journal, or statement.");
            }
        }
    }

    private function assertSameCompany(ReconMatch $match, BankReconciliation $reconciliation): void
    {
        if ((int) $match->company_id !== (int) $reconciliation->company_id) {
            throw new InvalidArgumentException('This match belongs to another company.');
        }
    }

    private function findIdempotentMatch(BankReconciliation $reconciliation, string $key, array $bankLineIds, array $ledgerLineIds, ?string $notes, array $allocations, ?int $ruleId, string $status): ?ReconMatch
    {
        $match = ReconMatch::query()
            ->where('reconciliation_id', $reconciliation->id)
            ->where('company_id', $reconciliation->company_id)
            ->where('idempotency_key', $key)
            ->whereNull('reversed_at')
            ->whereNotIn('status', ['rejected'])
            ->first();

        if (! $match) {
            return null;
        }

        $matchBanks = $match->bankLines()->pluck('lineable_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        $matchLedgers = $match->ledgerLines()->pluck('lineable_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        $wantedBanks = collect($bankLineIds)->map(fn ($id): int => (int) $id)->sort()->values()->all();
        $wantedLedgers = collect($ledgerLineIds)->map(fn ($id): int => (int) $id)->sort()->values()->all();

        if ($matchBanks !== $wantedBanks || $matchLedgers !== $wantedLedgers) {
            throw new InvalidArgumentException('This idempotency key was already used for a different match basket.');
        }

        $fingerprint = $this->commandFingerprint($status, $bankLineIds, $ledgerLineIds, $notes, $allocations, $ruleId);
        if ($match->idempotency_fingerprint !== null && ! hash_equals($match->idempotency_fingerprint, $fingerprint)) {
            throw new InvalidArgumentException('This idempotency key was already used with a different financial instruction.');
        }

        if ($match->idempotency_fingerprint === null
            && ($match->status !== $status || (int) ($match->rule_id ?? 0) !== (int) ($ruleId ?? 0) || (string) ($match->notes ?? '') !== (string) ($notes ?? ''))) {
            throw new InvalidArgumentException('This idempotency key was already used with different match intent, rule, or notes.');
        }

        if ($match->idempotency_fingerprint === null && $allocations !== []) {
            foreach (['bank', 'ledger'] as $side) {
                foreach ($allocations[$side] ?? [] as $lineId => $amount) {
                    $actual = $match->{$side.'Lines'}()->where('lineable_id', (int) $lineId)->value('amount_applied');
                    if ($actual === null || bccomp($this->absolute((string) $actual), $this->absolute((string) $amount), 4) !== 0) {
                        throw new InvalidArgumentException('This idempotency key was already used with different allocations.');
                    }
                }
            }
        }

        return $match;
    }

    private function commandFingerprint(string $operation, array $bankLineIds, array $ledgerLineIds, ?string $notes, array $allocations, ?int $ruleId): string
    {
        $normalizeAmounts = function (array $amounts): array {
            $normalized = [];
            foreach ($amounts as $lineId => $amount) {
                $normalized[(int) $lineId] = bcadd((string) $amount, '0', 4);
            }
            ksort($normalized, SORT_NUMERIC);

            return $normalized;
        };

        $payload = [
            'operation'          => $operation,
            'bank_ids'           => collect($bankLineIds)->map(fn ($id): int => (int) $id)->sort()->values()->all(),
            'ledger_ids'         => collect($ledgerLineIds)->map(fn ($id): int => (int) $id)->sort()->values()->all(),
            'notes'              => (string) ($notes ?? ''),
            'rule_id'            => $ruleId,
            'allocation_mode'    => $allocations === [] ? 'default' : 'explicit',
            'bank_allocations'   => $normalizeAmounts($allocations['bank'] ?? []),
            'ledger_allocations' => $normalizeAmounts($allocations['ledger'] ?? []),
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'recon_match_idempotency_unique')
            || str_contains($message, 'duplicate key')
            || str_contains($message, 'unique constraint')
            || (string) $e->getCode() === '23505'
            || (string) $e->getCode() === '23000';
    }

    /** Closed legacy boundary. Accounting effects are only applied by create/confirm. */
    public function applyMatchEffects(ReconMatch $match): void
    {
        $this->safety->assertFinancialMutationsEnabled($match->company_id);
        throw new InvalidArgumentException('Direct match-effect application is closed; use createMatch or confirm.');
    }

    private function finalizeMatchEffects(ReconMatch $match): void
    {
        $bankLines = $match->bankLines()->whereNull('reversed_at')->lockForUpdate()->get();
        $ledgerLines = $match->ledgerLines()->whereNull('reversed_at')->get();

        foreach ($bankLines as $matchLine) {
            $line = BankStatementLine::query()->lockForUpdate()->findOrFail($matchLine->lineable_id);
            $current = (string) ($line->amount_residual ?? $line->amount);
            $applied = (string) $matchLine->amount_applied;
            if ($line->terminal_treatment || str_starts_with($current, '-') !== str_starts_with($applied, '-') || bccomp($this->absolute($applied), $this->absolute($current), 4) > 0) {
                throw new InvalidArgumentException('Match allocation exceeds the bank line remaining amount or the line is already treated.');
            }
            $remaining = bcsub($current, $applied, 4);
            $reconciliation = $match->reconciliation;
            $isFullyMatched = app(MatchingEngine::class)->isZero($reconciliation, $remaining);
            $moveId = $ledgerLines->count() === 1 && $bankLines->count() === 1 ? $ledgerLines->first()->resolve()?->move_id : null;

            if ($isFullyMatched) {
                $this->statementLineTreatment->apply($match->reconciliation, $line, 'matched', 'match', $match->id, settleResidual: true, moveId: $moveId);
            } else {
                $line->update(['is_reconciled' => false, 'amount_residual' => $remaining]);
            }
        }
    }

    private function createLines(ReconMatch $match, array $allocations): void
    {
        foreach ($allocations['bank'] as $lineId => $amount) {
            ReconMatchLine::create([
                'match_id'       => $match->id,
                'lineable_type'  => ReconMatchLine::TYPE_STATEMENT_LINE,
                'lineable_id'    => $lineId,
                'side'           => ReconMatchLine::SIDE_BANK,
                'amount_applied' => $amount,
            ]);
        }
        foreach ($allocations['ledger'] as $lineId => $amount) {
            ReconMatchLine::create([
                'match_id'       => $match->id,
                'lineable_type'  => ReconMatchLine::TYPE_MOVE_LINE,
                'lineable_id'    => $lineId,
                'side'           => ReconMatchLine::SIDE_LEDGER,
                'amount_applied' => $amount,
            ]);
        }
    }

    private function authorizeMutation(BankReconciliation $reconciliation): void
    {
        $this->mutationGuard->assert($reconciliation, lockedMessage: 'Reconciliation is locked. Reopen it before matching lines.');
    }

    private function lockSources(array $bankLineIds, array $ledgerLineIds): void
    {
        BankStatementLine::query()->whereKey(array_unique(array_map('intval', $bankLineIds)))->orderBy('id')->lockForUpdate()->get();
        MoveLine::query()->withoutGlobalScopes()->whereKey(array_unique(array_map('intval', $ledgerLineIds)))->orderBy('id')->lockForUpdate()->get();
    }

    private function recalculateBankLine(int $statementLineId): void
    {
        $line = BankStatementLine::query()->lockForUpdate()->findOrFail($statementLineId);
        $activeMatches = DB::table('recon_match_lines as match_line')
            ->join('recon_matches as recon_match', 'recon_match.id', '=', 'match_line.match_id')
            ->where('match_line.lineable_type', ReconMatchLine::TYPE_STATEMENT_LINE)
            ->where('match_line.lineable_id', $line->id)
            ->whereNull('match_line.reversed_at')
            ->where('recon_match.status', 'confirmed')
            ->whereNull('recon_match.reversed_at')
            ->orderByDesc('recon_match.id')
            ->get(['recon_match.id', 'match_line.amount_applied']);
        $allocated = $activeMatches->reduce(
            fn (string $total, object $allocation): string => bcadd($total, $this->absolute((string) $allocation->amount_applied), 4),
            '0.0000',
        );
        $remainingMagnitude = bcsub($this->absolute((string) $line->amount), $allocated, 4);
        if (bccomp($remainingMagnitude, '0.0000', 4) < 0) {
            throw new InvalidArgumentException('Active match allocations exceed the statement line amount.');
        }
        $remaining = str_starts_with((string) $line->amount, '-') ? '-'.$remainingMagnitude : $remainingMagnitude;
        $precision = max(0, min(4, (int) ($line->currency?->decimal_places ?? 2)));
        $halfUnit = $precision === 4 ? '0.0001' : '0.'.str_repeat('0', $precision).'5';
        $isFullyMatched = $activeMatches->isNotEmpty() && bccomp($remainingMagnitude, $halfUnit, 4) < 0;

        $line->update([
            'terminal_treatment'        => $isFullyMatched ? 'matched' : null,
            'terminal_reference_type'   => $isFullyMatched ? 'match' : null,
            'terminal_reference_id'     => $isFullyMatched ? $activeMatches->first()->id : null,
            'terminal_treatment_reason' => null,
            'terminal_treatment_at'     => $isFullyMatched ? now() : null,
            'terminal_treatment_by'     => $isFullyMatched ? Auth::id() : null,
            'amount_residual'           => $isFullyMatched ? '0.0000' : $remaining,
            'is_reconciled'             => $isFullyMatched,
            'move_id'                   => null,
        ]);
    }

    private function absolute(string $amount): string
    {
        return str_starts_with($amount, '-') ? substr($amount, 1) : $amount;
    }
}

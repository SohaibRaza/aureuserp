<?php

namespace Webkul\Account\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\FullReconcile;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Models\PartialReconcile;
use Webkul\Account\Models\Payment;

class Reconciler
{
    public function reconcile(Collection $lines): void
    {
        $this->reconcileGroups([$lines]);
    }

    public function reconcileReversals($moves, $reverseMoves)
    {
        foreach ($moves->zip($reverseMoves) as [$move, $reverseMove]) {
            $grouped = $move->lines->merge($reverseMove->lines)
                ->reject(fn (MoveLine $line) => $line->reconciled)
                ->groupBy(fn (MoveLine $line) => $line->account_id.'|'.($line->currency_id ?? 'null'));

            foreach ($grouped as $key => $lines) {
                [$accountId] = explode('|', $key);

                $account = Account::find($accountId);

                if ($this->allowsReconciliation($account)) {
                    $this->reconcile($lines);
                }
            }
        }

        return $reverseMoves;
    }

    public function unReconcile(PartialReconcile $partial): void
    {
        if (! $partial->exists || $partial->isDirty($partial->getKeyName()) || $partial->getConnection() !== (new Move)->getConnection()) {
            throw ValidationException::withMessages(['lines' => __('accounts::system.reconciliation.unavailable')]);
        }
        $partial->getConnection()->transaction(function () use ($partial): void {
            $stored = PartialReconcile::find($partial->getKey());
            if (! $stored) {
                return;
            }

            $lines = $this->lockReconciliationLines([$stored->debit_move_id, $stored->credit_move_id]);
            $stored = PartialReconcile::lockForUpdate()->find($partial->getKey());
            if (! $stored) {
                return;
            }
            $full = $stored->fullReconcile;
            $exchangeMove = $stored->exchangeMove ?? $full?->exchangeMove;
            // The lock context includes siblings; only native endpoints and cleared full links change.
            $affectedIds = collect($this->matchedLineIds([$stored->debit_move_id, $stored->credit_move_id]));
            if ($full) {
                $affectedIds = $affectedIds->merge($lines->where('full_reconcile_id', $full->id)->pluck('id'));
            }
            $affected = $lines->whereIn('id', $affectedIds->unique()->all());
            $this->assertReconciliationLinesMutable($affected);
            $moves = $affected->pluck('move')->unique('id');

            $stored->delete();
            $full?->delete();

            if ($exchangeMove) {
                app(MoveWorkflow::class)->reverse(collect([$exchangeMove]), [[
                    'date'      => $exchangeMove->date,
                    'reference' => __('Reversal of: :name', ['name' => $exchangeMove->name]),
                ]], true);
            }

            foreach ($affected as $line) {
                $line->refresh();
                $line->computeAmountResidual();
                $line->save();
            }
            foreach ($moves as $move) {
                app(MoveCalculator::class)->recomputeTotals($move->refresh())->save();
            }
            $this->refreshMatchingNumbers($affected->pluck('id')->all());
            $this->refreshPaymentStatuses($moves->pluck('id')->all());
        });
    }

    /**
     * Lock only the accounting component, with move roots before their mutable lines.
     * The caller owns the transaction. Newly discovered earlier roots require a retry
     * so roots are never acquired out of order.
     *
     * @param  array<int, int>  $lineIds
     * @param  array<int, int>  $moveIds
     * @return Collection<int, MoveLine>
     */
    public function lockReconciliationLines(array $lineIds = [], array $moveIds = []): Collection
    {
        $connection = (new Move)->getConnection();
        if ($connection->transactionLevel() === 0) {
            throw new \LogicException('Accounting reconciliation locks require a transaction.');
        }
        $context = $this->reconciliationContext($lineIds, $moveIds);
        $moves = Move::whereKey($context['move_ids'])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($moves->count() !== count($context['move_ids'])) {
            throw ValidationException::withMessages(['lines' => __('accounts::system.reconciliation.unavailable')]);
        }
        $fresh = $this->reconciliationContext($lineIds, $moveIds);
        $additionalIds = array_values(array_diff($fresh['move_ids'], $context['move_ids']));
        if ($additionalIds !== []) {
            if (min($additionalIds) < max($context['move_ids'])) {
                throw ValidationException::withMessages(['lines' => __('accounts::system.reconciliation.changed')]);
            }
            // Payment/exchange entries created while waiting follow the held roots.
            $additional = Move::whereKey($additionalIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($additional->count() !== count($additionalIds)) {
                throw ValidationException::withMessages(['lines' => __('accounts::system.reconciliation.unavailable')]);
            }
            $moves = $moves->union($additional);
            $current = $this->reconciliationContext($lineIds, $moveIds);
            if (array_diff($current['move_ids'], $moves->keys()->all()) !== []) {
                throw ValidationException::withMessages(['lines' => __('accounts::system.reconciliation.changed')]);
            }
            $fresh = $current;
        }
        $lines = MoveLine::whereKey($fresh['line_ids'])->orderBy('id')->lockForUpdate()->get();
        if ($lines->count() !== count($fresh['line_ids'])) {
            throw ValidationException::withMessages(['lines' => __('accounts::system.reconciliation.unavailable')]);
        }
        PartialReconcile::whereKey($fresh['partial_ids'])->orderBy('id')->lockForUpdate()->get();
        FullReconcile::whereKey($fresh['full_ids'])->orderBy('id')->lockForUpdate()->get();
        $lines->load(['account', 'partner', 'currency', 'company.currency', 'companyCurrency', 'matchedDebits', 'matchedCredits']);
        foreach ($lines as $line) {
            $line->setRelation('move', $moves->get($line->move_id));
        }

        return $lines;
    }

    /** @return array{move_ids: array<int, int>, line_ids: array<int, int>, partial_ids: array<int, int>, full_ids: array<int, int>} */
    protected function reconciliationContext(array $lineIds, array $moveIds): array
    {
        $selectedIds = array_values(array_unique($lineIds));
        $selected = MoveLine::whereKey($selectedIds)->get(['id', 'move_id']);
        if ($selected->count() !== count($selectedIds)) {
            throw ValidationException::withMessages(['lines' => __('accounts::system.reconciliation.unavailable')]);
        }
        $moveIds = collect($moveIds)->merge($selected->pluck('move_id'))->unique()->sort()->values()->all();
        $previous = [];
        do {
            $previous = $moveIds;
            $lines = MoveLine::whereIn('move_id', $moveIds)->get(['id', 'move_id', 'full_reconcile_id']);
            $partials = PartialReconcile::where(function ($query) use ($lines): void {
                $query->whereIn('debit_move_id', $lines->pluck('id'))->orWhereIn('credit_move_id', $lines->pluck('id'));
            })->get();
            $fullIds = $lines->pluck('full_reconcile_id')->merge($partials->pluck('full_reconcile_id'))->filter()->unique()->all();
            $fulls = FullReconcile::whereKey($fullIds)->get();
            $relatedIds = $partials->pluck('debit_move_id')->merge($partials->pluck('credit_move_id'))->unique();
            $relatedLines = MoveLine::whereKey($relatedIds)->get(['id', 'move_id']);
            if ($relatedLines->count() !== $relatedIds->count()) {
                throw ValidationException::withMessages(['lines' => __('accounts::system.reconciliation.unavailable')]);
            }
            $moveIds = collect($moveIds)->merge($relatedLines->pluck('move_id'))
                ->merge(MoveLine::whereIn('full_reconcile_id', $fullIds)->pluck('move_id'))
                ->merge($partials->pluck('exchange_move_id'))->merge($fulls->pluck('exchange_move_id'))
                ->filter()->unique()->sort()->values()->all();
        } while ($moveIds !== $previous);

        return ['move_ids' => $moveIds, 'line_ids' => $lines->pluck('id')->sort()->values()->all(),
            'partial_ids'  => $partials->pluck('id')->sort()->values()->all(), 'full_ids' => collect($fullIds)->sort()->values()->all()];
    }

    public function assertNoBankLinkage(Collection $lines): void
    {
        $linked = $lines->contains(fn (MoveLine $line): bool => $line->statement_id !== null || $line->statement_line_id !== null
            || str_starts_with((string) $line->matching_number, 'RB'));
        if (! $linked && $lines->isNotEmpty() && Schema::hasTable('recon_match_lines') && Schema::hasTable('recon_matches')) {
            // Partial, split and proposed bank allocations do not always carry an RB stamp.
            $linked = DB::table('recon_match_lines as allocation')
                ->join('recon_matches as match', 'match.id', '=', 'allocation.match_id')
                ->join('accounts_account_move_lines as ledger', 'ledger.id', '=', 'allocation.lineable_id')
                ->whereIn('ledger.id', $lines->pluck('id'))
                ->whereColumn('allocation.company_id', 'ledger.company_id')
                ->whereColumn('match.company_id', 'ledger.company_id')
                ->where('allocation.lineable_type', 'move_line')
                ->where('allocation.side', 'ledger')
                ->whereIn('match.status', ['proposed', 'confirmed'])
                ->whereNull('allocation.reversed_at')
                ->whereNull('match.reversed_at')
                ->exists();
        }
        if ($linked) {
            throw ValidationException::withMessages(['lines' => __('accounts::system.reconciliation.bank-match')]);
        }
    }

    protected function assertReconciliationLinesMutable(Collection $lines): void
    {
        $this->assertNoBankLinkage($lines);
        foreach ($lines->groupBy('move_id') as $moveLines) {
            app(AccountingDateGuard::class)->assertReconciliationPeriodOpen(
                $moveLines->first()->move,
                affectedLineIds: $moveLines->pluck('id')->all(),
            );
        }
    }

    protected function refreshPaymentStatuses(array $moveIds): void
    {
        Payment::whereIn('move_id', $moveIds)->orderBy('id')->get()->each(function (Payment $payment): void {
            $payment->computeState();
            $payment->computeReconciliationStatus();
            // Reconciliation refreshes status, not the historical payment inputs.
            $payment->newQuery()->whereKey($payment->id)->update($payment->only(['state', 'is_matched', 'is_reconciled']));
        });
    }

    /**
     * U13: Accounting-owned bank linkage for fully consumed 1:1 matches.
     *
     * Sets statement linkage and a recon matching-number stamp without touching
     * residuals, partials, or payment state. Only the accounting service may
     * write these MoveLine fields; MatchService must delegate here.
     */
    public function linkBankStatementLines(Collection $ledgerLines, int $statementLineId, int $statementId, string $matchNumber): void
    {
        foreach ($ledgerLines as $line) {
            $fresh = MoveLine::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($line->id);

            if ($fresh->reconciled) {
                throw new InvalidArgumentException("Ledger line {$fresh->id} is already reconciled in accounting.");
            }

            if ($fresh->statement_line_id !== null || $fresh->statement_id !== null || $fresh->matching_number !== null) {
                throw new InvalidArgumentException("Ledger line {$fresh->id} already has accounting statement linkage or matching provenance.");
            }

            $fresh->statement_line_id = $statementLineId;
            $fresh->statement_id = $statementId;
            $fresh->matching_number = $matchNumber;
            $fresh->save();
        }
    }

    /**
     * U13: Restore pre-match statement linkage exactly.
     *
     * Only clears linkage stamped by the given match number; any other value
     * indicates external modification and throws a safe conflict.
     *
     * @param  array<int, array{statement_line_id: mixed, statement_id: mixed, matching_number: mixed}>  $previous
     */
    public function unlinkBankStatementLines(Collection $ledgerLines, array $previous, string $expectedMatchNumber): void
    {
        foreach ($ledgerLines as $line) {
            $fresh = MoveLine::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($line->id);
            $snapshot = $previous[$fresh->id] ?? null;

            if (! is_array($snapshot)) {
                throw new InvalidArgumentException("No provenance snapshot for ledger line {$fresh->id}; cannot safely unmatch.");
            }

            if ($fresh->matching_number !== $expectedMatchNumber) {
                throw new InvalidArgumentException(
                    "Ledger line {$fresh->id} was modified outside this match; use a controlled correction workflow."
                );
            }

            $fresh->statement_line_id = $snapshot['statement_line_id'];
            $fresh->statement_id = $snapshot['statement_id'];
            $fresh->matching_number = $snapshot['matching_number'];
            $fresh->save();
        }
    }

    public function reconcileGroups(array $plans): array
    {
        $connection = (new Move)->getConnection();
        foreach ($plans as $lines) {
            if ($lines->contains(fn (MoveLine $line): bool => ! $line->exists || $line->isDirty($line->getKeyName()) || $line->getConnection() !== $connection)) {
                throw ValidationException::withMessages(['lines' => __('accounts::system.reconciliation.unavailable')]);
            }
        }

        return $connection->transaction(function () use ($plans): array {
            $lineIds = collect($plans)->flatMap(fn (Collection $lines) => $lines->pluck('id'))->unique()->values()->all();
            $locked = $this->lockReconciliationLines($lineIds)->keyBy('id');
            $affected = $locked->whereIn('id', $this->matchedLineIds($lineIds));
            $this->assertReconciliationLinesMutable($affected);
            $partials = [];
            $fulls = [];
            foreach ($plans as $lines) {
                $planLineIds = $lines->pluck('id')->unique();
                $freshById = MoveLine::whereKey($planLineIds->all())
                    ->with(['account', 'partner', 'currency', 'move', 'company.currency', 'companyCurrency', 'matchedDebits', 'matchedCredits'])
                    ->get()
                    ->keyBy('id');
                $fresh = $planLineIds->map(fn ($id) => $freshById->get($id));
                $this->assertReconcilable($fresh);
                foreach ($fresh as $line) {
                    $line->computeAmountResidual();
                }
                if ($fresh->contains(fn (MoveLine $line): bool => $this->isSettled($line))) {
                    throw ValidationException::withMessages(['lines' => __('accounts::system.reconciliation.already-reconciled')]);
                }
                foreach ($fresh as $line) {
                    if ($line->isDirty(['amount_residual', 'amount_residual_currency', 'reconciled'])) {
                        $line->save();
                    }
                }
                $results = $this->reconcileNode($this->buildPlan($fresh));
                $partials = array_merge($partials, $results['partial_reconciles']);
                $fulls = array_merge($fulls, $results['full_reconciles']);
            }
            $this->refreshPaymentStatuses($affected->pluck('move_id')->unique()->all());

            return ['partial_reconciles' => $partials, 'full_reconciles' => $fulls];
        });
    }

    protected function buildPlan(Collection $lines): array
    {
        $sorted = $lines->sortBy(fn (MoveLine $line) => [
            $line->date_maturity ?? $line->date,
            $line->currency_id,
            $line->amount_currency,
            $line->balance,
        ]);

        $plan = [
            'lines'    => $sorted,
            'line_ids' => $sorted->pluck('id')->toArray(),
        ];

        $currencies = $sorted->pluck('currency_id')->unique();

        if ($currencies->count() > 1) {
            $plan['nodes'] = $currencies
                ->map(function ($currencyId) use ($sorted) {
                    $subset = $sorted->filter(fn (MoveLine $line) => $line->currency_id == $currencyId);

                    return ['lines' => $subset, 'line_ids' => $subset->pluck('id')->toArray()];
                })
                ->values()
                ->all();
        }

        return $plan;
    }

    protected function reconcileNode(array $plan): array
    {
        $partials = [];

        $fulls = [];

        $settledIds = [];

        foreach ($plan['nodes'] ?? [] as $childPlan) {
            $childResults = $this->reconcileNode($childPlan);

            $partials = array_merge($partials, $childResults['partial_reconciles']);

            $fulls = array_merge($fulls, $childResults['full_reconciles']);

            $settledIds = array_merge($settledIds, $childResults['fully_reconciled_ids']);
        }

        $remaining = ($plan['lines'] ?? collect())->reject(fn (MoveLine $line) => in_array($line->id, $settledIds));

        if ($remaining->isEmpty()) {
            return [
                'partial_reconciles'   => $partials,
                'full_reconciles'      => $fulls,
                'fully_reconciled_ids' => $settledIds,
            ];
        }

        $openAmounts = $remaining->map(fn (MoveLine $line) => [
            'line'                     => $line,
            'amount_residual'          => $line->amount_residual,
            'amount_residual_currency' => $line->amount_residual_currency,
        ])->values()->all();

        $matches = $this->matchDebitsToCredits($openAmounts);

        $touchedLineIds = [];

        foreach ($matches['partials_values_list'] as $match) {
            $partials[] = $this->applyPartial($match, $remaining->first()->company_id, $touchedLineIds);
        }

        if ($touchedLineIds !== []) {
            $this->refreshMatchingNumbers($touchedLineIds);
        }

        $fulls = array_merge($fulls, $this->finalizeFullyMatched($remaining));

        return [
            'partial_reconciles'   => $partials,
            'full_reconciles'      => $fulls,
            'fully_reconciled_ids' => array_merge($settledIds, $matches['fully_reconciled_line_ids']),
        ];
    }

    protected function applyPartial(array $match, $companyId, array &$touchedLineIds): PartialReconcile
    {
        $values = $match['partial_values'];

        $partial = PartialReconcile::create([
            'debit_move_id'          => $values['debit_move_id'],
            'credit_move_id'         => $values['credit_move_id'],
            'amount'                 => $values['amount'],
            'debit_amount_currency'  => $values['debit_amount_currency'],
            'credit_amount_currency' => $values['credit_amount_currency'],
            'company_id'             => $companyId,
        ]);

        $debitLine = MoveLine::find($values['debit_move_id']);

        $creditLine = MoveLine::find($values['credit_move_id']);

        $debitLine->amount_residual -= $values['amount'];
        $debitLine->amount_residual_currency -= $values['debit_amount_currency'];

        if ($debitLine->companyCurrency->isZero($debitLine->amount_residual)) {
            $debitLine->reconciled = true;
        }

        $debitLine->save();

        $creditLine->amount_residual += $values['amount'];
        $creditLine->amount_residual_currency += $values['credit_amount_currency'];

        if ($creditLine->companyCurrency->isZero($creditLine->amount_residual)) {
            $creditLine->reconciled = true;
        }

        $creditLine->save();

        $touchedLineIds[] = $debitLine->id;
        $touchedLineIds[] = $creditLine->id;

        $calculator = app(MoveCalculator::class);

        $calculator->recomputeTotals($debitLine->move->refresh())->save();

        $calculator->recomputeTotals($creditLine->move->refresh())->save();

        if (! empty($match['exchange_values'])) {
            $exchangeMoves = app(ExchangeDifferenceRecorder::class)->postDifferences([$match['exchange_values']]);

            if ($exchangeMoves !== []) {
                $partial->update(['exchange_move_id' => $exchangeMoves[0]->id]);
            }
        }

        return $partial;
    }

    protected function matchDebitsToCredits(array $openAmounts): array
    {
        $debits = array_values(array_filter(
            $openAmounts,
            fn ($values) => $values['line']->balance > 0.0 || $values['line']->amount_currency > 0.0
        ));

        $credits = array_values(array_filter(
            $openAmounts,
            fn ($values) => $values['line']->balance < 0.0 || $values['line']->amount_currency < 0.0
        ));

        $debitIndex = $creditIndex = 0;

        $debitValues = $creditValues = null;

        $settledLineIds = [];

        $matches = [];

        while (true) {
            if (! $debitValues) {
                if ($debitIndex >= count($debits)) {
                    break;
                }

                $debitValues = $debits[$debitIndex++];
            }

            if (! $creditValues) {
                if ($creditIndex >= count($credits)) {
                    break;
                }

                $creditValues = $credits[$creditIndex++];
            }

            $result = $this->matchSinglePair($debitValues, $creditValues);

            if (! empty($result['partial_values'])) {
                $matches[] = $result;
            }

            if ($result['debit_values'] === null) {
                $settledLineIds[] = $debitValues['line']->id;

                $debitValues = null;
            } else {
                $debitValues = $result['debit_values'];
            }

            if ($result['credit_values'] === null) {
                $settledLineIds[] = $creditValues['line']->id;

                $creditValues = null;
            } else {
                $creditValues = $result['credit_values'];
            }
        }

        return [
            'partials_values_list'      => $matches,
            'fully_reconciled_line_ids' => $settledLineIds,
        ];
    }

    protected function matchSinglePair(array $debitValues, array $creditValues): array
    {
        $debitLine = $debitValues['line'];

        $creditLine = $creditValues['line'];

        $debitCurrency = $debitLine->currency;

        $creditCurrency = $creditLine->currency;

        $companyCurrency = $debitLine->company->currency;

        $debitResiduals = $this->residualsPerCurrency($debitValues, $creditCurrency);

        $creditResiduals = $this->residualsPerCurrency($creditValues, $debitCurrency);

        $matchCurrency = match (true) {
            $debitCurrency->id != $companyCurrency->id
                && isset($debitResiduals[$debitCurrency->id], $creditResiduals[$debitCurrency->id]) => $debitCurrency,
            $creditCurrency->id != $companyCurrency->id
                && isset($debitResiduals[$creditCurrency->id], $creditResiduals[$creditCurrency->id]) => $creditCurrency,
            default                                                                                   => $companyCurrency,
        };

        $debitMatch = $debitResiduals[$matchCurrency->id] ?? null;

        $creditMatch = $creditResiduals[$matchCurrency->id] ?? null;

        $result = [
            'debit_values'  => $debitMatch ? $debitValues : null,
            'credit_values' => $creditMatch ? $creditValues : null,
        ];

        if (! $debitMatch || ! $creditMatch) {
            return $result;
        }

        $availableDebit = $debitMatch['residual'];

        $availableCredit = -$creditMatch['residual'];

        $matchable = min($availableDebit, $availableCredit);

        $debitFullyMatched = $availableDebit <= $availableCredit;

        $creditFullyMatched = $availableDebit >= $availableCredit;

        if ($matchCurrency->id == $companyCurrency->id) {
            $amount = $matchable;

            $debitRate = $debitResiduals[$debitCurrency->id]['rate'] ?? null;

            $creditRate = $creditResiduals[$creditCurrency->id]['rate'] ?? null;

            $debitAmountCurrency = $debitRate
                ? min($debitCurrency->round($debitRate * $matchable), $debitValues['amount_residual_currency'])
                : 0.0;

            $creditAmountCurrency = $creditRate
                ? min($creditCurrency->round($creditRate * $matchable), -$creditValues['amount_residual_currency'])
                : 0.0;
        } else {
            $amount = $matchable;

            if ($debitRate = $debitMatch['rate'] ?? null) {
                $amount = min($companyCurrency->round($matchable / $debitRate), $debitValues['amount_residual']);
            }

            if ($creditRate = $creditMatch['rate'] ?? null) {
                $amount = min($companyCurrency->round($matchable / $creditRate), -$creditValues['amount_residual']);
            }

            $debitAmountCurrency = $creditAmountCurrency = $matchable;
        }

        $debitValues['amount_residual'] -= $amount;
        $debitValues['amount_residual_currency'] -= $debitAmountCurrency;
        $creditValues['amount_residual'] += $amount;
        $creditValues['amount_residual_currency'] += $creditAmountCurrency;

        $result['partial_values'] = [
            'amount'                 => $amount,
            'debit_amount_currency'  => $debitAmountCurrency,
            'credit_amount_currency' => $creditAmountCurrency,
            'debit_move_id'          => $debitLine->id,
            'credit_move_id'         => $creditLine->id,
        ];

        $result['debit_values'] = $debitFullyMatched ? null : $debitValues;

        $result['credit_values'] = $creditFullyMatched ? null : $creditValues;

        $exchange = app(ExchangeDifferenceRecorder::class)
            ->differenceFor($debitValues, $creditValues, $debitFullyMatched, $creditFullyMatched);

        if ($exchange) {
            $result['exchange_values'] = $exchange;
        }

        return $result;
    }

    protected function residualsPerCurrency(array $lineValues, $counterpartCurrency): array
    {
        $line = $lineValues['line'];

        $remaining = $lineValues['amount_residual'];

        $remainingCurrency = $lineValues['amount_residual_currency'];

        $companyCurrency = $line->company->currency;

        $residuals = [];

        if (! $companyCurrency->isZero($remaining)) {
            $residuals[$companyCurrency->id] = ['residual' => $remaining, 'rate' => 1.0];
        }

        if ($line->currency->id != $companyCurrency->id && ! $line->currency->isZero($remainingCurrency)) {
            $residuals[$line->currency->id] = [
                'residual' => $remainingCurrency,
                'rate'     => abs($remainingCurrency / $remaining),
            ];
        }

        $needsCounterpart = $counterpartCurrency->id != $companyCurrency->id
            && $line->currency->id == $companyCurrency->id
            && ! $companyCurrency->isZero($remaining);

        if ($needsCounterpart) {
            $rate = $this->conversionRate($counterpartCurrency, $line);

            $inCounterpart = $counterpartCurrency->round($remaining * $rate);

            if (! $counterpartCurrency->isZero($inCounterpart)) {
                $residuals[$counterpartCurrency->id] = ['residual' => $inCounterpart, 'rate' => $rate];
            }
        }

        return $residuals;
    }

    protected function conversionRate($currency, MoveLine $line): float
    {
        $usesForeignCurrency = $line->currency_id != $line->company->currency->id
            && ! $line->company->currency->isZero($line->balance)
            && ! $line->currency->isZero($line->amount_currency);

        return $usesForeignCurrency
            ? abs($line->amount_currency / $line->balance)
            : ($currency->rate ?? 1.0);
    }

    protected function finalizeFullyMatched(Collection $lines): array
    {
        $lines = MoveLine::whereKey($this->matchedLineIds($lines->pluck('id')->all()))->get();

        $lines->load(['matchedDebits', 'matchedCredits', 'company.currency', 'currency']);

        $fullReconciles = [];

        $groups = $lines->groupBy(fn (MoveLine $line) => $line->matching_number ?? 'auto_'.$line->id);

        foreach ($groups as $groupLines) {
            if (! $groupLines->every(fn (MoveLine $line) => $this->isSettled($line))) {
                continue;
            }

            $invoicesFullySettled = $groupLines->pluck('move')
                ->filter(fn ($move) => $move->isInvoice(true))
                ->unique()
                ->every(fn ($move) => $move->paymentTermLines->every(fn (MoveLine $line) => $this->isSettled($line)));

            $exchangeMoveId = $this->exchangeMoveForUnsettled($groupLines);

            $partialIds = $groupLines
                ->flatMap(fn (MoveLine $line) => $line->matchedCredits->pluck('id')->merge($line->matchedDebits->pluck('id')))
                ->unique()
                ->all();

            if (! $invoicesFullySettled) {
                $groupLines->each(function (MoveLine $line) {
                    $line->reconciled = true;

                    $line->save();
                });

                continue;
            }

            $fullReconcile = FullReconcile::create(['exchange_move_id' => $exchangeMoveId]);

            PartialReconcile::whereIn('id', $partialIds)->update(['full_reconcile_id' => $fullReconcile->id]);

            $fullReconciles[] = $fullReconcile;

            $this->stampFullReconcile($partialIds, $fullReconcile);
        }

        return $fullReconciles;
    }

    protected function isSettled(MoveLine $line): bool
    {
        return $line->company->currency->isZero($line->amount_residual)
            && $line->currency->isZero($line->amount_residual_currency);
    }

    protected function exchangeMoveForUnsettled(Collection $groupLines): ?int
    {
        $unsettled = $groupLines
            ->reject(fn (MoveLine $line) => $this->isSettled($line))
            ->map(fn (MoveLine $line) => [
                'line'            => $line,
                'amount_residual' => ! $line->company->currency->isZero($line->amount_residual)
                    ? $line->amount_residual
                    : $line->amount_residual_currency,
            ])
            ->all();

        if ($unsettled === []) {
            return null;
        }

        $difference = app(ExchangeDifferenceRecorder::class)->buildExchangeMove($unsettled);

        if (! $difference) {
            return null;
        }

        return app(ExchangeDifferenceRecorder::class)->postDifferences([$difference])[0]->id ?? null;
    }

    protected function stampFullReconcile(array $partialIds, FullReconcile $fullReconcile): void
    {
        $lineIds = PartialReconcile::whereIn('id', $partialIds)
            ->get()
            ->flatMap(fn (PartialReconcile $partial) => [$partial->debit_move_id, $partial->credit_move_id])
            ->unique()
            ->values();

        foreach (MoveLine::whereIn('id', $lineIds)->get() as $line) {
            $line->reconciled = true;
            $line->full_reconcile_id = $fullReconcile->id;
            $line->matching_number = (string) $fullReconcile->id;

            $line->save();
        }
    }

    public function refreshMatchingNumbers(array $lineIds): void
    {
        if ($lineIds === []) {
            return;
        }

        $lines = MoveLine::whereKey($this->matchedLineIds($lineIds))->get();

        $partials = $lines
            ->flatMap(fn (MoveLine $line) => $line->matchedDebits->merge($line->matchedCredits))
            ->unique('id')
            ->sortBy('id')
            ->values();

        $groupToLines = [];

        $lineToGroup = [];

        foreach ($partials as $partial) {
            $debitGroup = $lineToGroup[$partial->debit_move_id] ?? null;

            $creditGroup = $lineToGroup[$partial->credit_move_id] ?? null;

            if ($debitGroup && $creditGroup) {
                if ($debitGroup !== $creditGroup) {
                    $this->mergeGroups($groupToLines, $lineToGroup, $debitGroup, $creditGroup);
                }
            } elseif ($debitGroup) {
                $groupToLines[$debitGroup][] = $partial->credit_move_id;
                $lineToGroup[$partial->credit_move_id] = $debitGroup;
            } elseif ($creditGroup) {
                $groupToLines[$creditGroup][] = $partial->debit_move_id;
                $lineToGroup[$partial->debit_move_id] = $creditGroup;
            } else {
                $groupToLines[$partial->id] = [$partial->debit_move_id, $partial->credit_move_id];
                $lineToGroup[$partial->debit_move_id] = $partial->id;
                $lineToGroup[$partial->credit_move_id] = $partial->id;
            }
        }

        foreach ($lines as $line) {
            if ($line->statement_id !== null || $line->statement_line_id !== null || str_starts_with((string) $line->matching_number, 'RB')) {
                continue;
            }
            $line->matching_number = match (true) {
                ! isset($lineToGroup[$line->id]) => null,
                (bool) $line->full_reconcile_id  => (string) $line->full_reconcile_id,
                default                          => 'P'.$lineToGroup[$line->id],
            };

            $line->save();
        }
    }

    /** @param array<int, int> $lineIds @return array<int, int> */
    protected function matchedLineIds(array $lineIds): array
    {
        do {
            $previous = $lineIds;
            $partials = PartialReconcile::where(function ($query) use ($lineIds): void {
                $query->whereIn('debit_move_id', $lineIds)->orWhereIn('credit_move_id', $lineIds);
            })->get(['debit_move_id', 'credit_move_id']);
            $lineIds = collect($lineIds)->merge($partials->pluck('debit_move_id'))->merge($partials->pluck('credit_move_id'))
                ->unique()->sort()->values()->all();
        } while ($lineIds !== $previous);

        return $lineIds;
    }

    protected function mergeGroups(array &$groupToLines, array &$lineToGroup, $debitGroup, $creditGroup): void
    {
        $keep = min($debitGroup, $creditGroup);

        $drop = max($debitGroup, $creditGroup);

        foreach ($groupToLines[$drop] as $lineId) {
            $lineToGroup[$lineId] = $keep;
        }

        $groupToLines[$keep] = array_merge($groupToLines[$keep], $groupToLines[$drop]);

        unset($groupToLines[$drop]);
    }

    public function assertReconcilable(Collection $lines): void
    {
        if ($lines->isEmpty()) {
            return;
        }

        if ($lines->contains(fn (MoveLine $line) => $line->reconciled)) {
            throw ValidationException::withMessages(['lines' => __('accounts::system.reconciliation.already-reconciled')]);
        }

        $this->assertNoBankLinkage($lines);

        if ($lines->contains(fn (MoveLine $line) => $line->parent_state != MoveState::POSTED || $line->move->state !== MoveState::POSTED)) {
            throw ValidationException::withMessages(['lines' => __('You can only reconcile posted entries.')]);
        }

        $accountIds = $lines->pluck('account_id')->unique();

        if ($accountIds->count() > 1) {
            $accounts = $lines->map->account->filter()->unique('id');

            throw ValidationException::withMessages(['lines' => __(
                'Entries are not from the same account: :accounts',
                ['accounts' => $accounts->pluck('display_name')->implode(', ')]
            )]);
        }

        $account = $lines->first()->account;

        if ($lines->pluck('partner_id')->unique()->count() > 1) {
            throw ValidationException::withMessages(['lines' => __('All lines must have the same partner.')]);
        }

        $companyIds = $lines->pluck('company_id')->unique();

        if ($companyIds->count() > 1) {
            $companies = $lines->map->company->filter()->unique('id');

            throw ValidationException::withMessages(['lines' => __(
                "Entries don't belong to the same company: :companies",
                ['companies' => $companies->pluck('display_name')->implode(', ')]
            )]);
        }

        if (! $this->allowsReconciliation($account)) {
            throw ValidationException::withMessages(['lines' => __(
                'Account :account does not allow reconciliation. First change the configuration of this account to allow it.',
                ['account' => $account->display_name]
            )]);
        }
    }

    protected function allowsReconciliation($account): bool
    {
        return $account->reconcile
            || in_array($account->account_type, [AccountType::ASSET_CASH, AccountType::LIABILITY_CREDIT_CARD]);
    }
}

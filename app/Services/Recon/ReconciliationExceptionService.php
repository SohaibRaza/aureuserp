<?php

namespace App\Services\Recon;

use App\Enums\Recon\ReconcilingItemClassification;
use App\Enums\Recon\ReconcilingItemSide;
use App\Enums\Recon\ReconcilingItemStatus;
use App\Models\Recon\BankReconciliation;
use App\Models\Recon\ReconAdjustment;
use App\Models\Recon\ReconcilingItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Webkul\Account\Models\MoveLine;

class ReconciliationExceptionService
{
    public function __construct(
        private readonly ReconciliationSafety $safety,
        private readonly StatementLineTreatment $statementLineTreatment,
        private readonly ReconciliationMutationGuard $mutationGuard,
    ) {}

    public function classify(
        BankReconciliation $reconciliation,
        ReconcilingItemClassification $classification,
        ReconcilingItemSide $side,
        float|int|string $amount,
        ?int $statementLineId = null,
        ?int $moveLineId = null,
        ?int $ownerId = null,
        array $evidence = [],
        ?string $notes = null,
    ): ReconcilingItem {
        $this->mutationGuard->assert($reconciliation, lockedMessage: 'Exceptions require an editable reconciliation.');
        if (! is_numeric($amount)) {
            throw new InvalidArgumentException('Exception amount must be numeric.');
        }
        $amount = bcadd((string) $amount, '0', 4);
        if (bccomp($amount, '0', 4) === 0) {
            throw new InvalidArgumentException('Exception amount cannot be zero.');
        }
        if (in_array($classification, [ReconcilingItemClassification::BankError, ReconcilingItemClassification::Duplicate], true)) {
            if (! $statementLineId || $side !== ReconcilingItemSide::Bank) {
                throw new InvalidArgumentException('Bank errors and duplicates require a bank statement line.');
            }
        }

        return DB::transaction(function () use ($reconciliation, $classification, $side, $amount, $statementLineId, $moveLineId, $ownerId, $evidence, $notes): ReconcilingItem {
            $reconciliation = BankReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);
            $this->mutationGuard->assert($reconciliation, lockedMessage: 'Exceptions require an editable reconciliation.');
            if ($statementLineId) {
                $statementLine = $this->statementLineTreatment->lock($reconciliation, $statementLineId);

                if ($statementLine->terminal_treatment) {
                    throw new InvalidArgumentException('This statement line already has a terminal treatment.');
                }
                if (ReconcilingItem::query()
                    ->where('reconciliation_id', $reconciliation->id)
                    ->where('statement_line_id', $statementLineId)
                    ->whereIn('status', [ReconcilingItemStatus::Open, ReconcilingItemStatus::CarriedForward])
                    ->exists()
                ) {
                    throw new InvalidArgumentException('This statement line already has an unresolved exception.');
                }

                $statementAmount = bcadd((string) ($statementLine->amount_residual ?? $statementLine->amount), '0', 4);
                if ($classification === ReconcilingItemClassification::BankError
                    && bccomp($amount, bcmul($statementAmount, '-1', 4), 4) !== 0) {
                    throw new InvalidArgumentException('A bank-error correction must be the opposite of the erroneous statement line remaining amount.');
                }
                if ($classification === ReconcilingItemClassification::Duplicate
                    && bccomp($amount, $statementAmount, 4) !== 0) {
                    throw new InvalidArgumentException('A duplicate amount must equal the statement line remaining amount.');
                }

                $evidence = [
                    ...$evidence,
                    'source_statement_amount' => $statementAmount,
                ];
            }
            $moveLine = $moveLineId ? MoveLine::query()->withoutGlobalScopes()
                ->from('accounts_account_move_lines as line')
                ->join('accounts_account_moves as move', 'move.id', '=', 'line.move_id')
                ->where('line.id', $moveLineId)
                ->where('line.company_id', $reconciliation->company_id)
                ->where('line.account_id', $reconciliation->liquidity_account_id)
                ->where('line.currency_id', $reconciliation->currency_id)
                ->where('move.company_id', $reconciliation->company_id)
                ->where('move.state', 'posted')
                ->lockForUpdate()
                ->first([
                    'line.id', 'line.currency_id', 'line.company_currency_id', 'line.balance',
                    'line.amount_currency', 'line.amount_residual', 'line.amount_residual_currency',
                ]) : null;
            if ($moveLineId && ! $moveLine) {
                throw new InvalidArgumentException('The move line is not a posted liquidity line in this reconciliation.');
            }

            if (in_array($classification, [ReconcilingItemClassification::DepositInTransit, ReconcilingItemClassification::OutstandingPayment], true)) {
                if (! $moveLine || $statementLineId || $side !== ReconcilingItemSide::Bank) {
                    throw new InvalidArgumentException('Timing differences require a bank-side posted liquidity move line and no statement line.');
                }

                $sourceAmount = (int) $moveLine->currency_id !== (int) $moveLine->company_currency_id
                    ? ($moveLine->amount_residual_currency ?? $moveLine->amount_currency)
                    : ($moveLine->amount_residual ?? $moveLine->balance);
                $sourceAmount = bcadd((string) $sourceAmount, '0', 4);
                $expectedPositive = $classification === ReconcilingItemClassification::DepositInTransit;
                if (bccomp($sourceAmount, '0', 4) === 0
                    || ($expectedPositive && bccomp($sourceAmount, '0', 4) < 0)
                    || (! $expectedPositive && bccomp($sourceAmount, '0', 4) > 0)) {
                    throw new InvalidArgumentException('The timing classification does not match the signed remaining liquidity amount.');
                }

                $expectedAmount = ltrim($sourceAmount, '-');
                if (bccomp(ltrim($amount, '-'), $expectedAmount, 4) !== 0) {
                    throw new InvalidArgumentException('The timing amount must equal the posted liquidity line remaining amount.');
                }
                $amount = $expectedAmount;
                $evidence = [...$evidence, 'source_liquidity_residual' => $sourceAmount];
            }

            $item = ReconcilingItem::create([
                'company_id'                    => $reconciliation->company_id,
                'reconciliation_id'             => $reconciliation->id,
                'originating_reconciliation_id' => $reconciliation->id,
                'statement_line_id'             => $statementLineId,
                'move_line_id'                  => $moveLineId,
                'side'                          => $side,
                'classification'                => $classification,
                'amount'                        => $amount,
                'origin_period_end'             => $reconciliation->period_end,
                'status'                        => ReconcilingItemStatus::Open,
                'resolved_at'                   => null,
                'resolved_by'                   => null,
                'owner_id'                      => $ownerId,
                'evidence'                      => $evidence,
                'notes'                         => $notes,
            ]);
            BankReconciliation::logEvent($reconciliation, 'exception_classified', [
                'item_id' => $item->id, 'classification' => $classification->value, 'actor_id' => Auth::id(),
            ]);

            return $item;
        });
    }

    public function approveBankError(ReconcilingItem $item, string $reason): ReconcilingItem
    {
        return $this->terminalize($item, ReconcilingItemClassification::BankError, ReconcilingItemStatus::Resolved, 'bank_error', $reason);
    }

    public function excludeDuplicate(ReconcilingItem $item, string $reason): ReconcilingItem
    {
        return $this->terminalize($item, ReconcilingItemClassification::Duplicate, ReconcilingItemStatus::Excluded, 'duplicate', $reason);
    }

    public function carryForwardTimingDifference(ReconcilingItem $item, string $reason, ?int $ownerId = null): ReconcilingItem
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A carry-forward reason is required.');
        }

        return DB::transaction(function () use ($item, $reason, $ownerId): ReconcilingItem {
            $item = ReconcilingItem::query()->lockForUpdate()->findOrFail($item->id);
            $reconciliation = BankReconciliation::query()->lockForUpdate()->findOrFail($item->reconciliation_id);
            $this->mutationGuard->assert($reconciliation, lockedMessage: 'Timing differences require an editable reconciliation.');

            if ($item->status !== ReconcilingItemStatus::Open
                || ! in_array($item->classification, [ReconcilingItemClassification::OutstandingPayment, ReconcilingItemClassification::DepositInTransit], true)
                || $item->side !== ReconcilingItemSide::Bank
                || ! $item->move_line_id
                || $item->statement_line_id) {
                throw new InvalidArgumentException('Only an open, GL-backed outstanding payment or deposit in transit can be carried forward.');
            }

            $item->update([
                'status'   => ReconcilingItemStatus::CarriedForward,
                'owner_id' => $ownerId ?: $item->owner_id,
                'notes'    => $reason,
            ]);
            BankReconciliation::logEvent($reconciliation, 'exception_carried_forward', [
                'item_id' => $item->id, 'reason' => $reason, 'owner_id' => $item->owner_id, 'actor_id' => Auth::id(),
            ]);

            return $item->refresh();
        });
    }

    public function resolveWithAdjustment(ReconcilingItem $item, ReconAdjustment $adjustment, string $reason): ReconcilingItem
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A correction-resolution reason is required.');
        }

        return DB::transaction(function () use ($item, $adjustment, $reason): ReconcilingItem {
            $item = ReconcilingItem::query()->lockForUpdate()->findOrFail($item->id);
            $reconciliation = BankReconciliation::query()->lockForUpdate()->findOrFail($item->reconciliation_id);
            $adjustment = ReconAdjustment::query()->lockForUpdate()->findOrFail($adjustment->id);
            $this->mutationGuard->assert($reconciliation, lockedMessage: 'Exception resolution requires an editable reconciliation.');

            $correctable = [
                ReconcilingItemClassification::MissingBookEntry,
                ReconcilingItemClassification::ReturnedPayment,
                ReconcilingItemClassification::Transfer,
                ReconcilingItemClassification::Unidentified,
                ReconcilingItemClassification::Disputed,
                ReconcilingItemClassification::InvestigationRequired,
            ];
            if (! in_array($item->status, [ReconcilingItemStatus::Open, ReconcilingItemStatus::CarriedForward], true)
                || ! in_array($item->classification, $correctable, true)
                || (int) $adjustment->reconciliation_id !== (int) $reconciliation->id
                || (int) $adjustment->company_id !== (int) $reconciliation->company_id
                || ! $item->statement_line_id
                || (int) $adjustment->statement_line_id !== (int) $item->statement_line_id
                || ! $adjustment->posted_at
                || $adjustment->reversed_at
                || bccomp((string) $adjustment->amount, (string) $item->amount, 4) !== 0) {
                throw new InvalidArgumentException('Resolution requires a posted, unreversed adjustment for the same company, reconciliation, source line, and exact amount.');
            }

            $statementLine = $this->statementLineTreatment->lock($reconciliation, $item->statement_line_id);
            if ($statementLine->terminal_treatment !== 'adjustment'
                || $statementLine->terminal_reference_type !== 'adjustment'
                || (int) $statementLine->terminal_reference_id !== (int) $adjustment->id) {
                throw new InvalidArgumentException('The source statement line is not terminally linked to this adjustment.');
            }

            $item->update([
                'status'                     => ReconcilingItemStatus::Resolved,
                'resolved_reconciliation_id' => $reconciliation->id,
                'resolved_at'                => now(),
                'resolved_by'                => Auth::id(),
                'evidence'                   => [...($item->evidence ?? []), 'adjustment_id' => $adjustment->id],
                'notes'                      => $reason,
            ]);
            BankReconciliation::logEvent($reconciliation, 'exception_resolved_with_adjustment', [
                'item_id' => $item->id, 'adjustment_id' => $adjustment->id, 'reason' => $reason, 'actor_id' => Auth::id(),
            ]);

            return $item->refresh();
        });
    }

    private function terminalize(
        ReconcilingItem $item,
        ReconcilingItemClassification $classification,
        ReconcilingItemStatus $status,
        string $treatment,
        string $reason,
    ): ReconcilingItem {
        $this->safety->assertFinancialMutationsEnabled($item->company_id);
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('An approval reason is required.');
        }

        return DB::transaction(function () use ($item, $classification, $status, $treatment, $reason): ReconcilingItem {
            $item = ReconcilingItem::query()->lockForUpdate()->findOrFail($item->id);
            $reconciliation = BankReconciliation::query()->lockForUpdate()->findOrFail($item->reconciliation_id);
            $this->mutationGuard->assert($reconciliation, lockedMessage: 'Exceptions require an editable reconciliation.');
            if ($item->classification !== $classification || $item->side !== ReconcilingItemSide::Bank || ! $item->statement_line_id) {
                throw new InvalidArgumentException('This exception cannot use the selected terminal treatment.');
            }
            if ($item->status !== ReconcilingItemStatus::Open) {
                throw new InvalidArgumentException('Only an open exception can be approved.');
            }

            $statementLine = $this->statementLineTreatment->lock($reconciliation, $item->statement_line_id);
            $remaining = bcadd((string) ($statementLine->amount_residual ?? $statementLine->amount), '0', 4);
            $expectedAmount = $classification === ReconcilingItemClassification::BankError
                ? bcmul($remaining, '-1', 4)
                : $remaining;
            if (bccomp($expectedAmount, (string) $item->amount, 4) !== 0) {
                throw new InvalidArgumentException($classification === ReconcilingItemClassification::BankError
                    ? 'The bank-error correction must be opposite to the statement line remaining amount.'
                    : 'The exception amount must equal the statement line remaining amount.');
            }

            $item->update([
                'status'      => $status,
                'notes'       => $reason,
                'resolved_at' => now(),
                'resolved_by' => Auth::id(),
            ]);
            $this->statementLineTreatment->apply(
                $reconciliation,
                $statementLine,
                $treatment,
                'reconciling_item',
                $item->id,
                $reason,
                settleResidual: true,
            );
            BankReconciliation::logEvent($reconciliation, 'exception_approved', [
                'item_id'   => $item->id,
                'treatment' => $treatment,
                'reason'    => $reason,
                'actor_id'  => Auth::id(),
            ]);

            return $item->refresh();
        });
    }
}

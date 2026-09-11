<?php

namespace App\Services\Recon;

use App\Models\Recon\BankReconciliation;
use App\Models\Recon\ReconAdjustment;
use App\Models\Recon\ReconcilingItem;
use App\Models\Recon\StatementLine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\PaymentType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Models\Payment;
use Webkul\Account\Services\MoveWorkflow;

class AdjustmentService
{
    public const POSTABLE_TYPES = ['bank_fee', 'interest', 'direct_charge', 'correction', 'other'];

    private const SOURCE_REQUIRED_TYPES = ['bank_fee', 'interest', 'direct_charge', 'other', 'nsf', 'fx_difference', 'transfer'];

    public function __construct(
        private readonly ReconciliationSafety $safety,
        private readonly MoveWorkflow $moveWorkflow,
        private readonly StatementLineTreatment $statementLineTreatment,
        private readonly ReconciliationMutationGuard $mutationGuard,
    ) {}

    public function post(
        BankReconciliation $reconciliation,
        string $type,
        float|int|string $amount,
        ?int $counterpartAccountId,
        ?string $notes = null,
        ?int $statementLineId = null,
    ): ReconAdjustment {
        $this->mutationGuard->assert($reconciliation, lockedMessage: 'Adjustments require an editable reconciliation.');

        if (! in_array($type, self::POSTABLE_TYPES, true)) {
            throw new InvalidArgumentException('Use the dedicated returned-payment, transfer, or FX workflow.');
        }

        return $this->postEntry($reconciliation, $type, $amount, $counterpartAccountId, $notes, $statementLineId);
    }

    public function postTransfer(BankReconciliation $reconciliation, float|int|string $amount, int $destinationLiquidityAccountId, ?string $notes = null, ?int $statementLineId = null): ReconAdjustment
    {
        $this->mutationGuard->assert($reconciliation, lockedMessage: 'Adjustments require an editable reconciliation.');
        $account = $this->account($destinationLiquidityAccountId, $reconciliation->company_id);
        if (! in_array($account->account_type->value, ['asset_cash', 'asset_current'], true)) {
            throw new InvalidArgumentException('The transfer destination must be a liquidity or bank clearing account.');
        }

        return $this->postEntry($reconciliation, 'transfer', $amount, $account->id, $notes, $statementLineId);
    }

    public function postFxDifference(BankReconciliation $reconciliation, float|int|string $amount, int $gainAccountId, int $lossAccountId, ?string $notes = null, ?int $statementLineId = null): ReconAdjustment
    {
        $this->mutationGuard->assert($reconciliation, lockedMessage: 'Adjustments require an editable reconciliation.');
        $amount = $this->decimal($amount);
        $counterpart = bccomp($amount, '0.0000', 4) > 0
            ? $this->account($gainAccountId, $reconciliation->company_id)
            : $this->account($lossAccountId, $reconciliation->company_id);
        $expectedTypes = bccomp($amount, '0.0000', 4) > 0 ? ['income', 'income_other'] : ['expense', 'expense_direct_cost'];
        if (! in_array($counterpart->account_type->value, $expectedTypes, true)) {
            throw new InvalidArgumentException('FX gains require an income account and FX losses require an expense account.');
        }

        return $this->postEntry($reconciliation, 'fx_difference', $amount, $counterpart->id, $notes, $statementLineId);
    }

    public function postReturnedPayment(BankReconciliation $reconciliation, Payment $payment, int $statementLineId, string $reason): ReconAdjustment
    {
        $this->safety->assertFinancialMutationsEnabled($reconciliation->company_id);
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A returned-payment reason is required.');
        }

        return DB::transaction(function () use ($reconciliation, $payment, $statementLineId, $reason): ReconAdjustment {
            $reconciliation = BankReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);
            $this->mutationGuard->assert($reconciliation, lockedMessage: 'Returned payments require an editable reconciliation.');
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ((int) $payment->company_id !== (int) $reconciliation->company_id || ! $payment->move_id) {
                throw new InvalidArgumentException('The payment is outside this company or has no posted accounting entry.');
            }
            if ((int) $payment->currency_id !== (int) $reconciliation->currency_id) {
                throw new InvalidArgumentException('The returned payment currency must match the bank reconciliation currency. Record any FX effect separately.');
            }
            $statementLine = $this->statementLineTreatment->lock($reconciliation, $statementLineId);

            $move = Move::query()->with('lines.account')->findOrFail($payment->move_id);
            if ($move->state !== MoveState::POSTED) {
                throw new InvalidArgumentException('Only a posted payment can be treated as returned.');
            }
            $date = ($reconciliation->statement_date ?? $reconciliation->period_end)->toDateString();
            $reversal = $this->moveWorkflow->reverse(collect([$move]), [[
                'date' => $date, 'reference' => 'Returned payment: '.$reason,
            ]], true)->firstOrFail();
            $counterpart = $move->lines->first(fn (MoveLine $line) => in_array($line->account?->account_type?->value, ['asset_receivable', 'liability_payable'], true));
            if (! $counterpart) {
                throw new InvalidArgumentException('The payment entry has no receivable or payable line to reopen.');
            }

            $returnedAmount = $payment->payment_type === PaymentType::SEND
                ? ltrim(bcadd((string) $payment->amount, '0', 4), '-')
                : '-'.ltrim(bcadd((string) $payment->amount, '0', 4), '-');
            $adjustment = ReconAdjustment::create([
                'company_id'             => $reconciliation->company_id, 'currency_id' => $reconciliation->currency_id,
                'reconciliation_id'      => $reconciliation->id, 'type' => 'nsf',
                'amount'                 => $returnedAmount,
                'counterpart_account_id' => $counterpart->account_id, 'move_id' => $reversal->id,
                'statement_line_id'      => $statementLineId, 'notes' => $reason,
                'posted_at'              => now(), 'creator_id' => Auth::id(),
            ]);
            $this->assertStatementAmount($statementLine, (string) $adjustment->amount);
            $this->statementLineTreatment->apply(
                $reconciliation,
                $statementLine,
                'adjustment',
                'adjustment',
                $adjustment->id,
                $reason,
                settleResidual: true,
                moveId: $reversal->id,
            );
            $staleImpact = app(ReconciliationLifecycleService::class)->markAffectedByHistoricalCorrection(
                $reconciliation,
                $move->date,
                'Returned payment '.$payment->id.' affects previously reconciled activity.',
                'returned_payment_posted',
            );
            BankReconciliation::logEvent($reconciliation, 'returned_payment_posted', [
                'payment_id'            => $payment->id, 'adjustment_id' => $adjustment->id, 'reversal_move_id' => $reversal->id,
                'stale_reconciliations' => $staleImpact,
            ]);

            return $adjustment;
        });
    }

    private function postEntry(
        BankReconciliation $reconciliation,
        string $type,
        float|int|string $amount,
        ?int $counterpartAccountId,
        ?string $notes = null,
        ?int $statementLineId = null,
    ): ReconAdjustment {
        $this->mutationGuard->assert($reconciliation, lockedMessage: 'Adjustments require an editable reconciliation.');

        if (! array_key_exists($type, ReconAdjustment::TYPES)) {
            throw new InvalidArgumentException("Unknown adjustment type: {$type}");
        }
        if (! $counterpartAccountId) {
            throw new InvalidArgumentException('A configured counterpart account is required.');
        }
        if (in_array($type, self::SOURCE_REQUIRED_TYPES, true) && ! $statementLineId) {
            throw new InvalidArgumentException('Select the bank statement line that this adjustment resolves.');
        }

        $amount = $this->decimal($amount);
        if (bccomp($amount, '0.0000', 4) === 0) {
            throw new InvalidArgumentException('Adjustment amount cannot be zero.');
        }

        return DB::transaction(function () use ($reconciliation, $type, $amount, $counterpartAccountId, $notes, $statementLineId): ReconAdjustment {
            $reconciliation = BankReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);
            $this->mutationGuard->assert($reconciliation, lockedMessage: 'Adjustments require an editable reconciliation.');
            $statementLine = $statementLineId
                ? $this->statementLineTreatment->lock($reconciliation, $statementLineId)
                : null;
            if ($statementLine) {
                $this->assertStatementAmount($statementLine, $amount);
            }

            $bankAccount = $this->account($reconciliation->liquidity_account_id, $reconciliation->company_id);
            $counterpart = $this->account($counterpartAccountId, $reconciliation->company_id);
            if ($bankAccount->id === $counterpart->id) {
                throw new InvalidArgumentException('Bank and counterpart accounts must be different.');
            }

            $date = ($reconciliation->statement_date ?? $reconciliation->period_end)?->toDateString();
            if (! $date) {
                throw new InvalidArgumentException('A statement or period end date is required.');
            }

            $company = $reconciliation->company()->with('currency')->firstOrFail();
            $bankCurrency = $reconciliation->currency()->firstOrFail();
            $companyCurrency = $company->currency;
            if (! $companyCurrency) {
                throw new InvalidArgumentException('The company currency must be configured before posting a reconciliation adjustment.');
            }

            $companyAmount = $amount;
            if ((int) $bankCurrency->id !== (int) $companyCurrency->id) {
                $hasBankRate = $bankCurrency->rates()
                    ->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $company->id))
                    ->whereDate('name', '<=', $date)
                    ->where('rate', '>', 0)
                    ->exists();
                if (! $hasBankRate) {
                    throw new InvalidArgumentException('A valid exchange rate is required for the bank currency on or before the adjustment date.');
                }

                $companyAmount = number_format(
                    $bankCurrency->convert((float) $amount, $companyCurrency, $company, $date),
                    4,
                    '.',
                    '',
                );
            }

            $move = Move::create([
                'journal_id'  => $reconciliation->journal_id,
                'company_id'  => $reconciliation->company_id,
                'currency_id' => $reconciliation->currency_id,
                'date'        => $date,
                'move_type'   => 'entry',
                'state'       => 'draft',
                'reference'   => $notes ?: ReconAdjustment::TYPES[$type],
                'creator_id'  => Auth::id(),
            ]);

            $bankDebit = bccomp($companyAmount, '0.0000', 4) > 0 ? $companyAmount : '0.0000';
            $bankCredit = bccomp($companyAmount, '0.0000', 4) < 0 ? ltrim($companyAmount, '-') : '0.0000';
            foreach ([
                [$bankAccount->id, $bankDebit, $bankCredit, $companyAmount, $amount],
                [$counterpart->id, $bankCredit, $bankDebit, bcmul($companyAmount, '-1', 4), bcmul($amount, '-1', 4)],
            ] as [$accountId, $debit, $credit, $balance, $amountCurrency]) {
                MoveLine::create([
                    'move_id'             => $move->id,
                    'journal_id'          => $reconciliation->journal_id,
                    'company_id'          => $reconciliation->company_id,
                    'company_currency_id' => $companyCurrency->id,
                    'currency_id'         => $reconciliation->currency_id,
                    'account_id'          => $accountId,
                    'name'                => $notes ?: ReconAdjustment::TYPES[$type],
                    'date'                => $date,
                    'debit'               => $debit,
                    'credit'              => $credit,
                    'balance'             => $balance,
                    'amount_currency'     => $amountCurrency,
                    'creator_id'          => Auth::id(),
                ]);
            }

            $move = $this->moveWorkflow->post($move->fresh('lines'));
            $adjustment = ReconAdjustment::create([
                'company_id'             => $reconciliation->company_id,
                'currency_id'            => $reconciliation->currency_id,
                'reconciliation_id'      => $reconciliation->id,
                'type'                   => $type,
                'amount'                 => $amount,
                'counterpart_account_id' => $counterpart->id,
                'move_id'                => $move->id,
                'statement_line_id'      => $statementLineId,
                'notes'                  => $notes,
                'posted_at'              => now(),
                'creator_id'             => Auth::id(),
            ]);
            if ($statementLine) {
                $this->statementLineTreatment->apply(
                    $reconciliation,
                    $statementLine,
                    'adjustment',
                    'adjustment',
                    $adjustment->id,
                    $notes,
                    settleResidual: true,
                    moveId: $move->id,
                );
            }
            BankReconciliation::logEvent($reconciliation, 'adjustment_posted', [
                'adjustment_id' => $adjustment->id,
                'type'          => $type,
                'amount'        => $amount,
                'move_id'       => $move->id,
            ]);

            return $adjustment;
        });
    }

    public function reverse(ReconAdjustment $adjustment, string $reason): ReconAdjustment
    {
        $this->safety->assertFinancialMutationsEnabled($adjustment->company_id);
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A reversal reason is required.');
        }

        return DB::transaction(function () use ($adjustment, $reason): ReconAdjustment {
            $adjustment = ReconAdjustment::query()->lockForUpdate()->findOrFail($adjustment->id);
            if ($adjustment->reversed_at) {
                throw new InvalidArgumentException('This adjustment has already been reversed.');
            }
            $reconciliation = BankReconciliation::query()->lockForUpdate()->findOrFail($adjustment->reconciliation_id);
            $this->mutationGuard->assert($reconciliation, lockedMessage: 'Reopen the reconciliation before reversing an adjustment.');

            $move = Move::query()->findOrFail($adjustment->move_id);
            $reversal = $this->moveWorkflow->reverse(collect([$move]), [[
                'date'      => ($reconciliation->statement_date ?? $reconciliation->period_end)->toDateString(),
                'reference' => $reason,
            ]])->firstOrFail();
            $this->moveWorkflow->post($reversal->fresh('lines'));
            $adjustment->update([
                'reversed_at' => now(), 'reversed_by' => Auth::id(), 'reversal_reason' => $reason,
            ]);
            if ($adjustment->statement_line_id) {
                $statementLine = $this->statementLineTreatment->lock($reconciliation, $adjustment->statement_line_id);
                $this->statementLineTreatment->clear($reconciliation, $statementLine, 'adjustment', $adjustment->id, (string) $adjustment->amount);
            }
            $resolvedItems = ReconcilingItem::query()
                ->where('reconciliation_id', $reconciliation->id)
                ->where('company_id', $reconciliation->company_id)
                ->where('status', 'resolved')
                ->where('statement_line_id', $adjustment->statement_line_id)
                ->get()
                ->filter(fn (ReconcilingItem $item): bool => (int) ($item->evidence['adjustment_id'] ?? 0) === (int) $adjustment->id);
            foreach ($resolvedItems as $item) {
                $item->update([
                    'status'                     => 'open',
                    'resolved_reconciliation_id' => null,
                    'resolved_at'                => null,
                    'resolved_by'                => null,
                    'notes'                      => 'Correction reversed: '.$reason,
                ]);
                BankReconciliation::logEvent($reconciliation, 'exception_reopened_after_adjustment_reversal', [
                    'item_id' => $item->id, 'adjustment_id' => $adjustment->id, 'reason' => $reason,
                ]);
            }
            BankReconciliation::logEvent($reconciliation, 'adjustment_reversed', [
                'adjustment_id' => $adjustment->id, 'reversal_move_id' => $reversal->id, 'reason' => $reason,
            ]);

            return $adjustment->refresh();
        });
    }

    private function account(?int $accountId, int $companyId): Account
    {
        $account = Account::query()->withoutGlobalScopes()
            ->whereKey($accountId)
            ->where(owned_by_company($companyId))
            ->where('deprecated', false)
            ->first();

        if (! $account) {
            throw new InvalidArgumentException('The selected account is unavailable, deprecated, or belongs to another company.');
        }

        return $account;
    }

    private function decimal(float|int|string $amount): string
    {
        if (! is_numeric($amount)) {
            throw new InvalidArgumentException('Adjustment amount must be numeric.');
        }

        return bcadd((string) $amount, '0', 4);
    }

    private function assertStatementAmount(StatementLine $line, string $amount): void
    {
        $remaining = bcadd((string) ($line->amount_residual ?? $line->amount), '0', 4);

        if (bccomp($remaining, $amount, 4) !== 0) {
            throw new InvalidArgumentException('The adjustment amount must equal the selected statement line remaining amount.');
        }
    }
}

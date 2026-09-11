<?php

namespace App\Services\Recon;

use App\Models\Recon\BankOpeningBalance;
use App\Models\Recon\BankReconciliation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Services\MoveWorkflow;

class BankOpeningBalanceService
{
    public function __construct(
        private readonly MoveWorkflow $moveWorkflow,
        private readonly ReconciliationSafety $safety,
    ) {}

    /**
     * Establish the first Bank GL balance for a company-owned bank account.
     * The amount is signed: positive means a debit Bank balance; negative means an overdraft.
     */
    public function post(int $companyId, array $data): BankOpeningBalance
    {
        Gate::authorize('postOpeningBalance', BankReconciliation::class);
        $this->safety->assertFinancialMutationsEnabled($companyId);

        $amount = $this->decimal($data['amount'] ?? null);
        if (bccomp($amount, '0.0000', 4) === 0) {
            throw new InvalidArgumentException('Opening balance cannot be zero. Do not create a baseline when the Bank GL is already zero.');
        }

        $effectiveDate = (string) ($data['effective_date'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate)) {
            throw new InvalidArgumentException('An effective date is required for the opening balance.');
        }

        return DB::transaction(function () use ($companyId, $data, $amount, $effectiveDate): BankOpeningBalance {
            $journal = Journal::query()->lockForUpdate()
                ->where('company_id', $companyId)
                ->with(['bankAccount', 'company.currency'])
                ->find($data['journal_id'] ?? null);

            if (! $journal?->isReadyForReconciliation()) {
                throw new InvalidArgumentException('Select a bank journal with an active company bank account and default Bank ledger account.');
            }
            if ($journal->type->value !== 'bank') {
                throw new InvalidArgumentException('Opening balances can only be posted for bank journals. Cash-on-hand requires a separate cash-count workflow.');
            }
            if ((int) $journal->bankAccount->company_id !== $companyId) {
                throw new InvalidArgumentException('The selected bank account must belong to the active company.');
            }

            $company = $journal->company;
            if (! $company?->currency_id || ! $journal->currency_id) {
                throw new InvalidArgumentException('The company and bank journal currencies must be configured before posting an opening balance.');
            }
            if ((int) $company->currency_id !== (int) $journal->currency_id) {
                throw new InvalidArgumentException('Opening balances currently require a bank journal in the company currency.');
            }

            $bank = Account::query()
                ->whereHas('companies', fn ($query) => $query->whereKey($companyId))
                ->find($journal->default_account_id);
            $counterpart = Account::query()
                ->whereHas('companies', fn ($query) => $query->whereKey($companyId))
                ->find($data['counterpart_account_id'] ?? null);
            if (! $bank || ! $counterpart) {
                throw new InvalidArgumentException('Select a valid Bank and opening-balance equity account.');
            }
            if ($bank->id === $counterpart->id) {
                throw new InvalidArgumentException('Bank and opening-balance equity accounts must be different.');
            }
            if ($bank->deprecated || $counterpart->deprecated) {
                throw new InvalidArgumentException('Archived accounts cannot be used for an opening balance.');
            }
            if (! in_array($bank->account_type, [AccountType::ASSET_CASH, AccountType::ASSET_CURRENT], true)) {
                throw new InvalidArgumentException('The selected journal default account must be a cash or current-asset Bank GL account.');
            }
            if (! in_array($counterpart->account_type, [AccountType::EQUITY, AccountType::EQUITY_UNAFFECTED], true)) {
                throw new InvalidArgumentException('The counterpart must be an equity account, normally Opening Balance Equity.');
            }

            $firstPeriodStart = BankReconciliation::query()
                ->where('company_id', $companyId)
                ->where('bank_account_id', $journal->bank_account_id)
                ->whereNotNull('period_start')
                ->min('period_start');
            if ($firstPeriodStart && $effectiveDate >= $firstPeriodStart) {
                throw new InvalidArgumentException("The opening balance date must be before the first reconciliation period ({$firstPeriodStart}).");
            }

            if (BankOpeningBalance::query()
                ->where('company_id', $companyId)
                ->where('bank_account_id', $journal->bank_account_id)
                ->lockForUpdate()
                ->exists()) {
                throw new InvalidArgumentException('An opening balance already exists for this bank account. Reverse and correct that entry instead of creating another baseline.');
            }

            $priorBankActivity = DB::table('accounts_account_move_lines as line')
                ->join('accounts_account_moves as move', 'move.id', '=', 'line.move_id')
                ->where('move.company_id', $companyId)
                ->where('line.company_id', $companyId)
                ->where('move.state', 'posted')
                ->where('line.account_id', $bank->id)
                ->whereDate('move.date', '<=', $effectiveDate)
                ->whereDate('line.date', '<=', $effectiveDate)
                ->exists();
            if ($priorBankActivity) {
                throw new InvalidArgumentException('The Bank GL already has posted activity on or before this date. Do not overlay an opening balance; use a reviewed correcting entry instead.');
            }

            $label = trim((string) ($data['notes'] ?? '')) ?: 'Opening balance';
            $debit = bccomp($amount, '0.0000', 4) > 0 ? $amount : '0.0000';
            $credit = bccomp($amount, '0.0000', 4) < 0 ? ltrim($amount, '-') : '0.0000';

            $move = Move::create([
                'journal_id'      => $journal->id,
                'company_id'      => $companyId,
                'currency_id'     => $journal->currency_id,
                'partner_bank_id' => $journal->bank_account_id,
                'date'            => $effectiveDate,
                'move_type'       => 'entry',
                'state'           => 'draft',
                'reference'       => 'Bank opening balance · '.$journal->bankAccount->account_number,
                'narration'       => $label,
                'creator_id'      => Auth::id(),
            ]);

            foreach ([
                [$bank->id, $debit, $credit, $amount],
                [$counterpart->id, $credit, $debit, bcmul($amount, '-1', 4)],
            ] as [$accountId, $lineDebit, $lineCredit, $balance]) {
                MoveLine::create([
                    'move_id'             => $move->id,
                    'journal_id'          => $journal->id,
                    'company_id'          => $companyId,
                    'company_currency_id' => $company->currency_id,
                    'currency_id'         => $journal->currency_id,
                    'account_id'          => $accountId,
                    'name'                => $label,
                    'reference'           => $move->reference,
                    'date'                => $effectiveDate,
                    'debit'               => $lineDebit,
                    'credit'              => $lineCredit,
                    'balance'             => $balance,
                    'amount_currency'     => $balance,
                    'creator_id'          => Auth::id(),
                ]);
            }

            $move = $this->moveWorkflow->post($move->fresh('lines'));

            return BankOpeningBalance::create([
                'company_id'             => $companyId,
                'bank_account_id'        => $journal->bank_account_id,
                'journal_id'             => $journal->id,
                'liquidity_account_id'   => $bank->id,
                'counterpart_account_id' => $counterpart->id,
                'currency_id'            => $journal->currency_id,
                'move_id'                => $move->id,
                'effective_date'         => $effectiveDate,
                'amount'                 => $amount,
                'notes'                  => $label,
                'creator_id'             => Auth::id(),
            ]);
        });
    }

    private function decimal(mixed $amount): string
    {
        $amount = trim((string) $amount);
        if (! preg_match('/^-?\d+(?:\.\d{1,4})?$/', $amount)) {
            throw new InvalidArgumentException('Opening balance must be a valid number with at most four decimal places.');
        }

        $integerPart = ltrim(explode('.', ltrim($amount, '-'), 2)[0], '0');
        if (strlen($integerPart ?: '0') > 14) {
            throw new InvalidArgumentException('Opening balance exceeds the maximum supported amount.');
        }

        return bcadd($amount, '0', 4);
    }
}

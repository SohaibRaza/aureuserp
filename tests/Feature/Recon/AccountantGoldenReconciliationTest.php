<?php

require_once __DIR__.'/ReconTestSupport.php';

use App\Enums\Recon\ReconcilingItemClassification;
use App\Enums\Recon\ReconcilingItemSide;
use App\Models\Recon\BankReconciliation;
use App\Models\Recon\ReconcilingItem;
use App\Models\Recon\StatementLine;
use App\Services\Recon\MatchingEngine;
use App\Services\Recon\MatchService;
use App\Services\Recon\ReconciliationCalculator;
use App\Services\Recon\ReconciliationExceptionService;
use Illuminate\Support\Facades\DB;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\BankStatement;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('accounts');
    $this->company = Company::query()->firstOrFail();
    $this->actingAs(User::query()->firstOrFail());
    config()->set('reconciliation.financial_mutations_enabled', true);
    config()->set('reconciliation.require_company_rollout', false);
});

function goldenRecon(string $statementEnd): BankReconciliation
{
    $accountId = (int) Account::query()->withoutGlobalScopes()->max('id') + 1;
    DB::table('accounts_accounts')->insert([
        'id'         => $accountId, 'currency_id' => test()->company->currency_id,
        'creator_id' => auth()->id(), 'account_type' => 'asset_cash',
        'name'       => 'Golden isolated bank '.$accountId, 'code' => 'G'.$accountId,
        'deprecated' => false, 'reconcile' => false, 'non_trade' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    Account::query()->withoutGlobalScopes()->findOrFail($accountId)->companies()->syncWithoutDetaching([test()->company->id]);

    $baseJournal = testBankJournal();
    $journal = $baseJournal->replicate();
    $journal->forceFill([
        'name'               => 'Golden bank journal '.$accountId,
        'code'               => 'GB'.$accountId,
        'sort'               => 'GB'.$accountId,
        'default_account_id' => $accountId,
    ])->save();

    return BankReconciliation::query()->create([
        ...validBankReconciliationAttributes($journal),
        'liquidity_account_id' => $accountId,
        'status'               => 'in_progress',
        'period_start'         => '2026-09-01',
        'period_end'           => '2026-09-30',
        'statement_date'       => '2026-09-30',
        'balance_start'        => '10000.0000',
        'balance_end_real'     => $statementEnd,
    ]);
}

/** Post a balanced journal entry; positive bank balance is a debit, negative is a credit. */
function goldenBookEntry(BankReconciliation $reconciliation, string $date, string $bankAmount, string $label): int
{
    $counterpartId = DB::table('accounts_accounts')
        ->where('id', '<>', $reconciliation->liquidity_account_id)
        ->value('id');
    $moveId = DB::table('accounts_account_moves')->insertGetId([
        'journal_id'  => $reconciliation->journal_id, 'company_id' => $reconciliation->company_id,
        'currency_id' => $reconciliation->currency_id, 'creator_id' => auth()->id(),
        'name'        => fake()->unique()->bothify('GOLD/####'), 'reference' => $label,
        'state'       => 'posted', 'move_type' => 'entry', 'date' => $date,
        'created_at'  => now(), 'updated_at' => now(),
    ]);
    $debit = bccomp($bankAmount, '0', 4) > 0 ? $bankAmount : '0.0000';
    $credit = bccomp($bankAmount, '0', 4) < 0 ? ltrim($bankAmount, '-') : '0.0000';
    $base = [
        'move_id'     => $moveId, 'journal_id' => $reconciliation->journal_id,
        'company_id'  => $reconciliation->company_id, 'company_currency_id' => $reconciliation->currency_id,
        'currency_id' => $reconciliation->currency_id, 'creator_id' => auth()->id(),
        'name'        => $label, 'date' => $date, 'parent_state' => 'posted',
        'reconciled'  => false, 'created_at' => now(), 'updated_at' => now(),
    ];
    $lineId = DB::table('accounts_account_move_lines')->insertGetId([
        ...$base, 'account_id' => $reconciliation->liquidity_account_id,
        'debit'                => $debit, 'credit' => $credit, 'balance' => $bankAmount,
        'amount_residual'      => $bankAmount,
    ]);
    DB::table('accounts_account_move_lines')->insert([
        ...$base, 'account_id' => $counterpartId,
        'debit'                => $credit, 'credit' => $debit, 'balance' => bcmul($bankAmount, '-1', 4),
    ]);

    return $lineId;
}

function goldenStatement(BankReconciliation $reconciliation): BankStatement
{
    $statement = BankStatement::query()->create([
        'company_id'       => $reconciliation->company_id, 'journal_id' => $reconciliation->journal_id,
        'date'             => '2026-09-30', 'name' => 'Independent accountant statement',
        'balance_start'    => $reconciliation->balance_start,
        'balance_end'      => $reconciliation->balance_end_real,
        'balance_end_real' => $reconciliation->balance_end_real,
        'is_completed'     => false,
    ]);
    $reconciliation->update(['statement_id' => $statement->id]);

    return $statement;
}

function goldenStatementLine(BankReconciliation $reconciliation, string $amount, string $key): StatementLine
{
    $statement = $reconciliation->statement_id
        ? BankStatement::query()->findOrFail($reconciliation->statement_id)
        : goldenStatement($reconciliation);

    return StatementLine::query()->create([
        'statement_id'      => $statement->id, 'company_id' => $reconciliation->company_id,
        'journal_id'        => $reconciliation->journal_id, 'currency_id' => $reconciliation->currency_id,
        'transaction_date'  => '2026-09-30', 'amount' => $amount, 'amount_residual' => $amount,
        'import_status'     => 'accepted', 'source_fingerprint' => hash('sha256', 'golden-'.$key),
        'payment_reference' => $key,
    ]);
}

function goldenTimingItem(BankReconciliation $reconciliation, string $classification, string $amount, int $moveLineId): void
{
    ReconcilingItem::query()->create([
        'company_id'                    => $reconciliation->company_id, 'reconciliation_id' => $reconciliation->id,
        'originating_reconciliation_id' => $reconciliation->id, 'move_line_id' => $moveLineId,
        'side'                          => 'bank', 'classification' => $classification, 'amount' => $amount,
        'origin_period_end'             => $reconciliation->period_end, 'status' => 'carried_forward',
    ]);
}

it('proves a hand-worked month: 10000 opening +3000 receipt -2000 payment +500 DIT -250 outstanding -50 fee +20 interest -400 returned receipt -1000 transfer +30 FX gain = 9850 book; 9560 bank +500 DIT -250 outstanding +40 erroneous-debit correction = 9850', function (): void {
    $reconciliation = goldenRecon('9560.0000');
    goldenBookEntry($reconciliation, '2026-08-31', '10000.0000', 'Opening book balance');
    goldenBookEntry($reconciliation, '2026-09-02', '3000.0000', 'Cleared customer receipt: Dr Bank 3000');
    goldenBookEntry($reconciliation, '2026-09-04', '-2000.0000', 'Cleared supplier payment: Cr Bank 2000');
    $deposit = goldenBookEntry($reconciliation, '2026-09-28', '500.0000', 'Deposit in transit: Dr Bank 500');
    $payment = goldenBookEntry($reconciliation, '2026-09-29', '-250.0000', 'Outstanding payment: Cr Bank 250');
    goldenBookEntry($reconciliation, '2026-09-30', '-50.0000', 'Bank fee: Dr Expense / Cr Bank 50');
    goldenBookEntry($reconciliation, '2026-09-30', '20.0000', 'Interest: Dr Bank / Cr Income 20');
    goldenBookEntry($reconciliation, '2026-09-30', '-400.0000', 'Returned receipt: Dr Receivable / Cr Bank 400');
    goldenBookEntry($reconciliation, '2026-09-30', '-1000.0000', 'Transfer out: Dr Other Bank / Cr Bank 1000');
    goldenBookEntry($reconciliation, '2026-09-30', '30.0000', 'FX gain: Dr Bank / Cr FX Gain 30');
    goldenTimingItem($reconciliation, 'deposit_in_transit', '500.0000', $deposit);
    goldenTimingItem($reconciliation, 'outstanding_payment', '250.0000', $payment);

    goldenStatement($reconciliation);
    $erroneousDebit = goldenStatementLine($reconciliation->refresh(), '-40.0000', 'bank-posted-erroneous-debit');
    $duplicate = goldenStatementLine($reconciliation->refresh(), '3000.0000', 'duplicate-import-row');
    $exceptions = app(ReconciliationExceptionService::class);
    $bankError = $exceptions->classify($reconciliation->refresh(), ReconcilingItemClassification::BankError, ReconcilingItemSide::Bank, '40.0000', $erroneousDebit->id);
    $duplicateItem = $exceptions->classify($reconciliation->refresh(), ReconcilingItemClassification::Duplicate, ReconcilingItemSide::Bank, '3000.0000', $duplicate->id);
    $exceptions->approveBankError($bankError, 'Bank confirmed erroneous debit; add 40 to reported balance');
    $exceptions->excludeDuplicate($duplicateItem, 'Same imported transaction as cleared receipt; no GL or bank-balance effect');

    $result = app(ReconciliationCalculator::class)->calculate($reconciliation->refresh());

    expect($result)->toMatchArray([
        'gl_balance_start'      => '10000.00', 'gl_balance_end' => '9850.00',
        'statement_balance_end' => '9560.00', 'deposits_in_transit' => '500.00',
        'outstanding_payments'  => '250.00', 'bank_errors' => '40.00',
        'adjusted_bank_balance' => '9850.00', 'adjusted_book_balance' => '9850.00',
        'difference'            => '0.00', 'is_balanced' => true,
    ])->and($bankError->refresh()->evidence['source_statement_amount'])->toBe('-40.0000')
        ->and($duplicateItem->refresh()->evidence['source_statement_amount'])->toBe('3000.0000');
});

it('applies the opposite correction for an erroneous bank credit: 1000 reported bank -25 correction = 975 adjusted bank and book', function (): void {
    $reconciliation = goldenRecon('1000.0000');
    goldenBookEntry($reconciliation, '2026-08-31', '975.0000', 'Opening and closing book balance');
    goldenStatement($reconciliation);
    $erroneousCredit = goldenStatementLine($reconciliation->refresh(), '25.0000', 'bank-posted-erroneous-credit');
    $item = app(ReconciliationExceptionService::class)->classify(
        $reconciliation->refresh(), ReconcilingItemClassification::BankError,
        ReconcilingItemSide::Bank, '-25.0000', $erroneousCredit->id,
    );
    app(ReconciliationExceptionService::class)->approveBankError($item, 'Bank confirmed erroneous credit; subtract 25');

    expect(app(ReconciliationCalculator::class)->calculate($reconciliation->refresh()))->toMatchArray([
        'statement_balance_end' => '1000.00', 'bank_errors' => '-25.00',
        'adjusted_bank_balance' => '975.00', 'adjusted_book_balance' => '975.00',
        'difference'            => '0.00', 'is_balanced' => true,
    ]);
});

it('shows zero for a clean statement and exposes a one-cent nonzero difference without tolerance masking', function (): void {
    $clean = goldenRecon('1250.0000');
    goldenBookEntry($clean, '2026-08-31', '1000.0000', 'Opening');
    goldenBookEntry($clean, '2026-09-30', '250.0000', 'Cleared receipt');
    $cleanResult = app(ReconciliationCalculator::class)->calculate($clean);

    $offByCent = goldenRecon('1249.9900');
    goldenBookEntry($offByCent, '2026-08-31', '1000.0000', 'Opening');
    goldenBookEntry($offByCent, '2026-09-30', '250.0000', 'Cleared receipt');
    $differenceResult = app(ReconciliationCalculator::class)->calculate($offByCent);

    expect($cleanResult['difference'])->toBe('0.00')->and($cleanResult['is_balanced'])->toBeTrue()
        ->and($differenceResult['difference'])->toBe('-0.01')->and($differenceResult['is_balanced'])->toBeFalse();
});

it('proves partial 60 + remaining 40 and split 60 + 40 allocations without inventing or losing value', function (): void {
    $partialRecon = goldenRecon('100.0000');
    goldenStatement($partialRecon);
    $bank = goldenStatementLine($partialRecon->refresh(), '100.0000', 'partial-bank-100');
    $ledger = goldenBookEntry($partialRecon, '2026-09-15', '-100.0000', 'Book payment 100');
    $partial = app(MatchService::class)->createMatch($partialRecon->refresh(), [$bank->id], [$ledger], allocations: [
        'bank' => [$bank->id => '60.0000'], 'ledger' => [$ledger => '60.0000'],
    ]);

    expect($partial->amount)->toBe('60.0000')
        ->and($bank->refresh()->amount_residual)->toBe('40.0000')
        ->and(app(MatchingEngine::class)->ledgerCandidates($partialRecon->refresh())->where('line.id', $ledger)->value('remaining_amount'))->toBe('40.0000');

    $splitRecon = goldenRecon('100.0000');
    goldenStatement($splitRecon);
    $splitBank = goldenStatementLine($splitRecon->refresh(), '100.0000', 'split-bank-100');
    $first = goldenBookEntry($splitRecon, '2026-09-16', '-60.0000', 'Invoice allocation 60');
    $second = goldenBookEntry($splitRecon, '2026-09-16', '-40.0000', 'Invoice allocation 40');
    $split = app(MatchService::class)->createMatch($splitRecon->refresh(), [$splitBank->id], [$first, $second]);

    expect($split->amount)->toBe('100.0000')
        ->and($splitBank->refresh()->amount_residual)->toBe('0.0000')
        ->and($splitBank->is_reconciled)->toBeTrue()
        ->and((string) DB::table('recon_match_lines')->where('match_id', $split->id)->where('side', 'bank')->sum('amount_applied'))->toBe('100.0000')
        ->and((string) DB::table('recon_match_lines')->where('match_id', $split->id)->where('side', 'ledger')->sum('amount_applied'))->toBe('-100.0000');
});

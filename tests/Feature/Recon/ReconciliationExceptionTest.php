<?php

require_once __DIR__.'/ReconTestSupport.php';

use App\Enums\Recon\ReconcilingItemClassification;
use App\Enums\Recon\ReconcilingItemSide;
use App\Enums\Recon\ReconcilingItemStatus;
use App\Models\Recon\BankReconciliation;
use App\Models\Recon\StatementLine;
use App\Services\Recon\AdjustmentService;
use App\Services\Recon\FinalizationService;
use App\Services\Recon\ReconciliationExceptionService;
use Illuminate\Support\Facades\DB;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\BankStatement;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Services\MoveWorkflow;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('accounts');
    $this->company = Company::query()->firstOrFail();
    $this->actingAs(User::query()->firstOrFail());
    config()->set('reconciliation.financial_mutations_enabled', true);
    config()->set('reconciliation.require_company_rollout', false);
});

function exceptionRecon(array $overrides = []): BankReconciliation
{
    return BankReconciliation::query()->create([
        ...validBankReconciliationAttributes(),
        'status'           => 'in_progress',
        'period_start'     => '2026-09-01',
        'period_end'       => '2026-09-30',
        'statement_date'   => '2026-09-30',
        'balance_start'    => '0.0000',
        'balance_end_real' => '0.0000',
        ...$overrides,
    ]);
}

function exceptionLine(BankReconciliation $reconciliation, string $amount, string $key): StatementLine
{
    $statement = $reconciliation->statement_id
        ? BankStatement::query()->findOrFail($reconciliation->statement_id)
        : BankStatement::query()->create([
            'company_id'       => $reconciliation->company_id,
            'journal_id'       => $reconciliation->journal_id,
            'date'             => '2026-09-30',
            'name'             => 'EXC-'.$reconciliation->id.'-'.$key,
            'balance_start'    => 0,
            'balance_end'      => $amount,
            'balance_end_real' => $amount,
        ]);
    $reconciliation->update(['statement_id' => $statement->id]);

    return StatementLine::query()->create([
        'statement_id'       => $statement->id,
        'company_id'         => $reconciliation->company_id,
        'journal_id'         => $reconciliation->journal_id,
        'currency_id'        => $reconciliation->currency_id,
        'transaction_date'   => '2026-09-30',
        'amount'             => $amount,
        'amount_residual'    => $amount,
        'import_status'      => 'accepted',
        'source_fingerprint' => hash('sha256', $key),
        'payment_reference'  => $key,
    ]);
}

function exceptionAccount(BankReconciliation $reconciliation, string $type = 'expense'): Account
{
    $id = (int) Account::query()->withoutGlobalScopes()->max('id') + 1;
    DB::table('accounts_accounts')->insert([
        'id'           => $id,
        'currency_id'  => $reconciliation->currency_id,
        'creator_id'   => auth()->id(),
        'account_type' => $type,
        'name'         => 'Exception '.$type.' '.$id,
        'code'         => 'E'.$id,
        'deprecated'   => false,
        'reconcile'    => false,
        'non_trade'    => false,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    $account = Account::query()->withoutGlobalScopes()->findOrFail($id);
    $account->companies()->syncWithoutDetaching([$reconciliation->company_id]);

    return $account;
}

function postedExceptionLiquidityLine(BankReconciliation $reconciliation, string $amount): MoveLine
{
    $counterpart = exceptionAccount($reconciliation, 'asset_current');
    $move = Move::query()->create([
        'journal_id'  => $reconciliation->journal_id,
        'company_id'  => $reconciliation->company_id,
        'currency_id' => $reconciliation->currency_id,
        'date'        => '2026-09-20',
        'move_type'   => 'entry',
        'state'       => 'draft',
        'reference'   => 'Timing evidence',
        'creator_id'  => auth()->id(),
    ]);
    $positive = bccomp($amount, '0', 4) > 0;
    $absolute = ltrim($amount, '-');
    foreach ([
        [$reconciliation->liquidity_account_id, $positive ? $absolute : '0.0000', $positive ? '0.0000' : $absolute, $amount],
        [$counterpart->id, $positive ? '0.0000' : $absolute, $positive ? $absolute : '0.0000', bcmul($amount, '-1', 4)],
    ] as [$accountId, $debit, $credit, $balance]) {
        MoveLine::query()->create([
            'move_id'             => $move->id,
            'journal_id'          => $reconciliation->journal_id,
            'company_id'          => $reconciliation->company_id,
            'company_currency_id' => $reconciliation->currency_id,
            'currency_id'         => $reconciliation->currency_id,
            'account_id'          => $accountId,
            'name'                => 'Timing evidence',
            'date'                => '2026-09-20',
            'debit'               => $debit,
            'credit'              => $credit,
            'balance'             => $balance,
            'amount_currency'     => $balance,
            'creator_id'          => auth()->id(),
        ]);
    }

    $posted = app(MoveWorkflow::class)->post($move->fresh('lines'));

    return $posted->lines->firstWhere('account_id', $reconciliation->liquidity_account_id);
}

it('rejects non-numeric, zero, missing-bank-line, and book-side bank-error inputs', function (): void {
    $reconciliation = exceptionRecon();
    $service = app(ReconciliationExceptionService::class);

    expect(fn () => $service->classify($reconciliation, ReconcilingItemClassification::BankError, ReconcilingItemSide::Bank, 'not-a-number'))
        ->toThrow(InvalidArgumentException::class, 'must be numeric')
        ->and(fn () => $service->classify($reconciliation, ReconcilingItemClassification::BankError, ReconcilingItemSide::Bank, '0'))
        ->toThrow(InvalidArgumentException::class, 'cannot be zero')
        ->and(fn () => $service->classify($reconciliation, ReconcilingItemClassification::Duplicate, ReconcilingItemSide::Bank, '10'))
        ->toThrow(InvalidArgumentException::class, 'require a bank statement line')
        ->and(fn () => $service->classify($reconciliation, ReconcilingItemClassification::BankError, ReconcilingItemSide::Book, '10', 1))
        ->toThrow(InvalidArgumentException::class, 'require a bank statement line');
});

it('allows only one unresolved exception or terminal treatment per statement line', function (): void {
    $reconciliation = exceptionRecon();
    $line = exceptionLine($reconciliation, '25.0000', 'one-only');
    $service = app(ReconciliationExceptionService::class);
    $item = $service->classify($reconciliation, ReconcilingItemClassification::Duplicate, ReconcilingItemSide::Bank, '25.0000', $line->id);

    expect(fn () => $service->classify($reconciliation, ReconcilingItemClassification::BankError, ReconcilingItemSide::Bank, '25.0000', $line->id))
        ->toThrow(InvalidArgumentException::class, 'unresolved exception');

    $service->excludeDuplicate($item, 'Confirmed duplicate');
    expect(fn () => $service->classify($reconciliation, ReconcilingItemClassification::BankError, ReconcilingItemSide::Bank, '25.0000', $line->id))
        ->toThrow(InvalidArgumentException::class, 'terminal treatment');
});

it('requires exact residual evidence before approving a bank exception and preserves the evidence', function (): void {
    $reconciliation = exceptionRecon();
    $line = exceptionLine($reconciliation, '-7.5000', 'evidence');
    $service = app(ReconciliationExceptionService::class);
    $item = $service->classify(
        $reconciliation,
        ReconcilingItemClassification::BankError,
        ReconcilingItemSide::Bank,
        '7.5000',
        $line->id,
        evidence: ['bank_case' => 'CASE-77', 'source' => 'bank-confirmation'],
    );

    DB::table('recon_reconciling_items')->where('id', $item->id)->update(['amount' => '7.4000']);
    expect(fn () => $service->approveBankError($item->refresh(), 'Bank confirmed error'))
        ->toThrow(InvalidArgumentException::class, 'opposite to the statement line remaining amount');

    expect($item->refresh()->status)->toBe(ReconcilingItemStatus::Open)
        ->and($item->evidence)->toMatchArray(['bank_case' => 'CASE-77', 'source' => 'bank-confirmation'])
        ->and($line->refresh()->terminal_treatment)->toBeNull();
});

it('does not create GL entries for duplicate or bank-error resolution', function (): void {
    $reconciliation = exceptionRecon();
    $duplicateLine = exceptionLine($reconciliation, '11.0000', 'duplicate-no-gl');
    $errorLine = exceptionLine($reconciliation, '-4.0000', 'error-no-gl');
    $service = app(ReconciliationExceptionService::class);
    $before = DB::table('accounts_account_moves')->count();

    $duplicate = $service->classify($reconciliation, ReconcilingItemClassification::Duplicate, ReconcilingItemSide::Bank, '11.0000', $duplicateLine->id);
    $error = $service->classify($reconciliation, ReconcilingItemClassification::BankError, ReconcilingItemSide::Bank, '4.0000', $errorLine->id);
    $service->excludeDuplicate($duplicate, 'Duplicate confirmed by bank');
    $service->approveBankError($error, 'Bank posting error confirmed');

    expect(DB::table('accounts_account_moves')->count())->toBe($before)
        ->and($duplicateLine->refresh()->is_reconciled)->toBeTrue()
        ->and($errorLine->refresh()->is_reconciled)->toBeTrue();
});

it('carries forward only GL-backed timing differences with an owner and audit reason', function (): void {
    $reconciliation = exceptionRecon();
    $moveLine = postedExceptionLiquidityLine($reconciliation, '30.0000');
    $service = app(ReconciliationExceptionService::class);
    $item = $service->classify(
        $reconciliation,
        ReconcilingItemClassification::DepositInTransit,
        ReconcilingItemSide::Bank,
        '30.0000',
        moveLineId: $moveLine->id,
        ownerId: auth()->id(),
    );

    $carried = $service->carryForwardTimingDifference($item, 'Expected to clear in October', auth()->id());

    expect($carried->status)->toBe(ReconcilingItemStatus::CarriedForward)
        ->and($carried->owner_id)->toBe(auth()->id())
        ->and($reconciliation->events()->where('event', 'exception_carried_forward')->exists())->toBeTrue()
        ->and(fn () => $service->carryForwardTimingDifference($carried, 'Again'))
        ->toThrow(InvalidArgumentException::class, 'Only an open');
});

it('derives timing differences from the signed remaining liquidity amount', function (): void {
    $reconciliation = exceptionRecon();
    $deposit = postedExceptionLiquidityLine($reconciliation, '30.0000');
    $payment = postedExceptionLiquidityLine($reconciliation, '-25.0000');
    $service = app(ReconciliationExceptionService::class);

    expect(fn () => $service->classify($reconciliation, ReconcilingItemClassification::DepositInTransit, ReconcilingItemSide::Bank, '29.0000', moveLineId: $deposit->id))
        ->toThrow(InvalidArgumentException::class, 'must equal')
        ->and(fn () => $service->classify($reconciliation, ReconcilingItemClassification::OutstandingPayment, ReconcilingItemSide::Bank, '30.0000', moveLineId: $deposit->id))
        ->toThrow(InvalidArgumentException::class, 'does not match')
        ->and(fn () => $service->classify($reconciliation, ReconcilingItemClassification::DepositInTransit, ReconcilingItemSide::Bank, '25.0000', moveLineId: $payment->id))
        ->toThrow(InvalidArgumentException::class, 'does not match');

    $item = $service->classify(
        $reconciliation,
        ReconcilingItemClassification::OutstandingPayment,
        ReconcilingItemSide::Bank,
        '25.0000',
        moveLineId: $payment->id,
    );

    expect($item->amount)->toBe('25.0000')
        ->and($item->evidence['source_liquidity_residual'])->toBe('-25.0000');
});

it('resolves a book correction only through its exact posted adjustment evidence', function (): void {
    $reconciliation = exceptionRecon();
    $line = exceptionLine($reconciliation, '-6.0000', 'book-correction');
    $service = app(ReconciliationExceptionService::class);
    $item = $service->classify(
        $reconciliation,
        ReconcilingItemClassification::MissingBookEntry,
        ReconcilingItemSide::Bank,
        '-6.0000',
        statementLineId: $line->id,
    );
    $adjustment = app(AdjustmentService::class)->post(
        $reconciliation,
        'correction',
        '-6.0000',
        exceptionAccount($reconciliation)->id,
        'Missing bank charge',
        $line->id,
    );

    $resolved = $service->resolveWithAdjustment($item, $adjustment, 'Charge posted to the ledger');
    $calculation = app(FinalizationService::class)->computeDifference($reconciliation);

    expect($resolved->status)->toBe(ReconcilingItemStatus::Resolved)
        ->and($resolved->evidence['adjustment_id'])->toBe($adjustment->id)
        ->and($resolved->resolved_reconciliation_id)->toBe($reconciliation->id)
        ->and($calculation['blocking_item_ids'])->not->toContain($resolved->id);

    app(AdjustmentService::class)->reverse($adjustment, 'Correction was wrong');

    expect(app(FinalizationService::class)->computeDifference($reconciliation)['blocking_item_ids'])
        ->toContain($resolved->id);
});

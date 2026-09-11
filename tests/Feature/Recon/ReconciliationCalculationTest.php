<?php

require_once __DIR__.'/ReconTestSupport.php';

use App\Models\Recon\BankReconciliation;
use App\Models\Recon\ReconAdjustment;
use App\Models\Recon\ReconcilingItem;
use App\Models\Recon\StatementLine;
use App\Services\Recon\FinalizationService;
use App\Services\Recon\ReconciliationCalculator;
use Illuminate\Support\Facades\DB;
use Webkul\Account\Models\BankStatement;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('accounts');
    $this->company = Company::query()->firstOrFail();
    $this->actingAs(User::query()->firstOrFail());
});

function calculationReconciliation(array $overrides = []): BankReconciliation
{
    return BankReconciliation::query()->create([
        ...validBankReconciliationAttributes(),
        'period_start'     => '2026-09-01',
        'period_end'       => '2026-09-30',
        'statement_date'   => '2026-09-30',
        'balance_start'    => '0.0000',
        'balance_end_real' => '0.0000',
        ...$overrides,
    ]);
}

function postCalculationAmount(BankReconciliation $reconciliation, string $date, string $amount, ?int $accountId = null, string $state = 'posted'): array
{
    $accountId ??= $reconciliation->liquidity_account_id;
    $counterpartId = DB::table('accounts_accounts')
        ->where('id', '<>', $accountId)
        ->value('id');
    $moveId = DB::table('accounts_account_moves')->insertGetId([
        'journal_id'  => $reconciliation->journal_id,
        'company_id'  => $reconciliation->company_id,
        'currency_id' => $reconciliation->currency_id,
        'creator_id'  => auth()->id(),
        'name'        => fake()->unique()->bothify('CALC/####'),
        'state'       => $state,
        'move_type'   => 'entry',
        'date'        => $date,
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);
    $isDebit = bccomp($amount, '0', 4) > 0;
    $line = [
        'move_id'             => $moveId,
        'journal_id'          => $reconciliation->journal_id,
        'company_id'          => $reconciliation->company_id,
        'company_currency_id' => $reconciliation->currency_id,
        'currency_id'         => $reconciliation->currency_id,
        'creator_id'          => auth()->id(),
        'date'                => $date,
        'debit'               => $isDebit ? $amount : '0.0000',
        'credit'              => $isDebit ? '0.0000' : ltrim($amount, '-'),
        'balance'             => $amount,
        'created_at'          => now(),
        'updated_at'          => now(),
    ];
    $lineId = DB::table('accounts_account_move_lines')->insertGetId([
        ...$line,
        'account_id' => $accountId,
    ]);
    DB::table('accounts_account_move_lines')->insert([
        ...$line,
        'account_id' => $counterpartId,
        'debit'      => $line['credit'],
        'credit'     => $line['debit'],
        'balance'    => bcmul($amount, '-1', 4),
    ]);

    return ['move_id' => $moveId, 'line_id' => $lineId, 'counterpart_id' => $counterpartId];
}

function addCalculationItem(BankReconciliation $reconciliation, string $classification, string $amount, array $source, string $status = 'open'): ReconcilingItem
{
    return ReconcilingItem::query()->create([
        'company_id'        => $reconciliation->company_id,
        'reconciliation_id' => $reconciliation->id,
        'move_line_id'      => $source['move_line_id'] ?? null,
        'statement_line_id' => $source['statement_line_id'] ?? null,
        'side'              => 'bank',
        'classification'    => $classification,
        'amount'            => $amount,
        'origin_period_end' => $reconciliation->period_end,
        'status'            => $status,
        'resolved_at'       => in_array($status, ['resolved', 'excluded']) ? now() : null,
    ]);
}

it('derives opening and closing book balances only from posted in-scope liquidity lines', function (): void {
    $reconciliation = calculationReconciliation(['balance_end_real' => '1200.0000']);
    postCalculationAmount($reconciliation, '2026-08-31', '1000.0000');
    postCalculationAmount($reconciliation, '2026-09-15', '200.0000');
    postCalculationAmount($reconciliation, '2026-10-01', '700.0000');
    $wrongAccount = DB::table('accounts_accounts')->where('id', '<>', $reconciliation->liquidity_account_id)->value('id');
    postCalculationAmount($reconciliation, '2026-09-15', '300.0000', $wrongAccount);
    postCalculationAmount($reconciliation, '2026-09-15', '400.0000', state: 'draft');

    $result = app(ReconciliationCalculator::class)->calculate($reconciliation);

    expect($result)->toMatchArray([
        'gl_balance_start'      => '1000.00',
        'gl_balance_end'        => '1200.00',
        'adjusted_bank_balance' => '1200.00',
        'adjusted_book_balance' => '1200.00',
        'difference'            => '0.00',
        'is_balanced'           => true,
    ]);
});

it('adjusts the bank side for sourced timing differences including carried-forward items', function (): void {
    $reconciliation = calculationReconciliation(['balance_start' => '900.0000', 'balance_end_real' => '900.0000']);
    postCalculationAmount($reconciliation, '2026-08-31', '900.0000');
    $payment = postCalculationAmount($reconciliation, '2026-09-10', '-100.0000');
    $deposit = postCalculationAmount($reconciliation, '2026-09-20', '300.0000');
    addCalculationItem($reconciliation, 'outstanding_payment', '100.0000', ['move_line_id' => $payment['line_id']], 'carried_forward');
    addCalculationItem($reconciliation, 'deposit_in_transit', '300.0000', ['move_line_id' => $deposit['line_id']]);
    addCalculationItem($reconciliation, 'deposit_in_transit', '999.0000', ['move_line_id' => $deposit['line_id']], 'resolved');

    $result = app(ReconciliationCalculator::class)->calculate($reconciliation);

    expect($result)->toMatchArray([
        'deposits_in_transit'   => '300.00',
        'outstanding_payments'  => '100.00',
        'adjusted_bank_balance' => '1100.00',
        'adjusted_book_balance' => '1100.00',
        'difference'            => '0.00',
        'is_balanced'           => true,
    ])->and($result['components']['outstanding_payments']['item_ids'])->toHaveCount(1);
});

it('applies signed bank errors and does not double-count posted book adjustments', function (): void {
    $reconciliation = calculationReconciliation();
    postCalculationAmount($reconciliation, '2026-08-31', '990.0000');
    $adjustment = postCalculationAmount($reconciliation, '2026-09-30', '10.0000');
    $statement = BankStatement::query()->create([
        'company_id'       => $reconciliation->company_id,
        'journal_id'       => $reconciliation->journal_id,
        'date'             => '2026-09-30',
        'name'             => 'CALC-BANK-ERROR',
        'balance_start'    => '0.0000',
        'balance_end'      => '990.0040',
        'balance_end_real' => '990.0040',
        'is_completed'     => false,
    ]);
    $reconciliation->update(['statement_id' => $statement->id, 'balance_end_real' => '990.0040']);
    $statementLine = StatementLine::query()->create([
        'statement_id'       => $statement->id,
        'company_id'         => $reconciliation->company_id,
        'journal_id'         => $reconciliation->journal_id,
        'currency_id'        => $reconciliation->currency_id,
        'transaction_date'   => '2026-09-30',
        'amount'             => '-10.0000',
        'import_status'      => 'accepted',
        'source_fingerprint' => hash('sha256', 'bank-error'),
    ]);
    addCalculationItem($reconciliation, 'bank_error', '10.0000', ['statement_line_id' => $statementLine->id]);
    ReconAdjustment::query()->create([
        'reconciliation_id'      => $reconciliation->id,
        'type'                   => 'interest',
        'amount'                 => '10.0000',
        'counterpart_account_id' => $adjustment['counterpart_id'],
        'move_id'                => $adjustment['move_id'],
        'posted_at'              => now(),
    ]);

    $result = app(ReconciliationCalculator::class)->calculate($reconciliation->refresh());

    expect($result)->toMatchArray([
        'bank_errors'            => '10.00',
        'adjustments_total'      => '10.00',
        'adjusted_bank_balance'  => '1000.00',
        'adjusted_book_balance'  => '1000.00',
        'difference'             => '0.00',
        'is_balanced'            => true,
    ])->and(implode(' ', app(FinalizationService::class)->completionCheck($reconciliation->refresh())['issues']))
        ->not->toContain('unmatched');
});

it('blocks unsupported or unsourced reconciling items from changing the equation', function (): void {
    $reconciliation = calculationReconciliation(['balance_end_real' => '-500.0000']);
    postCalculationAmount($reconciliation, '2026-08-31', '-500.0000');
    $item = addCalculationItem($reconciliation, 'deposit_in_transit', '100.0000', []);

    $result = app(ReconciliationCalculator::class)->calculate($reconciliation);

    expect($result['adjusted_bank_balance'])->toBe('-500.00')
        ->and($result['adjusted_book_balance'])->toBe('-500.00')
        ->and($result['is_balanced'])->toBeTrue()
        ->and($result['blocking_item_ids'])->toBe([$item->id]);
});

it('does not persist calculated balances during reads or completion checks', function (): void {
    $reconciliation = calculationReconciliation(['difference' => '99.0000']);

    app(FinalizationService::class)->completionCheck($reconciliation);

    expect($reconciliation->refresh()->difference)->toBe('99.0000');
});

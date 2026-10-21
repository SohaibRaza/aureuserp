<?php

require_once __DIR__.'/ReconTestSupport.php';

use App\Models\Recon\BankOpeningBalance;
use App\Models\Recon\BankReconciliation;
use App\Services\Recon\BankOpeningBalanceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Move;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('accounts');
    $this->company = Company::query()->firstOrFail();
    $this->actingAs(User::query()->firstOrFail());
    config()->set('reconciliation.financial_mutations_enabled', true);
    config()->set('reconciliation.require_company_rollout', false);
});

function openingBalanceEquityAccount(): Account
{
    return Account::query()
        ->where('account_type', AccountType::EQUITY)
        ->where('deprecated', false)
        ->firstOrFail();
}

it('posts a balanced, auditable Bank versus Equity opening entry', function (): void {
    $journal = testBankJournal();

    $opening = app(BankOpeningBalanceService::class)->post($this->company->id, [
        'journal_id'             => $journal->id,
        'counterpart_account_id' => openingBalanceEquityAccount()->id,
        'effective_date'         => '2026-08-31',
        'amount'                 => '10000.0000',
        'notes'                  => 'Bank statement closing balance at 31 Aug 2026',
    ]);

    expect($opening)->toBeInstanceOf(BankOpeningBalance::class)
        ->and($opening->amount)->toBe('10000.0000');

    $move = Move::query()->with('lines')->findOrFail($opening->move_id);
    expect($move->state->value)->toBe('posted')
        ->and($move->date->toDateString())->toBe('2026-08-31')
        ->and((float) $move->lines->sum('debit'))->toBe(10000.0)
        ->and((float) $move->lines->sum('credit'))->toBe(10000.0);

    $bankLine = $move->lines->firstWhere('account_id', $journal->default_account_id);
    expect((string) $bankLine->debit)->toBe('10000.0000')
        ->and((string) $bankLine->credit)->toBe('0.0000');
});

it('rejects a second opening baseline for the same bank account', function (): void {
    $journal = testBankJournal();
    $payload = [
        'journal_id'             => $journal->id,
        'counterpart_account_id' => openingBalanceEquityAccount()->id,
        'effective_date'         => '2026-08-31',
        'amount'                 => '10000',
        'notes'                  => 'Migration baseline',
    ];

    app(BankOpeningBalanceService::class)->post($this->company->id, $payload);

    expect(fn () => app(BankOpeningBalanceService::class)->post($this->company->id, $payload))
        ->toThrow(InvalidArgumentException::class, 'opening balance already exists');
});

it('refuses an opening balance dated inside the first reconciliation period', function (): void {
    $journal = testBankJournal();
    BankReconciliation::query()->create([
        ...validBankReconciliationAttributes($journal),
        'period_start' => '2026-09-01',
        'period_end'   => '2026-09-30',
    ]);

    expect(fn () => app(BankOpeningBalanceService::class)->post($this->company->id, [
        'journal_id'             => $journal->id,
        'counterpart_account_id' => openingBalanceEquityAccount()->id,
        'effective_date'         => '2026-09-01',
        'amount'                 => '10000',
        'notes'                  => 'Incorrect in-period baseline',
    ]))->toThrow(InvalidArgumentException::class, 'before the first reconciliation period');
});

it('does not overlay a Bank GL that already has posted activity', function (): void {
    $journal = testBankJournal();
    $moveId = DB::table('accounts_account_moves')->insertGetId([
        'journal_id'  => $journal->id,
        'company_id'  => $this->company->id,
        'currency_id' => $this->company->currency_id,
        'date'        => '2026-08-30',
        'move_type'   => 'entry',
        'state'       => 'posted',
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);
    DB::table('accounts_account_move_lines')->insert([
        'move_id'             => $moveId,
        'journal_id'          => $journal->id,
        'company_id'          => $this->company->id,
        'company_currency_id' => $this->company->currency_id,
        'currency_id'         => $this->company->currency_id,
        'account_id'          => $journal->default_account_id,
        'date'                => '2026-08-30',
        'debit'               => '1.0000',
        'credit'              => '0.0000',
        'balance'             => '1.0000',
        'amount_currency'     => '1.0000',
        'created_at'          => now(),
        'updated_at'          => now(),
    ]);

    expect(fn () => app(BankOpeningBalanceService::class)->post($this->company->id, [
        'journal_id'             => $journal->id,
        'counterpart_account_id' => openingBalanceEquityAccount()->id,
        'effective_date'         => '2026-08-31',
        'amount'                 => '10000',
        'notes'                  => 'Would duplicate existing Bank GL',
    ]))->toThrow(InvalidArgumentException::class, 'already has posted activity');
});

it('posts a negative opening balance as a bank overdraft', function (): void {
    $journal = testBankJournal();

    $opening = app(BankOpeningBalanceService::class)->post($this->company->id, [
        'journal_id'             => $journal->id,
        'counterpart_account_id' => openingBalanceEquityAccount()->id,
        'effective_date'         => '2026-08-31',
        'amount'                 => '-750.0000',
        'notes'                  => 'Opening overdraft per bank statement',
    ]);

    $bankLine = $opening->move()->with('lines')->firstOrFail()->lines
        ->firstWhere('account_id', $journal->default_account_id);

    expect((string) $bankLine->debit)->toBe('0.0000')
        ->and((string) $bankLine->credit)->toBe('750.0000')
        ->and((string) $bankLine->balance)->toBe('-750.0000');
});

it('enforces rollout safety inside the opening balance service', function (): void {
    config()->set('reconciliation.financial_mutations_enabled', false);
    $journal = testBankJournal();

    expect(fn () => app(BankOpeningBalanceService::class)->post($this->company->id, [
        'journal_id'             => $journal->id,
        'counterpart_account_id' => openingBalanceEquityAccount()->id,
        'effective_date'         => '2026-08-31',
        'amount'                 => '100.0000',
        'notes'                  => 'Must be blocked',
    ]))->toThrow(InvalidArgumentException::class, 'temporarily disabled');
});

it('requires the dedicated opening balance posting permission', function (): void {
    $this->actingAs(User::factory()->create());

    expect(fn () => app(BankOpeningBalanceService::class)->post($this->company->id, [
        'journal_id'             => testBankJournal()->id,
        'counterpart_account_id' => openingBalanceEquityAccount()->id,
        'effective_date'         => '2026-08-31',
        'amount'                 => '100.0000',
        'notes'                  => 'Must be blocked',
    ]))->toThrow(AuthorizationException::class);
});

it('rejects cash journals because cash-on-hand requires a cash-count workflow', function (): void {
    $bankJournal = testBankJournal();
    $cashJournal = $bankJournal->replicate();
    $cashJournal->forceFill([
        'name' => 'Opening cash '.fake()->unique()->numerify('####'),
        'code' => fake()->unique()->bothify('CSH##'),
        'sort' => fake()->unique()->bothify('CSH##'),
        'type' => 'cash',
    ])->save();

    expect(fn () => app(BankOpeningBalanceService::class)->post($this->company->id, [
        'journal_id'             => $cashJournal->id,
        'counterpart_account_id' => openingBalanceEquityAccount()->id,
        'effective_date'         => '2026-08-31',
        'amount'                 => '100.0000',
        'notes'                  => 'Invalid cash baseline',
    ]))->toThrow(InvalidArgumentException::class, 'only be posted for bank journals');
});

it('keeps opening balance evidence immutable', function (): void {
    $journal = testBankJournal();
    $opening = app(BankOpeningBalanceService::class)->post($this->company->id, [
        'journal_id'             => $journal->id,
        'counterpart_account_id' => openingBalanceEquityAccount()->id,
        'effective_date'         => '2026-08-31',
        'amount'                 => '100.0000',
        'notes'                  => 'Immutable baseline',
    ]);

    expect(fn () => $opening->update(['amount' => '200.0000']))
        ->toThrow(RuntimeException::class, 'immutable');
    expect(fn () => DB::transaction(fn () => DB::table('recon_bank_opening_balances')->where('id', $opening->id)->update(['amount' => '200.0000'])))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('recon_bank_opening_balances')->where('id', $opening->id)->delete()))
        ->toThrow(QueryException::class);
    $migration = require base_path('plugins/webkul/accounts/database/migrations/2026_09_11_000001_create_recon_bank_opening_balances_table.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward recovery');
    expect(DB::table('recon_bank_opening_balances')->where('id', $opening->id)->exists())->toBeTrue();

});

<?php

require_once __DIR__.'/ReconTestSupport.php';

use App\Models\Recon\BankReconciliation;
use App\Models\Recon\StatementLine;
use App\Services\Recon\MatchingEngine;
use App\Services\Recon\MatchService;
use Illuminate\Support\Facades\DB;
use Webkul\Account\Models\BankStatement;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('accounts');
    $this->company = Company::query()->firstOrFail();
    $this->actingAs(User::query()->firstOrFail());
    config(['reconciliation.financial_mutations_enabled' => true]);
    config(['reconciliation.require_company_rollout' => false]);
});

function matchingReconciliation(): BankReconciliation
{
    $reconciliation = BankReconciliation::query()->create([
        ...validBankReconciliationAttributes(),
        'period_start'   => '2026-09-01',
        'period_end'     => '2026-09-30',
        'statement_date' => '2026-09-30',
    ]);
    $statement = BankStatement::query()->create([
        'company_id'       => $reconciliation->company_id,
        'journal_id'       => $reconciliation->journal_id,
        'name'             => fake()->unique()->bothify('MATCH/####'),
        'date'             => '2026-09-30',
        'balance_start'    => '0.0000',
        'balance_end'      => '0.0000',
        'balance_end_real' => '0.0000',
        'is_completed'     => false,
    ]);
    $reconciliation->update(['statement_id' => $statement->id]);

    return $reconciliation->refresh();
}

function matchingStatementLine(BankReconciliation $reconciliation, string $amount, string $date = '2026-09-15'): StatementLine
{
    return StatementLine::query()->create([
        'statement_id'       => $reconciliation->statement_id,
        'company_id'         => $reconciliation->company_id,
        'journal_id'         => $reconciliation->journal_id,
        'currency_id'        => $reconciliation->currency_id,
        'transaction_date'   => $date,
        'amount'             => $amount,
        'amount_residual'    => $amount,
        'import_status'      => 'accepted',
        'source_fingerprint' => hash('sha256', fake()->unique()->uuid()),
        'created_at'         => now()->subYears(2),
        'updated_at'         => now()->subYears(2),
    ]);
}

function matchingMoveLine(BankReconciliation $reconciliation, string $balance, string $date = '2026-09-15', ?int $accountId = null, string $state = 'posted'): int
{
    $accountId ??= $reconciliation->liquidity_account_id;
    $counterpartId = DB::table('accounts_accounts')->where('id', '<>', $accountId)->value('id');
    $moveId = DB::table('accounts_account_moves')->insertGetId([
        'journal_id'  => $reconciliation->journal_id,
        'company_id'  => $reconciliation->company_id,
        'currency_id' => $reconciliation->currency_id,
        'creator_id'  => auth()->id(),
        'name'        => fake()->unique()->bothify('MATCH-MOVE/####'),
        'state'       => $state,
        'move_type'   => 'entry',
        'date'        => $date,
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);
    $positive = bccomp($balance, '0', 4) > 0;
    $debit = $positive ? $balance : '0.0000';
    $credit = $positive ? '0.0000' : ltrim($balance, '-');
    $lineId = DB::table('accounts_account_move_lines')->insertGetId([
        'move_id'             => $moveId,
        'journal_id'          => $reconciliation->journal_id,
        'company_id'          => $reconciliation->company_id,
        'company_currency_id' => $reconciliation->currency_id,
        'account_id'          => $accountId,
        'currency_id'         => $reconciliation->currency_id,
        'creator_id'          => auth()->id(),
        'date'                => $date,
        'debit'               => $debit,
        'credit'              => $credit,
        'balance'             => $balance,
        'reconciled'          => false,
        'created_at'          => now(),
        'updated_at'          => now(),
    ]);
    DB::table('accounts_account_move_lines')->insert([
        'move_id'             => $moveId,
        'journal_id'          => $reconciliation->journal_id,
        'company_id'          => $reconciliation->company_id,
        'company_currency_id' => $reconciliation->currency_id,
        'account_id'          => $counterpartId,
        'currency_id'         => $reconciliation->currency_id,
        'creator_id'          => auth()->id(),
        'date'                => $date,
        'debit'               => $credit,
        'credit'              => $debit,
        'balance'             => bcmul($balance, '-1', 4),
        'reconciled'          => false,
        'created_at'          => now(),
        'updated_at'          => now(),
    ]);

    return $lineId;
}

it('only exposes eligible posted liquidity lines and preserves transaction dates for scoring', function (): void {
    $reconciliation = matchingReconciliation();
    $bankLine = matchingStatementLine($reconciliation, '100.0000');
    $liquidityLine = matchingMoveLine($reconciliation, '100.0000');
    matchingMoveLine($reconciliation, '200.0000', '2026-09-15', DB::table('accounts_accounts')->where('account_type', 'asset_receivable')->value('id'));
    matchingMoveLine($reconciliation, '300.0000', '2026-10-01');
    matchingMoveLine($reconciliation, '400.0000', '2026-09-15', state: 'draft');

    $candidates = app(MatchingEngine::class)->ledgerCandidates($reconciliation)->get();
    $score = app(MatchingEngine::class)->scorePair($bankLine, $candidates->first());

    expect($candidates->pluck('id')->all())->toBe([$liquidityLine])
        ->and($score['reasons'])->toContain('date-proximity');
});

it('matches same-direction direct-bank activity and exposes the available allocation magnitudes', function (): void {
    $reconciliation = matchingReconciliation();
    $bankLine = matchingStatementLine($reconciliation, '100.0000');
    $ledgerLine = matchingMoveLine($reconciliation, '100.0000');
    $engine = app(MatchingEngine::class);

    $check = $engine->validateBasket($reconciliation, [$bankLine->id], [$ledgerLine]);

    expect($engine->bankRemainingFor($reconciliation, $bankLine->id))->toBe('100.0000')
        ->and($engine->ledgerRemainingFor($reconciliation, $ledgerLine))->toBe('100.0000')
        ->and($check['bank_total'])->toBe('100.0000')
        ->and($check['ledger_total'])->toBe('100.0000')
        ->and($check['variance'])->toBe('0.0000')
        ->and($check['balanced'])->toBeTrue();
});

it('keeps weak and ambiguous matches as proposals and never auto-confirms an equal tie', function (): void {
    $reconciliation = matchingReconciliation();
    matchingStatementLine($reconciliation, '100.0000');
    matchingMoveLine($reconciliation, '100.0000');
    matchingMoveLine($reconciliation, '100.0000');

    $stats = app(MatchingEngine::class)->autoMatch($reconciliation, confirmStrong: false);

    expect($stats)->toBe(['proposed' => 0, 'confirmed' => 0]);
});

it('stores exact partial allocations, prevents reuse, and restores the bank residual on unmatch', function (): void {
    $reconciliation = matchingReconciliation();
    $bankLine = matchingStatementLine($reconciliation, '60.0000');
    $ledgerLine = matchingMoveLine($reconciliation, '100.0000');
    $service = app(MatchService::class);

    $first = $service->createMatch($reconciliation, [$bankLine->id], [$ledgerLine], allocations: [
        'bank'   => [$bankLine->id => '60.0000'],
        'ledger' => [$ledgerLine => '60.0000'],
    ]);

    expect($first->lines()->where('lineable_id', $ledgerLine)->value('amount_applied'))->toBe('60.0000')
        ->and(StatementLine::query()->find($bankLine->id)->is_reconciled)->toBeTrue()
        ->and(DB::table('accounts_partial_reconciles')->where('recon_match_id', $first->id)->exists())->toBeFalse()
        ->and(app(MatchingEngine::class)->ledgerCandidates($reconciliation)->where('line.id', $ledgerLine)->value('remaining_amount'))->toBe('40.0000');

    $secondBank = matchingStatementLine($reconciliation, '40.0000');
    $second = $service->createMatch($reconciliation, [$secondBank->id], [$ledgerLine]);
    expect($second->status)->toBe('confirmed');

    $service->unmatch($first, 'Undoing the first partial match');
    expect(StatementLine::query()->find($bankLine->id)->is_reconciled)->toBeFalse()
        ->and(StatementLine::query()->find($bankLine->id)->amount_residual)->toBe('60.0000');
});

it('rejects out-of-scope manual selections before creating a match', function (): void {
    $reconciliation = matchingReconciliation();
    $bankLine = matchingStatementLine($reconciliation, '50.0000');
    $otherAccount = DB::table('accounts_accounts')->where('id', '<>', $reconciliation->liquidity_account_id)->value('id');
    $ledgerLine = matchingMoveLine($reconciliation, '50.0000', accountId: $otherAccount);

    expect(fn () => app(MatchService::class)->createMatch($reconciliation, [$bankLine->id], [$ledgerLine]))
        ->toThrow(InvalidArgumentException::class);
});

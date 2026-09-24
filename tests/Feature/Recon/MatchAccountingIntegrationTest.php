<?php

require_once __DIR__.'/ReconTestSupport.php';

use App\Models\Recon\BankReconciliation;
use App\Models\Recon\ReconMatch;
use App\Models\Recon\StatementLine;
use App\Services\Recon\MatchingEngine;
use App\Services\Recon\MatchService;
use Illuminate\Support\Facades\DB;
use Webkul\Account\Models\BankStatement;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Models\PartialReconcile;
use Webkul\Account\Services\Reconciler;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('accounts');
    $this->company = Company::query()->firstOrFail();
    $this->actingAs(User::query()->firstOrFail());
    config(['reconciliation.financial_mutations_enabled' => true]);
    config(['reconciliation.require_company_rollout' => false]);
});

function u13Reconciliation(array $overrides = []): BankReconciliation
{
    $reconciliation = BankReconciliation::query()->create([
        ...validBankReconciliationAttributes(),
        'period_start'   => '2026-09-01',
        'period_end'     => '2026-09-30',
        'statement_date' => '2026-09-30',
        ...$overrides,
    ]);
    $statement = BankStatement::query()->create([
        'company_id'       => $reconciliation->company_id,
        'journal_id'       => $reconciliation->journal_id,
        'name'             => fake()->unique()->bothify('U13/####'),
        'date'             => '2026-09-30',
        'balance_start'    => '0.0000',
        'balance_end'      => '0.0000',
        'balance_end_real' => '0.0000',
        'is_completed'     => false,
    ]);
    $reconciliation->update(['statement_id' => $statement->id]);

    return $reconciliation->refresh();
}

function u13BankLine(BankReconciliation $reconciliation, string $amount, array $overrides = []): StatementLine
{
    return StatementLine::query()->create([
        'statement_id'       => $reconciliation->statement_id,
        'company_id'         => $reconciliation->company_id,
        'journal_id'         => $reconciliation->journal_id,
        'currency_id'        => $reconciliation->currency_id,
        'transaction_date'   => '2026-09-15',
        'amount'             => $amount,
        'amount_residual'    => $amount,
        'import_status'      => 'accepted',
        'source_fingerprint' => hash('sha256', fake()->unique()->uuid()),
        ...$overrides,
    ]);
}

function u13MoveLine(BankReconciliation $reconciliation, string $balance, array $overrides = []): int
{
    $accountId = $overrides['account_id'] ?? $reconciliation->liquidity_account_id;
    $companyId = $overrides['company_id'] ?? $reconciliation->company_id;
    $currencyId = $overrides['currency_id'] ?? $reconciliation->currency_id;
    $date = $overrides['date'] ?? '2026-09-15';
    $state = $overrides['state'] ?? 'posted';
    unset($overrides['account_id'], $overrides['company_id'], $overrides['currency_id'], $overrides['date'], $overrides['state']);

    $counterpartId = DB::table('accounts_accounts')->where('id', '<>', $accountId)->value('id');
    $moveId = DB::table('accounts_account_moves')->insertGetId([
        'journal_id'  => $reconciliation->journal_id,
        'company_id'  => $companyId,
        'currency_id' => $currencyId,
        'creator_id'  => auth()->id(),
        'name'        => fake()->unique()->bothify('U13-MOVE/####'),
        'state'       => $state,
        'move_type'   => 'entry',
        'date'        => $date,
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);
    $positive = bccomp($balance, '0', 4) > 0;
    $lineId = DB::table('accounts_account_move_lines')->insertGetId(array_merge([
        'move_id'             => $moveId,
        'journal_id'          => $reconciliation->journal_id,
        'company_id'          => $companyId,
        'company_currency_id' => $currencyId,
        'account_id'          => $accountId,
        'currency_id'         => $currencyId,
        'creator_id'          => auth()->id(),
        'date'                => $date,
        'parent_state'        => 'posted',
        'debit'               => $positive ? $balance : '0.0000',
        'credit'              => $positive ? '0.0000' : ltrim($balance, '-'),
        'balance'             => $balance,
        'amount_residual'     => $balance,
        'reconciled'          => false,
        'created_at'          => now(),
        'updated_at'          => now(),
    ], $overrides));
    DB::table('accounts_account_move_lines')->insert([
        'move_id'             => $moveId,
        'journal_id'          => $reconciliation->journal_id,
        'company_id'          => $companyId,
        'company_currency_id' => $currencyId,
        'account_id'          => $counterpartId,
        'currency_id'         => $currencyId,
        'creator_id'          => auth()->id(),
        'date'                => $date,
        'parent_state'        => 'posted',
        'debit'               => $positive ? '0.0000' : ltrim($balance, '-'),
        'credit'              => $positive ? $balance : '0.0000',
        'balance'             => bcmul($balance, '-1', 4),
        'reconciled'          => false,
        'created_at'          => now(),
        'updated_at'          => now(),
    ]);

    return $lineId;
}

it('records accounting provenance and hides a fully matched ledger line from both candidate screens', function (): void {
    $reconciliation = u13Reconciliation();
    $bankLine = u13BankLine($reconciliation, '100.0000');
    $ledgerId = u13MoveLine($reconciliation, '100.0000');

    $match = app(MatchService::class)->createMatch($reconciliation, [$bankLine->id], [$ledgerId]);

    expect($match->accounting_mode)->toBe('allocation_only')
        ->and($match->accounting_partial_ids)->toBe([])
        ->and($match->accounting_applied_at)->not->toBeNull()
        ->and($match->accounting_applied_by)->not->toBeNull()
        ->and($match->ledger_previous_residuals['ledger'][$ledgerId]['amount_residual'])->not->toBeNull()
        ->and($match->ledgerLines()->where('lineable_id', $ledgerId)->value('previous_ledger_residual'))->not->toBeNull();

    expect(StatementLine::query()->find($bankLine->id)->is_reconciled)->toBeTrue()
        ->and(app(MatchingEngine::class)->ledgerCandidates($reconciliation)->where('line.id', $ledgerId)->exists())->toBeFalse()
        ->and(app(MatchingEngine::class)->accountingOpenCandidates($reconciliation)->where('line.id', $ledgerId)->exists())->toBeFalse()
        ->and(MoveLine::query()->withoutGlobalScopes()->openForBankReconciliation()->whereKey($ledgerId)->exists())->toBeFalse()
        ->and(DB::table('accounts_partial_reconciles')->where('recon_match_id', $match->id)->exists())->toBeFalse();

    $freshLedger = MoveLine::query()->withoutGlobalScopes()->findOrFail($ledgerId);
    expect($freshLedger->statement_line_id)->toBe($bankLine->id)
        ->and($freshLedger->matching_number)->toBe(MoveLine::bankMatchNumber($match->id));
});

it('updates both residuals on partial matches and restores them exactly on unmatch', function (): void {
    $reconciliation = u13Reconciliation();
    $bankLine = u13BankLine($reconciliation, '100.0000');
    $ledgerId = u13MoveLine($reconciliation, '100.0000');

    $match = app(MatchService::class)->createMatch($reconciliation, [$bankLine->id], [$ledgerId], allocations: [
        'bank'   => [$bankLine->id => '60.0000'],
        'ledger' => [$ledgerId => '60.0000'],
    ]);

    expect(StatementLine::query()->find($bankLine->id)->amount_residual)->toBe('40.0000')
        ->and(app(MatchingEngine::class)->ledgerCandidates($reconciliation)->where('line.id', $ledgerId)->value('remaining_amount'))->toBe('40.0000')
        ->and(MoveLine::query()->withoutGlobalScopes()->openForBankReconciliation()->whereKey($ledgerId)->exists())->toBeTrue()
        ->and(MoveLine::query()->withoutGlobalScopes()->find($ledgerId)->statement_line_id)->toBeNull();

    app(MatchService::class)->unmatch($match, 'Correcting an incorrect allocation');

    expect(StatementLine::query()->find($bankLine->id)->amount_residual)->toBe('100.0000')
        ->and(StatementLine::query()->find($bankLine->id)->is_reconciled)->toBeFalse()
        ->and(app(MatchingEngine::class)->ledgerCandidates($reconciliation)->where('line.id', $ledgerId)->value('remaining_amount'))->toBe('100.0000')
        ->and($match->refresh()->status)->toBe('reversed')
        ->and($match->refresh()->accounting_reversed_at)->not->toBeNull();
});

it('balances split matches across multiple ledger lines exactly', function (): void {
    $reconciliation = u13Reconciliation();
    $bankLine = u13BankLine($reconciliation, '100.0000');
    $firstLedger = u13MoveLine($reconciliation, '60.0000');
    $secondLedger = u13MoveLine($reconciliation, '40.0000');

    $match = app(MatchService::class)->createMatch($reconciliation, [$bankLine->id], [$firstLedger, $secondLedger]);

    expect(StatementLine::query()->find($bankLine->id)->is_reconciled)->toBeTrue()
        ->and(app(MatchingEngine::class)->ledgerCandidates($reconciliation)->whereIn('line.id', [$firstLedger, $secondLedger])->exists())->toBeFalse();

    app(MatchService::class)->unmatch($match, 'Correcting an incorrect split');

    expect(StatementLine::query()->find($bankLine->id)->is_reconciled)->toBeFalse()
        ->and(app(MatchingEngine::class)->ledgerCandidates($reconciliation)->where('line.id', $firstLedger)->value('remaining_amount'))->toBe('60.0000')
        ->and(app(MatchingEngine::class)->ledgerCandidates($reconciliation)->where('line.id', $secondLedger)->value('remaining_amount'))->toBe('40.0000');
});

it('never exposes or modifies AR/AP residuals outside the accounting reconciler', function (): void {
    $reconciliation = u13Reconciliation();
    $receivableAccount = DB::table('accounts_accounts')->where('account_type', 'asset_receivable')->value('id');
    $receivableLine = u13MoveLine($reconciliation, '50.0000', ['account_id' => $receivableAccount]);
    $beforeResidual = (string) MoveLine::query()->withoutGlobalScopes()->find($receivableLine)->amount_residual;

    $bankLine = u13BankLine($reconciliation, '50.0000');

    expect(fn () => app(MatchService::class)->createMatch($reconciliation, [$bankLine->id], [$receivableLine]))
        ->toThrow(InvalidArgumentException::class)
        ->and((string) MoveLine::query()->withoutGlobalScopes()->find($receivableLine)->amount_residual)->toBe($beforeResidual)
        ->and(app(MatchingEngine::class)->ledgerCandidates($reconciliation)->where('line.id', $receivableLine)->exists())->toBeFalse();
});

it('cannot reverse unrelated accounting reconciliations on unmatch', function (): void {
    $reconciliation = u13Reconciliation();

    $debitLine = u13MoveLine($reconciliation, '80.0000');
    $creditLine = u13MoveLine($reconciliation, '-80.0000');
    app(Reconciler::class)->reconcile(MoveLine::query()->withoutGlobalScopes()->whereKey([$debitLine, $creditLine])->get());
    $unrelatedPartialId = PartialReconcile::query()->latest('id')->value('id');
    expect($unrelatedPartialId)->not->toBeNull();

    $bankLine = u13BankLine($reconciliation, '50.0000');
    $ledgerId = u13MoveLine($reconciliation, '50.0000');
    $match = app(MatchService::class)->createMatch($reconciliation, [$bankLine->id], [$ledgerId]);
    app(MatchService::class)->unmatch($match, 'Correcting the bank linkage');

    expect(PartialReconcile::query()->whereKey($unrelatedPartialId)->exists())->toBeTrue();
});

it('rejects wrong company, currency, account, and period selections', function (): void {
    $reconciliation = u13Reconciliation();
    $bankLine = u13BankLine($reconciliation, '50.0000');

    $otherCompanyId = DB::table('companies')->insertGetId([
        'currency_id' => $reconciliation->currency_id,
        'creator_id'  => DB::table('users')->value('id'),
        'partner_id'  => $this->company->partner_id,
        'name'        => 'U13 Other Company',
        'company_id'  => fake()->uuid(),
        'is_active'   => true,
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);
    $foreignLedger = u13MoveLine($reconciliation, '50.0000', ['company_id' => $otherCompanyId]);
    expect(fn () => app(MatchService::class)->createMatch($reconciliation, [$bankLine->id], [$foreignLedger]))
        ->toThrow(InvalidArgumentException::class);

    $otherCurrencyId = DB::table('currencies')->where('id', '<>', $reconciliation->currency_id)->value('id');
    $foreignCurrencyLedger = u13MoveLine($reconciliation, '50.0000', ['currency_id' => $otherCurrencyId]);
    expect(fn () => app(MatchService::class)->createMatch($reconciliation, [$bankLine->id], [$foreignCurrencyLedger]))
        ->toThrow(InvalidArgumentException::class);

    $otherAccount = DB::table('accounts_accounts')->where('id', '<>', $reconciliation->liquidity_account_id)->value('id');
    $otherAccountLedger = u13MoveLine($reconciliation, '50.0000', ['account_id' => $otherAccount]);
    expect(fn () => app(MatchService::class)->createMatch($reconciliation, [$bankLine->id], [$otherAccountLedger]))
        ->toThrow(InvalidArgumentException::class);

    $futureLedger = u13MoveLine($reconciliation, '50.0000', ['date' => '2026-10-15']);
    expect(fn () => app(MatchService::class)->createMatch($reconciliation, [$bankLine->id], [$futureLedger]))
        ->toThrow(InvalidArgumentException::class);

    $otherJournalId = DB::table('accounts_journals')->where('id', '<>', $reconciliation->journal_id)->value('id');
    $foreignBank = u13BankLine($reconciliation, '50.0000', ['journal_id' => $otherJournalId]);
    $ledgerId = u13MoveLine($reconciliation, '50.0000');
    expect(fn () => app(MatchService::class)->createMatch($reconciliation, [$foreignBank->id], [$ledgerId]))
        ->toThrow(InvalidArgumentException::class);
});

it('blocks unmatch once the reconciliation is completed', function (): void {
    $reconciliation = u13Reconciliation();
    $bankLine = u13BankLine($reconciliation, '25.0000');
    $ledgerId = u13MoveLine($reconciliation, '25.0000');
    $match = app(MatchService::class)->createMatch($reconciliation, [$bankLine->id], [$ledgerId]);

    $reconciliation->update(['status' => 'completed']);

    expect(fn () => app(MatchService::class)->unmatch($match->refresh()))
        ->toThrow(InvalidArgumentException::class, 'locked');
});

it('raises a safe conflict when the ledger residual was modified externally', function (): void {
    $reconciliation = u13Reconciliation();
    $bankLine = u13BankLine($reconciliation, '70.0000');
    $ledgerId = u13MoveLine($reconciliation, '70.0000');
    $match = app(MatchService::class)->createMatch($reconciliation, [$bankLine->id], [$ledgerId]);

    DB::table('accounts_account_move_lines')->where('id', $ledgerId)->update(['amount_residual' => '-10.0000']);

    expect(fn () => app(MatchService::class)->unmatch($match->refresh(), 'Correcting externally changed linkage'))
        ->toThrow(InvalidArgumentException::class, 'outside this match');
});

it('raises a safe conflict when this match no longer owns the ledger linkage', function (): void {
    $reconciliation = u13Reconciliation();
    $bankLine = u13BankLine($reconciliation, '71.0000');
    $ledgerId = u13MoveLine($reconciliation, '71.0000');
    $match = app(MatchService::class)->createMatch($reconciliation, [$bankLine->id], [$ledgerId]);

    DB::table('accounts_account_move_lines')->where('id', $ledgerId)->update(['matching_number' => 'RB-EXTERNAL']);

    expect(fn () => app(MatchService::class)->unmatch($match->refresh(), 'Correcting externally reassigned linkage'))
        ->toThrow(InvalidArgumentException::class, 'outside this match');
});

it('fails the second of two overlapping confirmations cleanly with no partial rows', function (): void {
    $reconciliation = u13Reconciliation();
    $ledgerId = u13MoveLine($reconciliation, '100.0000');
    $firstBank = u13BankLine($reconciliation, '60.0000');
    $secondBank = u13BankLine($reconciliation, '60.0000');

    $first = app(MatchService::class)->createMatch($reconciliation, [$firstBank->id], [$ledgerId], allocations: [
        'bank'   => [$firstBank->id => '60.0000'],
        'ledger' => [$ledgerId => '60.0000'],
    ]);
    $matchesBefore = ReconMatch::query()->where('reconciliation_id', $reconciliation->id)->count();

    expect(fn () => app(MatchService::class)->createMatch($reconciliation, [$secondBank->id], [$ledgerId], allocations: [
        'bank'   => [$secondBank->id => '60.0000'],
        'ledger' => [$ledgerId => '60.0000'],
    ]))->toThrow(InvalidArgumentException::class);

    expect(ReconMatch::query()->where('reconciliation_id', $reconciliation->id)->count())->toBe($matchesBefore)
        ->and(StatementLine::query()->find($secondBank->id)->is_reconciled)->toBeFalse()
        ->and($first->refresh()->status)->toBe('confirmed');
});

it('leaves no allocation behind when the basket cannot balance', function (): void {
    $reconciliation = u13Reconciliation();
    $bankLine = u13BankLine($reconciliation, '100.0000');
    $ledgerId = u13MoveLine($reconciliation, '40.0000');
    $matchesBefore = ReconMatch::query()->where('reconciliation_id', $reconciliation->id)->count();

    expect(fn () => app(MatchService::class)->createMatch($reconciliation, [$bankLine->id], [$ledgerId]))
        ->toThrow(InvalidArgumentException::class);

    expect(ReconMatch::query()->where('reconciliation_id', $reconciliation->id)->count())->toBe($matchesBefore)
        ->and(StatementLine::query()->find($bankLine->id)->is_reconciled)->toBeFalse();
});

it('consumes a bank line across partial matches exactly once', function (): void {
    $reconciliation = u13Reconciliation();
    $bankLine = u13BankLine($reconciliation, '100.0000');
    $firstLedger = u13MoveLine($reconciliation, '40.0000');
    $secondLedger = u13MoveLine($reconciliation, '60.0000');

    app(MatchService::class)->createMatch($reconciliation, [$bankLine->id], [$firstLedger], allocations: [
        'bank' => [$bankLine->id => '40.0000'], 'ledger' => [$firstLedger => '40.0000'],
    ]);

    $afterFirst = StatementLine::query()->findOrFail($bankLine->id);
    expect((string) $afterFirst->amount_residual)->toBe('60.0000')
        ->and($afterFirst->is_reconciled)->toBeFalse();

    app(MatchService::class)->createMatch($reconciliation, [$bankLine->id], [$secondLedger]);

    $afterSecond = StatementLine::query()->findOrFail($bankLine->id);
    expect((string) $afterSecond->amount_residual)->toBe('0.0000')
        ->and($afterSecond->is_reconciled)->toBeTrue()
        ->and($afterSecond->terminal_treatment)->toBe('matched');
});

it('treats repeated confirmations and unmatches as idempotent', function (): void {
    $reconciliation = u13Reconciliation();
    $bankLine = u13BankLine($reconciliation, '33.0000');
    $ledgerId = u13MoveLine($reconciliation, '33.0000');

    $proposalKey = 'u13-proposal-'.fake()->unique()->bothify('####');
    $proposed = app(MatchService::class)->proposeMatch($reconciliation, [$bankLine->id], [$ledgerId], idempotencyKey: $proposalKey);
    $confirmed = app(MatchService::class)->confirm($proposed);
    $again = app(MatchService::class)->confirm($confirmed->refresh());
    $proposalRetry = app(MatchService::class)->proposeMatch($reconciliation, [$bankLine->id], [$ledgerId], idempotencyKey: $proposalKey);
    expect($again->id)->toBe($confirmed->id)
        ->and($again->status)->toBe('confirmed')
        ->and($proposalRetry->id)->toBe($confirmed->id)
        ->and($proposalRetry->status)->toBe('confirmed')
        ->and(ReconMatch::query()->where('idempotency_key', $proposalKey)->count())->toBe(1)
        ->and($confirmed->lines()->count())->toBe(2)
        ->and(PartialReconcile::query()->where('recon_match_id', $confirmed->id)->exists())->toBeFalse();

    $secondBank = u13BankLine($reconciliation, '21.0000');
    $secondLedger = u13MoveLine($reconciliation, '21.0000');
    $key = 'u13-idempotent-'.fake()->unique()->bothify('####');
    $first = app(MatchService::class)->createMatch($reconciliation, [$secondBank->id], [$secondLedger], idempotencyKey: $key);
    $retry = app(MatchService::class)->createMatch($reconciliation, [$secondBank->id], [$secondLedger], idempotencyKey: $key);
    expect($retry->id)->toBe($first->id);

    $unmatched = app(MatchService::class)->unmatch($confirmed->refresh(), 'Correcting an incorrect match');
    $secondUnmatch = app(MatchService::class)->unmatch($unmatched->refresh(), 'Retrying the same reversal');
    expect($secondUnmatch->id)->toBe($unmatched->id)
        ->and($secondUnmatch->status)->toBe('reversed');
});

it('preserves confirmation and reversal history in the audit trail', function (): void {
    $reconciliation = u13Reconciliation();
    $bankLine = u13BankLine($reconciliation, '44.0000');
    $ledgerId = u13MoveLine($reconciliation, '44.0000');

    $match = app(MatchService::class)->createMatch($reconciliation, [$bankLine->id], [$ledgerId]);
    app(MatchService::class)->unmatch($match->refresh(), 'Correcting an incorrect match');

    $events = $reconciliation->events()->pluck('event')->all();
    expect($events)->toContain('match_confirmed')
        ->and($events)->toContain('unmatched')
        ->and($match->refresh()->accounting_applied_by)->not->toBeNull()
        ->and($match->refresh()->accounting_reversed_by)->not->toBeNull();
});

it('never nets mixed-direction bank or ledger activity into one match', function (): void {
    $reconciliation = u13Reconciliation();
    $bankReceipt = u13BankLine($reconciliation, '100.0000');
    $bankPayment = u13BankLine($reconciliation, '-40.0000');
    $ledgerDebit = u13MoveLine($reconciliation, '10.0000');
    $ledgerCredit = u13MoveLine($reconciliation, '-70.0000');

    expect(fn () => app(MatchService::class)->createMatch(
        $reconciliation,
        [$bankReceipt->id, $bankPayment->id],
        [$ledgerDebit, $ledgerCredit],
    ))->toThrow(InvalidArgumentException::class, 'Mixed-direction');

    expect(PartialReconcile::query()->whereIn('debit_move_id', [$ledgerDebit, $ledgerCredit])
        ->orWhereIn('credit_move_id', [$ledgerDebit, $ledgerCredit])->exists())->toBeFalse();
});

it('uses transaction-currency residuals for a foreign-currency bank account', function (): void {
    $reconciliation = u13Reconciliation();
    $foreignCurrencyId = DB::table('currencies')->where('id', '<>', $reconciliation->company->currency_id)->value('id');
    expect($foreignCurrencyId)->not->toBeNull();
    DB::table('partners_bank_accounts')->where('id', $reconciliation->bank_account_id)->update(['currency_id' => $foreignCurrencyId]);
    DB::table('accounts_journals')->where('id', $reconciliation->journal_id)->update(['currency_id' => $foreignCurrencyId]);
    $reconciliation->update(['currency_id' => $foreignCurrencyId]);

    $bankLine = u13BankLine($reconciliation->refresh(), '100.0000');
    $ledgerId = u13MoveLine($reconciliation, '28000.0000', [
        'currency_id'              => $foreignCurrencyId,
        'company_currency_id'      => $reconciliation->company->currency_id,
        'amount_currency'          => '100.0000',
        'amount_residual_currency' => '100.0000',
    ]);

    $match = app(MatchService::class)->createMatch($reconciliation->refresh(), [$bankLine->id], [$ledgerId]);

    expect($match->amount)->toBe('100.0000')
        ->and($match->accounting_mode)->toBe('allocation_only')
        ->and(PartialReconcile::query()->where('recon_match_id', $match->id)->exists())->toBeFalse();
});

it('rejects a real variance at four-decimal currency precision', function (): void {
    $reconciliation = u13Reconciliation();
    DB::table('currencies')->where('id', $reconciliation->currency_id)->update(['decimal_places' => 4, 'rounding' => 0.0001]);
    $bankLine = u13BankLine($reconciliation, '100.0001');
    $ledgerId = u13MoveLine($reconciliation, '100.0000');

    expect(fn () => app(MatchService::class)->createMatch($reconciliation->refresh(), [$bankLine->id], [$ledgerId]))
        ->toThrow(InvalidArgumentException::class, 'not balanced');
});

it('does not overwrite existing accounting matching provenance', function (): void {
    $reconciliation = u13Reconciliation();
    $bankLine = u13BankLine($reconciliation, '75.0000');
    $ledgerId = u13MoveLine($reconciliation, '75.0000', ['matching_number' => 'P123']);

    $match = app(MatchService::class)->createMatch($reconciliation, [$bankLine->id], [$ledgerId]);
    $ledger = MoveLine::query()->withoutGlobalScopes()->findOrFail($ledgerId);

    expect($match->accounting_mode)->toBe('allocation_only')
        ->and($ledger->matching_number)->toBe('P123')
        ->and($ledger->statement_line_id)->toBeNull();
});

it('binds an idempotency key to allocations and match intent', function (): void {
    $reconciliation = u13Reconciliation();
    $bankLine = u13BankLine($reconciliation, '100.0000');
    $ledgerId = u13MoveLine($reconciliation, '100.0000');
    $key = 'u13-command-'.fake()->unique()->bothify('####');

    app(MatchService::class)->createMatch($reconciliation, [$bankLine->id], [$ledgerId], notes: 'First instruction', allocations: [
        'bank' => [$bankLine->id => '60.0000'], 'ledger' => [$ledgerId => '60.0000'],
    ], idempotencyKey: $key);

    expect(fn () => app(MatchService::class)->createMatch($reconciliation, [$bankLine->id], [$ledgerId], notes: 'First instruction', allocations: [
        'bank' => [$bankLine->id => '50.0000'], 'ledger' => [$ledgerId => '50.0000'],
    ], idempotencyKey: $key))->toThrow(InvalidArgumentException::class, 'different financial instruction')
        ->and(fn () => app(MatchService::class)->createMatch($reconciliation, [$bankLine->id], [$ledgerId], notes: 'First instruction', idempotencyKey: $key))
        ->toThrow(InvalidArgumentException::class, 'different financial instruction')
        ->and(fn () => app(MatchService::class)->proposeMatch($reconciliation, [$bankLine->id], [$ledgerId], notes: 'First instruction', idempotencyKey: $key))
        ->toThrow(InvalidArgumentException::class, 'different financial instruction');
});

it('requires and preserves a meaningful reversal reason', function (): void {
    $reconciliation = u13Reconciliation();
    $bankLine = u13BankLine($reconciliation, '25.0000');
    $ledgerId = u13MoveLine($reconciliation, '25.0000');
    $match = app(MatchService::class)->createMatch($reconciliation, [$bankLine->id], [$ledgerId]);

    expect(fn () => app(MatchService::class)->unmatch($match->refresh(), ''))
        ->toThrow(InvalidArgumentException::class, 'meaningful reason');

    $reversed = app(MatchService::class)->unmatch($match->refresh(), 'Bank reference was assigned incorrectly');
    expect($reversed->reversal_reason)->toBe('Bank reference was assigned incorrectly');
});

it('rejects direct application of effects outside the create or confirm command', function (): void {
    $reconciliation = u13Reconciliation();
    $bankLine = u13BankLine($reconciliation, '31.0000');
    $ledgerId = u13MoveLine($reconciliation, '31.0000');
    $proposal = app(MatchService::class)->proposeMatch($reconciliation, [$bankLine->id], [$ledgerId]);

    expect(fn () => app(MatchService::class)->applyMatchEffects($proposal))
        ->toThrow(InvalidArgumentException::class, 'Direct match-effect application is closed')
        ->and($proposal->refresh()->status)->toBe('proposed')
        ->and(StatementLine::query()->findOrFail($bankLine->id)->is_reconciled)->toBeFalse();
});

it('preserves pre-existing accounting partials and their matching provenance', function (): void {
    $reconciliation = u13Reconciliation();
    $debitId = u13MoveLine($reconciliation, '100.0000');
    $creditId = u13MoveLine($reconciliation, '-50.0000');
    app(Reconciler::class)->reconcile(MoveLine::query()->withoutGlobalScopes()->whereKey([$debitId, $creditId])->get());

    $debitBefore = MoveLine::query()->withoutGlobalScopes()->findOrFail($debitId);
    $partialId = PartialReconcile::query()->where('debit_move_id', $debitId)->value('id');
    expect($partialId)->not->toBeNull()
        ->and((string) $debitBefore->amount_residual)->toBe('50.0000')
        ->and($debitBefore->matching_number)->not->toBeNull();

    $bankLine = u13BankLine($reconciliation, '50.0000');
    $match = app(MatchService::class)->createMatch($reconciliation, [$bankLine->id], [$debitId]);

    $debitMatched = MoveLine::query()->withoutGlobalScopes()->findOrFail($debitId);
    expect($match->accounting_mode)->toBe('allocation_only')
        ->and(PartialReconcile::query()->whereKey($partialId)->exists())->toBeTrue()
        ->and((string) $debitMatched->amount_residual)->toBe('50.0000')
        ->and($debitMatched->matching_number)->toBe($debitBefore->matching_number)
        ->and($debitMatched->statement_line_id)->toBeNull();

    app(MatchService::class)->unmatch($match->refresh(), 'Removing only the bank clearing allocation');
    expect(PartialReconcile::query()->whereKey($partialId)->exists())->toBeTrue()
        ->and((string) MoveLine::query()->withoutGlobalScopes()->findOrFail($debitId)->amount_residual)->toBe('50.0000');
});

it('refuses to unlink a ledger line whose match-owned linkage was changed', function (): void {
    $reconciliation = u13Reconciliation();
    $bankLine = u13BankLine($reconciliation, '46.0000');
    $ledgerId = u13MoveLine($reconciliation, '46.0000');
    $match = app(MatchService::class)->createMatch($reconciliation, [$bankLine->id], [$ledgerId]);

    DB::table('accounts_account_move_lines')->where('id', $ledgerId)->update([
        'matching_number' => 'RB-EXTERNAL',
    ]);

    expect(fn () => app(MatchService::class)->unmatch($match->refresh(), 'Correcting a linkage changed by another workflow'))
        ->toThrow(InvalidArgumentException::class, 'outside this match')
        ->and($match->refresh()->status)->toBe('confirmed');
});

it('clears same-direction receipts and payments without changing the posted ledger', function (string $amount): void {
    $reconciliation = u13Reconciliation();
    $bankLine = u13BankLine($reconciliation, $amount);
    $ledgerId = u13MoveLine($reconciliation, $amount);

    $match = app(MatchService::class)->createMatch($reconciliation, [$bankLine->id], [$ledgerId]);

    expect($bankLine->refresh()->amount_residual)->toBe('0.0000')
        ->and($match->lines()->pluck('amount_applied')->all())->toBe([$amount, $amount])
        ->and((string) MoveLine::query()->withoutGlobalScopes()->findOrFail($ledgerId)->balance)->toBe($amount)
        ->and((string) MoveLine::query()->withoutGlobalScopes()->findOrFail($ledgerId)->amount_residual)->toBe($amount)
        ->and(PartialReconcile::query()->where('recon_match_id', $match->id)->exists())->toBeFalse();
})->with(['receipt' => '125.0000', 'payment' => '-125.0000']);

it('rejects an opposite-direction bank and liquidity entry without an allocation', function (): void {
    $reconciliation = u13Reconciliation();
    $bankLine = u13BankLine($reconciliation, '125.0000');
    $ledgerId = u13MoveLine($reconciliation, '-125.0000');

    expect(fn () => app(MatchService::class)->createMatch($reconciliation, [$bankLine->id], [$ledgerId]))
        ->toThrow(InvalidArgumentException::class, 'same direction')
        ->and($reconciliation->matches()->count())->toBe(0)
        ->and($bankLine->refresh()->amount_residual)->toBe('125.0000');
});

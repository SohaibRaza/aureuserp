<?php

require_once __DIR__.'/ReconTestSupport.php';

use App\Models\Recon\BankReconciliation;
use App\Models\Recon\ReconMatch;
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
    config([
        'reconciliation.financial_mutations_enabled' => true,
        'reconciliation.require_company_rollout'     => false,
    ]);
});

function atomicMatchingReconciliation(): BankReconciliation
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
        'name'             => fake()->unique()->bothify('ATOMIC/####'),
        'date'             => '2026-09-30',
        'balance_start'    => '0.0000',
        'balance_end'      => '0.0000',
        'balance_end_real' => '0.0000',
        'is_completed'     => false,
    ]);
    $reconciliation->update(['statement_id' => $statement->id]);

    return $reconciliation->refresh();
}

function atomicStatementLine(BankReconciliation $reconciliation, string $amount): StatementLine
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
    ]);
}

function atomicMoveLine(BankReconciliation $reconciliation, string $balance): int
{
    $counterpartId = DB::table('accounts_accounts')->where('id', '<>', $reconciliation->liquidity_account_id)->value('id');
    $moveId = DB::table('accounts_account_moves')->insertGetId([
        'journal_id'  => $reconciliation->journal_id,
        'company_id'  => $reconciliation->company_id,
        'currency_id' => $reconciliation->currency_id,
        'creator_id'  => auth()->id(),
        'name'        => fake()->unique()->bothify('ATOMIC-MOVE/####'),
        'state'       => 'posted',
        'move_type'   => 'entry',
        'date'        => '2026-09-15',
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
        'account_id'          => $reconciliation->liquidity_account_id,
        'currency_id'         => $reconciliation->currency_id,
        'creator_id'          => auth()->id(),
        'date'                => '2026-09-15',
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
        'date'                => '2026-09-15',
        'debit'               => $credit,
        'credit'              => $debit,
        'balance'             => bcmul($balance, '-1', 4),
        'reconciled'          => false,
        'created_at'          => now(),
        'updated_at'          => now(),
    ]);

    return $lineId;
}

it('applies the financial safety gate even when automatic matching only creates proposals', function (): void {
    $reconciliation = atomicMatchingReconciliation();
    atomicStatementLine($reconciliation, '100.0000');
    atomicMoveLine($reconciliation, '-100.0000');
    config(['reconciliation.financial_mutations_enabled' => false]);

    expect(fn () => app(MatchingEngine::class)->autoMatch($reconciliation, confirmStrong: false))
        ->toThrow(InvalidArgumentException::class, 'temporarily disabled')
        ->and(ReconMatch::query()->where('reconciliation_id', $reconciliation->id)->exists())->toBeFalse()
        ->and(DB::table('recon_events')->where('reconciliation_id', $reconciliation->id)->exists())->toBeFalse();
});

it('rejects automatic matching in every non-editable lifecycle state without partial effects', function (string $status): void {
    $reconciliation = atomicMatchingReconciliation();
    atomicStatementLine($reconciliation, '100.0000');
    atomicMoveLine($reconciliation, '-100.0000');
    $reconciliation->update(['status' => $status]);

    expect(fn () => app(MatchingEngine::class)->autoMatch($reconciliation->refresh(), confirmStrong: false))
        ->toThrow(InvalidArgumentException::class, 'locked')
        ->and(ReconMatch::query()->where('reconciliation_id', $reconciliation->id)->exists())->toBeFalse()
        ->and(DB::table('recon_events')->where('reconciliation_id', $reconciliation->id)->exists())->toBeFalse();
})->with(['review', 'completed', 'reopen_requested', 'stale', 'cancelled', 'legacy_unverified']);

it('rolls back every proposal, allocation, and event when a later automatic match fails', function (): void {
    $reconciliation = atomicMatchingReconciliation();
    atomicStatementLine($reconciliation, '100.0000');
    atomicMoveLine($reconciliation, '-100.0000');
    atomicStatementLine($reconciliation, '200.0000');
    atomicMoveLine($reconciliation, '-200.0000');

    $real = app(MatchService::class);
    $failing = new class($real) extends MatchService
    {
        private int $calls = 0;

        public function __construct(private readonly MatchService $delegate) {}

        public function proposeMatch(BankReconciliation $reconciliation, array $bankLineIds, array $ledgerLineIds, ?string $notes = null, array $allocations = [], ?int $ruleId = null, ?string $idempotencyKey = null): ReconMatch
        {
            $this->calls++;
            if ($this->calls === 2) {
                throw new RuntimeException('Injected failure after the first proposal.');
            }

            return $this->delegate->proposeMatch($reconciliation, $bankLineIds, $ledgerLineIds, $notes, $allocations, $ruleId, $idempotencyKey);
        }
    };
    app()->instance(MatchService::class, $failing);

    expect(fn () => app(MatchingEngine::class)->autoMatch($reconciliation, confirmStrong: false))
        ->toThrow(RuntimeException::class, 'Injected failure')
        ->and(ReconMatch::query()->where('reconciliation_id', $reconciliation->id)->count())->toBe(0)
        ->and(DB::table('recon_match_lines')->where('company_id', $reconciliation->company_id)->count())->toBe(0)
        ->and(DB::table('recon_events')->where('reconciliation_id', $reconciliation->id)->count())->toBe(0);
});

it('commits all proposals and their audit evidence together on the successful path', function (): void {
    $reconciliation = atomicMatchingReconciliation();
    atomicStatementLine($reconciliation, '100.0000');
    atomicMoveLine($reconciliation, '-100.0000');
    atomicStatementLine($reconciliation, '200.0000');
    atomicMoveLine($reconciliation, '-200.0000');

    $stats = app(MatchingEngine::class)->autoMatch($reconciliation, confirmStrong: false);

    $matches = ReconMatch::query()->where('reconciliation_id', $reconciliation->id)->get();
    expect($stats)->toBe(['proposed' => 2, 'confirmed' => 0])
        ->and($matches)->toHaveCount(2)
        ->and($matches->every(fn (ReconMatch $match): bool => $match->status === 'proposed'))->toBeTrue()
        ->and(DB::table('recon_match_lines')->whereIn('match_id', $matches->pluck('id'))->count())->toBe(4)
        ->and(DB::table('recon_events')->where('reconciliation_id', $reconciliation->id)->where('event', 'match_proposed')->count())->toBe(2)
        ->and(DB::table('recon_events')->where('reconciliation_id', $reconciliation->id)->where('event', 'auto_matched')->count())->toBe(1);
});

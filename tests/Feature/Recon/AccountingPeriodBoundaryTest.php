<?php

require_once __DIR__.'/ReconTestSupport.php';

use App\Models\Recon\BankReconciliation;
use App\Models\Recon\ReconMatch;
use App\Models\Recon\ReconMatchLine;
use App\Models\Recon\StatementLine;
use App\Services\Recon\ReconciliationLifecycleService;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\BankStatement;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Services\AccountingDateGuard;
use Webkul\Account\Services\MoveWorkflow;
use Webkul\Account\Services\Reconciler;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\CompanyContext;

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('accounts');
    $this->company = Company::query()->firstOrFail();
    $this->actingAs(User::query()->firstOrFail());
    app()->forgetInstance(CompanyContext::class);
    URL::resolveMissingNamedRoutesUsing(fn () => '#');
    config()->set('reconciliation.financial_mutations_enabled', true);
    config()->set('reconciliation.require_company_rollout', false);
    config()->set('reconciliation.require_different_reviewer', true);
});

function tryPeriodBoundaryLock(Connection $connection, int $companyId, bool $exclusive = false): bool
{
    if ($connection->getDriverName() === 'pgsql') {
        $function = $exclusive ? 'pg_try_advisory_xact_lock' : 'pg_try_advisory_xact_lock_shared';

        return (bool) $connection->selectOne("SELECT {$function}(?, ?) AS acquired", [0x41525044, $companyId])->acquired;
    }
    try {
        return $connection->table('companies')->where('id', $companyId)
            ->lock($exclusive ? 'FOR UPDATE NOWAIT' : 'FOR SHARE NOWAIT')->first(['id']) !== null;
    } catch (QueryException $exception) {
        if ((int) ($exception->errorInfo[1] ?? 0) !== 3572) {
            throw $exception;
        }

        return false;
    }
}

function periodBoundaryReconciliation(string $status): BankReconciliation
{
    return BankReconciliation::query()->create([
        ...validBankReconciliationAttributes(testBankJournal(dedicated: true)),
        'status'         => $status,
        'statement_date' => '2026-08-31',
    ]);
}

function periodBoundaryMove(BankReconciliation $reconciliation, int $bankBalance = 10): Move
{
    $move = Move::query()->create([
        'journal_id'  => $reconciliation->journal_id,
        'company_id'  => $reconciliation->company_id,
        'currency_id' => $reconciliation->currency_id,
        'move_type'   => 'entry',
        'state'       => 'draft',
        'date'        => '2026-08-31',
        'creator_id'  => auth()->id(),
    ]);
    $counterpart = Account::query()->withoutGlobalScopes()
        ->where(owned_by_company($reconciliation->company_id))
        ->where('id', '<>', $reconciliation->liquidity_account_id)
        ->where('deprecated', false)->value('id');
    foreach ([[$reconciliation->liquidity_account_id, $bankBalance], [$counterpart, -$bankBalance]] as [$accountId, $balance]) {
        MoveLine::query()->create([
            'move_id'             => $move->id,
            'journal_id'          => $move->journal_id,
            'company_id'          => $move->company_id,
            'company_currency_id' => $move->currency_id,
            'currency_id'         => $move->currency_id,
            'account_id'          => $accountId,
            'date'                => $move->date,
            'debit'               => max($balance, 0),
            'credit'              => max(-$balance, 0),
            'balance'             => $balance,
            'amount_currency'     => $balance,
            'creator_id'          => auth()->id(),
        ]);
    }

    return $move;
}

it('keeps a pending reopen request closed until approved', function (): void {
    $reconciliation = periodBoundaryReconciliation('completed');
    $move = periodBoundaryMove($reconciliation);
    $lifecycle = app(ReconciliationLifecycleService::class);
    $requested = $lifecycle->requestReopen($reconciliation, 'Correct historical evidence');

    expect(fn () => app(MoveWorkflow::class)->post($move))->toThrow(ValidationException::class)
        ->and($move->fresh()->state->value)->toBe('draft');

    actAsReconciliationReviewer((int) $requested->company_id);
    $lifecycle->reopen($requested, 'Approved correction');
    expect(app(MoveWorkflow::class)->post($move)->state->value)->toBe('posted');
});

it('rejects reset and unreconciliation while reopening awaits approval', function (): void {
    $reconciliation = periodBoundaryReconciliation('in_progress');
    $workflow = app(MoveWorkflow::class);
    $first = $workflow->post(periodBoundaryMove($reconciliation));
    $second = $workflow->post(periodBoundaryMove($reconciliation, -10));
    $lines = MoveLine::query()->whereIn('move_id', [$first->id, $second->id])
        ->where('account_id', $reconciliation->liquidity_account_id)->get();
    $reconciler = app(Reconciler::class);
    $reconciler->reconcile($lines);
    $partial = $lines->first()->fresh()->matchedCredits()->firstOrFail();
    $reconciliation->update(['status' => 'completed']);
    app(ReconciliationLifecycleService::class)->requestReopen($reconciliation, 'Inspect prior period');

    expect(fn () => $workflow->resetToDraft($first))->toThrow(ValidationException::class)
        ->and(fn () => $reconciler->unReconcile($partial))->toThrow(ValidationException::class)
        ->and($first->fresh()->state->value)->toBe('posted')
        ->and($partial->fresh())->not->toBeNull()
        ->and((float) $lines->first()->fresh()->amount_residual)->toBe(0.0);
});

it('retains the actual completion gate until its enclosing transaction ends', function (): void {
    $reconciliation = periodBoundaryReconciliation('in_progress');
    $statement = BankStatement::query()->create([
        'company_id'    => $reconciliation->company_id, 'journal_id' => $reconciliation->journal_id,
        'date'          => '2026-08-31', 'name' => 'BOUNDARY-COMPLETE',
        'balance_start' => 0, 'balance_end' => 0, 'balance_end_real' => 0,
    ]);
    $match = ReconMatch::query()->create([
        'company_id'   => $reconciliation->company_id, 'reconciliation_id' => $reconciliation->id,
        'status'       => 'confirmed', 'match_type' => 'manual', 'amount' => 10, 'variance' => 0,
        'confirmed_at' => now(), 'confirmed_by' => auth()->id(), 'creator_id' => auth()->id(),
    ]);
    foreach ([10, -10] as $amount) {
        $line = StatementLine::query()->create([
            'statement_id'          => $statement->id, 'company_id' => $reconciliation->company_id,
            'journal_id'            => $reconciliation->journal_id, 'currency_id' => $reconciliation->currency_id,
            'transaction_date'      => '2026-08-31', 'amount' => $amount, 'amount_residual' => 0,
            'is_reconciled'         => true, 'import_status' => 'accepted',
            'source_fingerprint'    => hash('sha256', $reconciliation->id.':'.$amount),
            'terminal_treatment'    => 'matched', 'terminal_reference_type' => 'match',
            'terminal_reference_id' => $match->id,
            'terminal_treatment_at' => now(),
            'terminal_treatment_by' => auth()->id(),
        ]);
        ReconMatchLine::query()->create([
            'company_id'    => $reconciliation->company_id, 'match_id' => $match->id,
            'lineable_type' => ReconMatchLine::TYPE_STATEMENT_LINE, 'lineable_id' => $line->id,
            'side'          => ReconMatchLine::SIDE_BANK, 'amount_applied' => $amount,
        ]);
    }
    $reconciliation->update(['status' => 'review', 'statement_id' => $statement->id, 'balance_start' => 0, 'balance_end_real' => 0]);
    $move = periodBoundaryMove($reconciliation);
    $competitor = DB::build([...DB::connection()->getConfig(), 'name' => 'accounting_period_competitor']);
    $competitor->beginTransaction();
    try {
        DB::beginTransaction();
        try {
            $completed = app(ReconciliationLifecycleService::class)->complete($reconciliation);
            expect($completed->status)->toBe('completed')
                ->and($completed->snapshots()->count())->toBe(1)
                ->and(tryPeriodBoundaryLock($competitor, (int) $reconciliation->company_id))->toBeFalse()
                ->and(fn () => app(AccountingDateGuard::class)->assertReconciliationPeriodOpen($move))->toThrow(ValidationException::class);
        } finally {
            DB::rollBack();
        }
        if (DB::getDriverName() === 'mysql') {
            // InnoDB retains row locks after savepoint rollback; the full transaction must end.
            expect(tryPeriodBoundaryLock($competitor, (int) $reconciliation->company_id))->toBeFalse();
            DB::rollBack();
            DB::beginTransaction();
        }
        expect(tryPeriodBoundaryLock($competitor, (int) $reconciliation->company_id))->toBeTrue();
    } finally {
        $competitor->rollBack();
        $competitor->disconnect();
    }
});

it('holds the period boundary through the accounting transaction', function (bool $completionFirst): void {
    $reconciliation = periodBoundaryReconciliation('review');
    $move = periodBoundaryMove($reconciliation);
    $connection = DB::connection();
    if ($connection->getDriverName() === 'mysql') {
        // Committed fixtures avoid retaining FK shared locks on Company before the contender starts.
        while ($connection->transactionLevel() > 0) {
            $connection->commit();
        }
        $connection->beginTransaction();
    }
    $competitor = DB::build([...$connection->getConfig(), 'name' => 'accounting_period_competitor']);
    $competitor->beginTransaction();
    try {
        $pidQuery = $connection->getDriverName() === 'pgsql' ? 'SELECT pg_backend_pid() AS pid' : 'SELECT CONNECTION_ID() AS pid';
        expect($competitor->selectOne($pidQuery)->pid)->not->toBe($connection->selectOne($pidQuery)->pid);
        expect(tryPeriodBoundaryLock($competitor, (int) $reconciliation->company_id, $completionFirst))->toBeTrue();

        if ($completionFirst) {
            expect(fn () => app(MoveWorkflow::class)->post($move))
                ->toThrow(ValidationException::class, 'Refresh and try again');
            expect($move->fresh()->state->value)->toBe('draft');
        } else {
            expect(fn () => app(ReconciliationLifecycleService::class)->complete($reconciliation))
                ->toThrow(ValidationException::class, 'Refresh and try again');
            expect($reconciliation->fresh()->status)->toBe('review')
                ->and($reconciliation->snapshots()->count())->toBe(0);
            // Shared accounting gates must not block another independent posting.
            app(AccountingDateGuard::class)->assertReconciliationPeriodOpen($move);
        }
    } finally {
        $competitor->rollBack();
        $competitor->disconnect();
    }

    if ($completionFirst) {
        expect(app(MoveWorkflow::class)->post($move)->state->value)->toBe('posted');
    }
})->with(['completion first' => [true], 'accounting mutation first' => [false]]);

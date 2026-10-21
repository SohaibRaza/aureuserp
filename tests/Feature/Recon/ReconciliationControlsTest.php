<?php

require_once __DIR__.'/ReconTestSupport.php';

use App\Enums\Recon\ReconcilingItemClassification;
use App\Enums\Recon\ReconcilingItemSide;
use App\Filament\Pages\Recon\ReconReport;
use App\Models\Recon\BankReconciliation;
use App\Models\Recon\ReconMatch;
use App\Models\Recon\ReconMatchLine;
use App\Models\Recon\StatementLine;
use App\Services\Recon\AdjustmentService;
use App\Services\Recon\FeedIngestionService;
use App\Services\Recon\FinalizationService;
use App\Services\Recon\MatchingEngine;
use App\Services\Recon\ReconciliationExceptionService;
use App\Services\Recon\ReconciliationLifecycleService;
use App\Services\Recon\StatementImporter;
use App\Services\Recon\StatementLineTreatment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\BankStatement;
use Webkul\Account\Models\Move;
use Webkul\Account\Services\MoveWorkflow;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('accounts');
    $this->company = Company::query()->firstOrFail();
    $this->actingAs(User::query()->firstOrFail());
    config()->set('reconciliation.financial_mutations_enabled', true);
    config()->set('reconciliation.require_company_rollout', false);
    config()->set('reconciliation.require_different_reviewer', true);
});

function controlledReconciliation(array $overrides = []): BankReconciliation
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

function adjustmentStatementLine(BankReconciliation $reconciliation, string $amount, string $reference): StatementLine
{
    $statement = BankStatement::query()->firstOrCreate(
        ['company_id' => $reconciliation->company_id, 'journal_id' => $reconciliation->journal_id, 'name' => 'ADJUSTMENTS-'.$reconciliation->id],
        ['date' => $reconciliation->period_end, 'balance_start' => 0, 'balance_end' => 0, 'balance_end_real' => 0],
    );
    $reconciliation->update(['statement_id' => $statement->id]);

    return StatementLine::query()->create([
        'statement_id'       => $statement->id,
        'company_id'         => $reconciliation->company_id,
        'journal_id'         => $reconciliation->journal_id,
        'currency_id'        => $reconciliation->currency_id,
        'transaction_date'   => $reconciliation->period_end,
        'amount'             => $amount,
        'amount_residual'    => $amount,
        'import_status'      => 'accepted',
        'source_fingerprint' => hash('sha256', $reference),
        'payment_reference'  => $reference,
    ]);
}

it('posts adjustments through the accounts workflow and requires an explicit valid counterpart', function (): void {
    $reconciliation = controlledReconciliation();
    $counterpart = Account::query()->withoutGlobalScopes()
        ->where(owned_by_company($reconciliation->company_id))
        ->where('id', '<>', $reconciliation->liquidity_account_id)
        ->where('deprecated', false)
        ->value('id');
    $line = adjustmentStatementLine($reconciliation, '-12.5000', 'monthly-fee');

    $adjustment = app(AdjustmentService::class)->post($reconciliation->refresh(), 'bank_fee', '-12.5000', $counterpart, 'Monthly fee', $line->id);
    expect($line->refresh()->terminal_treatment)->toBe('adjustment')
        ->and($line->amount_residual)->toBe('0.0000');
    $reversed = app(AdjustmentService::class)->reverse($adjustment, 'Bank refunded the fee');

    expect($adjustment->posted_at)->not->toBeNull()
        ->and(DB::table('accounts_account_moves')->where('id', $adjustment->move_id)->value('state'))->toBe('posted')
        ->and((string) DB::table('accounts_account_move_lines')->where('move_id', $adjustment->move_id)->sum('balance'))->toBe('0.0000')
        ->and($reversed->reversed_at)->not->toBeNull()
        ->and($line->refresh()->terminal_treatment)->toBeNull()
        ->and($line->amount_residual)->toBe('-12.5000')
        ->and(fn () => app(AdjustmentService::class)->post($reconciliation, 'bank_fee', '-1.0000', null))->toThrow(InvalidArgumentException::class);
});

it('classifies bank-only exceptions without creating journal entries', function (): void {
    $reconciliation = controlledReconciliation();
    $statement = BankStatement::query()->create([
        'company_id'       => $reconciliation->company_id,
        'journal_id'       => $reconciliation->journal_id,
        'date'             => '2026-09-30',
        'name'             => 'EXCEPTIONS',
        'balance_start'    => 0,
        'balance_end'      => 20,
        'balance_end_real' => 20,
    ]);
    $reconciliation->update(['statement_id' => $statement->id]);
    $line = StatementLine::query()->create([
        'statement_id'       => $statement->id,
        'company_id'         => $reconciliation->company_id,
        'journal_id'         => $reconciliation->journal_id,
        'currency_id'        => $reconciliation->currency_id,
        'transaction_date'   => '2026-09-30',
        'amount'             => '20.0000',
        'import_status'      => 'accepted',
        'source_fingerprint' => hash('sha256', 'controlled-duplicate'),
    ]);
    $movesBefore = DB::table('accounts_account_moves')->count();

    $item = app(ReconciliationExceptionService::class)->classify(
        $reconciliation,
        ReconcilingItemClassification::Duplicate,
        ReconcilingItemSide::Bank,
        '20.0000',
        statementLineId: $line->id,
        evidence: ['source' => 'statement'],
    );

    expect($item->status->value)->toBe('open')
        ->and(fn () => app(ReconciliationExceptionService::class)->excludeDuplicate($item, ''))
        ->toThrow(InvalidArgumentException::class, 'approval reason');

    $item = app(ReconciliationExceptionService::class)->excludeDuplicate($item, 'Confirmed duplicate bank row');
    $bankErrorLine = StatementLine::query()->create([
        'statement_id'       => $statement->id,
        'company_id'         => $reconciliation->company_id,
        'journal_id'         => $reconciliation->journal_id,
        'currency_id'        => $reconciliation->currency_id,
        'transaction_date'   => '2026-09-30',
        'amount'             => '-5.0000',
        'amount_residual'    => '-5.0000',
        'import_status'      => 'accepted',
        'source_fingerprint' => hash('sha256', 'controlled-bank-error'),
    ]);
    $bankError = app(ReconciliationExceptionService::class)->classify(
        $reconciliation,
        ReconcilingItemClassification::BankError,
        ReconcilingItemSide::Bank,
        '5.0000',
        statementLineId: $bankErrorLine->id,
    );
    $bankError = app(ReconciliationExceptionService::class)->approveBankError($bankError, 'Bank confirmed posting error');
    $calculation = app(FinalizationService::class)->computeDifference($reconciliation);

    expect($item->status->value)->toBe('excluded')
        ->and($line->refresh()->terminal_treatment)->toBe('duplicate')
        ->and($line->amount_residual)->toBe('0.0000')
        ->and($line->is_reconciled)->toBeTrue()
        ->and($bankError->status->value)->toBe('resolved')
        ->and($bankErrorLine->refresh()->terminal_treatment)->toBe('bank_error')
        ->and($bankErrorLine->amount_residual)->toBe('0.0000')
        ->and($bankErrorLine->is_reconciled)->toBeTrue()
        ->and($calculation['bank_errors'])->toBe('5.00')
        ->and(DB::table('accounts_account_moves')->count())->toBe($movesBefore);
});

it('routes transfers to balance-sheet accounts and FX differences to the correct gain or loss side', function (): void {
    $reconciliation = controlledReconciliation();
    $destination = createReconAccount($reconciliation, 'asset_cash');
    $destination->companies()->syncWithoutDetaching([$reconciliation->company_id]);
    $gain = createReconAccount($reconciliation, 'income');
    $gain->companies()->syncWithoutDetaching([$reconciliation->company_id]);
    $loss = createReconAccount($reconciliation, 'expense');
    $loss->companies()->syncWithoutDetaching([$reconciliation->company_id]);

    $transferLine = adjustmentStatementLine($reconciliation, '-50.0000', 'transfer');
    $fxGainLine = adjustmentStatementLine($reconciliation, '7.5000', 'fx-gain');
    $fxLossLine = adjustmentStatementLine($reconciliation, '-3.2500', 'fx-loss');
    $reconciliation->refresh();

    $transfer = app(AdjustmentService::class)->postTransfer($reconciliation, '-50.0000', $destination->id, 'Transfer to savings', $transferLine->id);
    $fxGain = app(AdjustmentService::class)->postFxDifference($reconciliation, '7.5000', $gain->id, $loss->id, 'FX gain', $fxGainLine->id);
    $fxLoss = app(AdjustmentService::class)->postFxDifference($reconciliation, '-3.2500', $gain->id, $loss->id, 'FX loss', $fxLossLine->id);

    expect($transfer->type)->toBe('transfer')
        ->and($transfer->counterpart_account_id)->toBe($destination->id)
        ->and($fxGain->counterpart_account_id)->toBe($gain->id)
        ->and($fxLoss->counterpart_account_id)->toBe($loss->id)
        ->and(DB::table('accounts_account_move_lines')->whereIn('move_id', [$transfer->move_id, $fxGain->move_id, $fxLoss->move_id])->sum('balance'))->toEqual(0);
});

function createReconAccount(BankReconciliation $reconciliation, string $accountType): Account
{
    $id = ((int) Account::query()->withoutGlobalScopes()->max('id')) + 1;
    DB::table('accounts_accounts')->insert([
        'id'           => $id,
        'currency_id'  => $reconciliation->currency_id,
        'creator_id'   => auth()->id(),
        'account_type' => $accountType,
        'name'         => fake()->unique()->words(3, true),
        'code'         => fake()->unique()->numerify('###'),
        'deprecated'   => false,
        'reconcile'    => false,
        'non_trade'    => false,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    return Account::query()->withoutGlobalScopes()->findOrFail($id);
}

it('completes atomically with an immutable snapshot and makes later periods stale on reopen', function (): void {
    $reconciliation = controlledReconciliation(['status' => 'review']);
    $statement = BankStatement::query()->create([
        'company_id'       => $reconciliation->company_id,
        'journal_id'       => $reconciliation->journal_id,
        'date'             => '2026-09-30',
        'name'             => 'COMPLETE',
        'balance_start'    => 0,
        'balance_end'      => 10,
        'balance_end_real' => 10,
    ]);
    $reconciliation->update(['statement_id' => $statement->id, 'balance_end_real' => '10.0000']);
    $statementLine = StatementLine::query()->create([
        'statement_id'       => $statement->id,
        'company_id'         => $reconciliation->company_id,
        'journal_id'         => $reconciliation->journal_id,
        'currency_id'        => $reconciliation->currency_id,
        'transaction_date'   => '2026-09-30',
        'amount'             => '10.0000',
        'amount_residual'    => '0.0000',
        'is_reconciled'      => true,
        'import_status'      => 'accepted',
        'source_fingerprint' => hash('sha256', 'controlled-complete'),
    ]);
    $counterpart = DB::table('accounts_accounts')->where('id', '<>', $reconciliation->liquidity_account_id)->value('id');
    $moveId = DB::table('accounts_account_moves')->insertGetId([
        'journal_id'  => $reconciliation->journal_id, 'company_id' => $reconciliation->company_id,
        'currency_id' => $reconciliation->currency_id, 'name' => 'COMPLETE/GL', 'state' => 'posted',
        'move_type'   => 'entry', 'date' => '2026-09-30', 'creator_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ([[$reconciliation->liquidity_account_id, 10, 0, 10], [$counterpart, 0, 10, -10]] as [$account, $debit, $credit, $balance]) {
        DB::table('accounts_account_move_lines')->insert([
            'move_id'             => $moveId, 'journal_id' => $reconciliation->journal_id, 'company_id' => $reconciliation->company_id,
            'company_currency_id' => $reconciliation->currency_id, 'currency_id' => $reconciliation->currency_id,
            'account_id'          => $account, 'date' => '2026-09-30', 'debit' => $debit, 'credit' => $credit,
            'balance'             => $balance, 'creator_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $match = ReconMatch::query()->create([
        'company_id'   => $reconciliation->company_id, 'reconciliation_id' => $reconciliation->id,
        'status'       => 'confirmed', 'match_type' => 'manual', 'amount' => 10, 'variance' => 0,
        'confirmed_at' => now(), 'confirmed_by' => auth()->id(), 'creator_id' => auth()->id(),
    ]);
    ReconMatchLine::query()->create([
        'company_id'    => $reconciliation->company_id, 'match_id' => $match->id,
        'lineable_type' => ReconMatchLine::TYPE_STATEMENT_LINE, 'lineable_id' => $statementLine->id,
        'side'          => ReconMatchLine::SIDE_BANK, 'amount_applied' => 10,
    ]);
    $statementLine->update([
        'terminal_treatment'    => 'matched', 'terminal_reference_type' => 'match',
        'terminal_reference_id' => $match->id, 'terminal_treatment_at' => now(),
        'terminal_treatment_by' => auth()->id(),
    ]);
    $completed = app(ReconciliationLifecycleService::class)->complete($reconciliation->refresh());
    $snapshot = $completed->snapshots()->firstOrFail();
    try {
        app(MoveWorkflow::class)->resetToDraft(Move::query()->findOrFail($moveId));
        test()->fail('Completed bank reconciliation period allowed a journal reset.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('date')
            ->and($exception->errors()['date'][0])->toContain('completed bank reconciliation');
    }
    $statementLine->update(['amount' => '999.0000']);
    $reportPage = new ReconReport;
    $reportPage->record = $completed;
    $report = $reportPage->getReportProperty();
    $later = BankReconciliation::query()->create([
        ...$reconciliation->only(['company_id', 'journal_id', 'bank_account_id', 'liquidity_account_id', 'currency_id']),
        'name'       => 'OCT', 'status' => 'completed', 'period_start' => '2026-10-01',
        'period_end' => '2026-10-31', 'statement_date' => '2026-10-31',
    ]);
    $impact = app(ReconciliationLifecycleService::class)->previewReopenImpact($completed);
    app(ReconciliationLifecycleService::class)->requestReopen($completed, 'Correct September evidence');
    actAsReconciliationReviewer((int) $completed->company_id);
    app(ReconciliationLifecycleService::class)->reopen($completed->refresh(), 'Approved historical correction');

    expect($snapshot->payload['calculation']['difference'])->toBe('0.00')
        ->and($snapshot->payload['statement_lines'])->toHaveCount(1)
        ->and($snapshot->payload['matches'])->toHaveCount(1)
        ->and((string) $report['reconciled']->first()->amount)->toBe('10.0000')
        ->and($report['snapshot_version'])->toBe(1)
        ->and($impact)->toHaveCount(1)
        ->and($impact[0]['id'])->toBe($later->id)
        ->and($later->refresh()->status)->toBe('stale')
        ->and($later->events()->where('event', 'marked_stale')->exists())->toBeTrue()
        ->and($completed->events()->where('event', 'reopen_requested')->exists())->toBeTrue()
        ->and($completed->events()->where('event', 'reopened')->exists())->toBeTrue();
});

it('requires a recorded reopen request before approval', function (): void {
    $reconciliation = controlledReconciliation(['status' => 'completed']);

    expect(fn () => app(ReconciliationLifecycleService::class)->reopen($reconciliation, 'Attempted direct approval'))
        ->toThrow(InvalidArgumentException::class, 'submitted for reopening');
});

it('enforces separation between reopen requester and approver when configured', function (): void {
    config()->set('reconciliation.require_different_reviewer', true);
    $reconciliation = controlledReconciliation(['status' => 'completed']);
    $service = app(ReconciliationLifecycleService::class);
    $requested = $service->requestReopen($reconciliation, 'Correct a prior-period error');

    expect(fn () => $service->reopen($requested, 'Approval attempt by requester'))
        ->toThrow(InvalidArgumentException::class, 'requester cannot approve');
});

it('stales and audits completed periods affected by a historical correction', function (): void {
    $affected = controlledReconciliation([
        'status'         => 'completed',
        'name'           => 'AUG-COMPLETED',
        'period_start'   => '2026-08-01',
        'period_end'     => '2026-08-31',
        'statement_date' => '2026-08-31',
    ]);
    $context = BankReconciliation::query()->create([
        ...$affected->only(['company_id', 'journal_id', 'bank_account_id', 'liquidity_account_id', 'currency_id']),
        'name'             => 'SEP-CORRECTION',
        'status'           => 'in_progress',
        'period_start'     => '2026-09-01',
        'period_end'       => '2026-09-30',
        'statement_date'   => '2026-09-30',
        'balance_start'    => '0.0000',
        'balance_end_real' => '0.0000',
    ]);

    $impact = app(ReconciliationLifecycleService::class)->markAffectedByHistoricalCorrection(
        $context,
        '2026-08-15',
        'Returned payment affects August liquidity activity.',
        'returned_payment_posted',
    );

    expect($impact)->toHaveCount(1)
        ->and($impact[0]['id'])->toBe($affected->id)
        ->and($affected->refresh()->status)->toBe('stale')
        ->and($affected->events()->where('event', 'marked_stale')->exists())->toBeTrue();
});

it('blocks completion when statement activity is still unmatched', function (): void {
    $reconciliation = controlledReconciliation(['status' => 'review']);
    $statement = BankStatement::query()->create([
        'company_id'       => $reconciliation->company_id,
        'journal_id'       => $reconciliation->journal_id,
        'date'             => '2026-09-30',
        'name'             => 'BLOCKED-COMPLETION',
        'balance_start'    => 0,
        'balance_end'      => 10,
        'balance_end_real' => 10,
    ]);
    $reconciliation->update(['statement_id' => $statement->id, 'balance_end_real' => '10.0000']);
    StatementLine::query()->create([
        'statement_id'       => $statement->id,
        'company_id'         => $reconciliation->company_id,
        'journal_id'         => $reconciliation->journal_id,
        'currency_id'        => $reconciliation->currency_id,
        'transaction_date'   => '2026-09-30',
        'amount'             => '10.0000',
        'amount_residual'    => '10.0000',
        'import_status'      => 'accepted',
        'source_fingerprint' => hash('sha256', 'blocked-completion'),
    ]);

    expect(fn () => app(ReconciliationLifecycleService::class)->complete($reconciliation->refresh()))
        ->toThrow(InvalidArgumentException::class, 'no approved terminal treatment');
});

it('rejects unsupported or non-settling terminal treatments', function (): void {
    $reconciliation = controlledReconciliation();
    $line = adjustmentStatementLine($reconciliation, '5.0000', 'terminal-contract');
    $treatment = app(StatementLineTreatment::class);

    expect(fn () => $treatment->apply($reconciliation, $line, 'ignored', 'adjustment', 1, 'test', settleResidual: true))
        ->toThrow(InvalidArgumentException::class, 'Unsupported terminal treatment')
        ->and(fn () => $treatment->apply($reconciliation, $line, 'adjustment', 'adjustment', 1, 'test'))
        ->toThrow(InvalidArgumentException::class, 'must settle the statement line residual');
});

it('rejects a terminal treatment whose source is not owned by the reconciliation', function (): void {
    $reconciliation = controlledReconciliation();
    $line = adjustmentStatementLine($reconciliation, '5.0000', 'foreign-treatment-source');

    expect(fn () => app(StatementLineTreatment::class)->apply(
        $reconciliation->refresh(),
        $line,
        'duplicate',
        'reconciling_item',
        PHP_INT_MAX,
        'Attempted foreign source',
        settleResidual: true,
    ))->toThrow(InvalidArgumentException::class, 'outside this reconciliation')
        ->and($line->refresh()->terminal_treatment)->toBeNull()
        ->and($line->amount_residual)->toBe('5.0000');
});

it('enforces preparation permission inside every financial mutation service', function (): void {
    $reconciliation = controlledReconciliation();
    $line = adjustmentStatementLine($reconciliation, '-9.0000', 'u14-permission');
    $counterpart = Account::query()->withoutGlobalScopes()
        ->where(owned_by_company($reconciliation->company_id))
        ->where('id', '<>', $reconciliation->liquidity_account_id)
        ->where('deprecated', false)
        ->value('id');

    $unauthorized = User::withoutEvents(fn (): User => User::factory()->create([
        'default_company_id' => $reconciliation->company_id,
    ]));
    $this->actingAs($unauthorized);

    expect(fn () => app(AdjustmentService::class)->post($reconciliation->refresh(), 'bank_fee', '-9.0000', $counterpart, statementLineId: $line->id))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(ReconciliationExceptionService::class)->classify(
            $reconciliation->refresh(),
            ReconcilingItemClassification::Duplicate,
            ReconcilingItemSide::Bank,
            '-9.0000',
            statementLineId: $line->id,
        ))->toThrow(AuthorizationException::class)
        ->and(fn () => app(StatementImporter::class)->importCsv(
            $reconciliation->refresh(),
            [['date' => '2026-09-10', 'amount' => '1.0000']],
            '0.0000',
            '1.0000',
        ))->toThrow(AuthorizationException::class)
        ->and(fn () => app(FeedIngestionService::class)->stage(
            $reconciliation->refresh(),
            ['event_id' => 'unauthorized-feed', 'complete' => false],
        ))->toThrow(AuthorizationException::class)
        ->and(fn () => app(MatchingEngine::class)->autoMatch($reconciliation->refresh()))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(FinalizationService::class)->refreshDifference($reconciliation->refresh()))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(ReconciliationLifecycleService::class)->startReview($reconciliation->refresh()))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(StatementLineTreatment::class)->apply(
            $reconciliation->refresh(),
            $line->refresh(),
            'duplicate',
            'reconciling_item',
            999,
            'Unauthorized direct treatment',
            settleResidual: true,
        ))->toThrow(AuthorizationException::class);
});

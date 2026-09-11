<?php

require_once __DIR__.'/ReconTestSupport.php';

use App\Models\Recon\BankReconciliation;
use App\Models\Recon\ReconciliationSnapshot;
use App\Services\Recon\ReconciliationLifecycleService;
use Illuminate\Support\Facades\DB;
use Webkul\Account\Models\Journal;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('accounts');
    $this->company = Company::query()->firstOrFail();
    $this->actingAs(User::query()->firstOrFail());
    config()->set('reconciliation.financial_mutations_enabled', true);
    config()->set('reconciliation.require_company_rollout', false);
});

function lifecycleRecon(array $overrides = []): BankReconciliation
{
    $journal = isset($overrides['journal_id']) ? Journal::query()->findOrFail($overrides['journal_id']) : null;

    return BankReconciliation::query()->create([
        ...validBankReconciliationAttributes($journal),
        'status'           => 'in_progress',
        'period_start'     => '2026-09-01',
        'period_end'       => '2026-09-30',
        'statement_date'   => '2026-09-30',
        'balance_start'    => '0.0000',
        'balance_end_real' => '0.0000',
        ...$overrides,
    ]);
}

it('allows only the documented lifecycle transition paths', function (): void {
    $service = app(ReconciliationLifecycleService::class);
    $draft = lifecycleRecon(['status' => 'draft']);
    $review = lifecycleRecon(['status' => 'review']);
    $completed = lifecycleRecon(['status' => 'completed']);
    $stale = lifecycleRecon(['status' => 'stale']);

    expect(fn () => $service->returnToPreparation($draft))
        ->toThrow(InvalidArgumentException::class, 'Cannot move reconciliation')
        ->and(fn () => $service->complete($review))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->startReview($completed))
        ->toThrow(InvalidArgumentException::class, 'Cannot move reconciliation')
        ->and(fn () => $service->requestReopen($stale, 'Historical correction'))
        ->toThrow(InvalidArgumentException::class, 'Only a completed reconciliation')
        ->and($draft->refresh()->status)->toBe('draft')
        ->and($review->refresh()->status)->toBe('review')
        ->and($completed->refresh()->status)->toBe('completed')
        ->and($stale->refresh()->status)->toBe('stale');
});

it('retains immutable snapshots after an approved reopen and records ordered audit events', function (): void {
    $reconciliation = lifecycleRecon(['status' => 'completed', 'name' => 'SEPTEMBER-COMPLETE']);
    $snapshot = ReconciliationSnapshot::query()->create([
        'company_id'        => $reconciliation->company_id,
        'reconciliation_id' => $reconciliation->id,
        'version'           => 1,
        'payload'           => ['calculation' => ['difference' => '0.00'], 'statement_lines' => [['id' => 1]]],
        'payload_hash'      => hash('sha256', 'snapshot-one'),
        'created_by'        => auth()->id(),
    ]);
    $later = lifecycleRecon([
        'status'               => 'completed',
        'name'                 => 'OCTOBER-COMPLETE',
        'period_start'         => '2026-10-01',
        'period_end'           => '2026-10-31',
        'statement_date'       => '2026-10-31',
        'bank_account_id'      => $reconciliation->bank_account_id,
        'liquidity_account_id' => $reconciliation->liquidity_account_id,
        'journal_id'           => $reconciliation->journal_id,
    ]);
    $service = app(ReconciliationLifecycleService::class);

    $service->requestReopen($reconciliation, 'Correct September evidence');
    $service->reopen($reconciliation->refresh(), 'Approved historical correction');
    $events = $reconciliation->refresh()->events()->get();

    expect($snapshot->fresh()->payload['calculation']['difference'])->toBe('0.00')
        ->and($reconciliation->snapshots()->count())->toBe(1)
        ->and($reconciliation->refresh()->status)->toBe('reopened')
        ->and($later->refresh()->status)->toBe('stale')
        ->and($events->pluck('event')->all())->toBe(['reopen_requested', 'reopened'])
        ->and($later->events()->pluck('event')->all())->toBe(['marked_stale'])
        ->and($later->events()->first()->payload['cause_event'])->toBe('reopened')
        ->and(DB::table('recon_reconciliation_snapshots')->where('id', $snapshot->id)->exists())->toBeTrue();
});

it('records a deterministic stale event with its effective date and cause payload', function (): void {
    $context = lifecycleRecon(['status' => 'in_progress', 'period_end' => '2026-09-30']);
    $identity = ['journal_id' => $context->journal_id, 'bank_account_id' => $context->bank_account_id, 'liquidity_account_id' => $context->liquidity_account_id];
    $affected = lifecycleRecon(['status' => 'completed', 'period_end' => '2026-10-31', 'period_start' => '2026-10-01', ...$identity]);
    $service = app(ReconciliationLifecycleService::class);

    $impact = $service->markAffectedByHistoricalCorrection($context, '2026-09-15', 'Backdated bank correction', 'bank_correction');

    expect(array_column($impact, 'id'))->toBe([$affected->id])
        ->and($affected->refresh()->status)->toBe('stale')
        ->and($affected->events()->first()->payload['cause_event'])->toBe('bank_correction')
        ->and($affected->events()->first()->payload['effective_date'])->toBe('2026-09-15')
        ->and($affected->events()->first()->payload['previous_status'])->toBe('completed');
});

it('requires authorization and an audit reason to reopen a stale period for rework', function (): void {
    $reconciliation = lifecycleRecon(['status' => 'stale', 'stale_reason' => 'September was corrected']);
    $service = app(ReconciliationLifecycleService::class);

    expect(fn () => $service->reworkStale($reconciliation, ''))
        ->toThrow(InvalidArgumentException::class, 'reason is required');

    $reworked = $service->reworkStale($reconciliation, 'Reperform October after the September correction');

    expect($reworked->status)->toBe('reopened')
        ->and($reworked->reopen_reason)->toBe('Reperform October after the September correction')
        ->and($reworked->stale_reason)->toBe('September was corrected')
        ->and($reworked->events()->where('event', 'stale_rework_started')->value('payload'))->toMatchArray([
            'stale_reason' => 'September was corrected',
        ]);
});

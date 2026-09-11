<?php

require_once __DIR__.'/ReconTestSupport.php';

use App\Filament\Pages\Recon\ListReconciliations;
use App\Filament\Pages\Recon\ReconcileWorkspace;
use App\Filament\Pages\Recon\ReconReport;
use App\Models\Recon\BankReconciliation;
use App\Models\Recon\StatementLine;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Webkul\Account\Models\BankStatement;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('accounts');
    $this->company = Company::query()->firstOrFail();
    $this->user = User::query()->firstOrFail();
    $this->user->forceFill(['default_company_id' => $this->company->id])->saveQuietly();
    $this->actingAs($this->user);
    config()->set('reconciliation.financial_mutations_enabled', true);
    config()->set('reconciliation.require_company_rollout', false);
});

function pageReconciliation(array $overrides = []): BankReconciliation
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

it('keeps the reconciliation entry point company-scoped and exposes the canonical page routes', function (): void {
    expect(ListReconciliations::getUrl())->toContain('bank-reconciliations')
        ->and(ReconcileWorkspace::getUrl(['record' => 42]))->toContain('reconcile');
});

it('keeps selection toggles deterministic across paginated workspace panes', function (): void {
    $page = new ReconcileWorkspace;

    $page->toggleBank(17);
    $page->toggleBank(17);
    $page->toggleBank(17);
    $page->toggleLedger(29);
    $page->toggleLedger(31);
    $page->toggleLedger(29);

    expect($page->bankSelection)->toBe([17])
        ->and($page->ledgerSelection)->toBe([31]);
});

it('blocks every workspace edit action for a completed reconciliation with a visible lock error', function (): void {
    $page = new ReconcileWorkspace;
    $page->record = pageReconciliation(['status' => 'completed']);

    foreach ([
        'runAutoMatch', 'confirmBasket', 'confirmMatch', 'rejectMatch', 'unmatchConfirmed',
        'postAdjustment', 'postTransferAdjustment', 'postFxAdjustment', 'postReturnedPaymentAdjustment',
        'classifyException', 'approveBankError', 'excludeDuplicate',
        'carryForwardException', 'resolveCorrectedException',
    ] as $method) {
        $arguments = match ($method) {
            'confirmMatch', 'rejectMatch', 'unmatchConfirmed', 'approveBankError', 'excludeDuplicate',
            'carryForwardException', 'resolveCorrectedException'                                      => [1],
            default                                                                                   => [],
        };

        expect(fn () => $page->{$method}(...$arguments))
            ->toThrow(InvalidArgumentException::class, 'Reconciliation is locked');
    }
});

it('enforces workspace authorization before invoking mutation services', function (): void {
    $reconciliation = pageReconciliation();
    $unauthorized = User::withoutEvents(fn (): User => User::factory()->create([
        'default_company_id' => $reconciliation->company_id,
    ]));
    $this->actingAs($unauthorized);

    $page = new ReconcileWorkspace;
    $page->record = $reconciliation;

    expect(fn () => $page->runAutoMatch())->toThrow(AuthorizationException::class)
        ->and(fn () => $page->confirmBasket())->toThrow(AuthorizationException::class);
});

it('enforces business policies even when the user has Filament page permissions', function (): void {
    $reconciliation = pageReconciliation();
    $pageViewer = User::withoutEvents(fn (): User => User::factory()->create([
        'default_company_id' => $reconciliation->company_id,
    ]));
    $pageViewer->givePermissionTo([
        'page_filament_list_reconciliations',
        'page_filament_reconcile_workspace',
        'page_filament_recon_report',
    ]);
    $this->actingAs($pageViewer);
    request()->query->set('record', $reconciliation->id);

    expect(fn () => (new ReconcileWorkspace)->mount())->toThrow(AuthorizationException::class)
        ->and(fn () => (new ReconReport)->mount())->toThrow(AuthorizationException::class)
        ->and(fn () => (new ListReconciliations)->mount())->toThrow(AuthorizationException::class)
        ->and($pageViewer->can('create', BankReconciliation::class))->toBeFalse();
});

it('treats stale cancelled and legacy sessions as read-only', function (): void {
    foreach (['stale', 'cancelled', 'legacy_unverified'] as $status) {
        $page = new ReconcileWorkspace;
        $page->record = pageReconciliation(['status' => $status]);

        expect($page->getSummaryProperty()['can_edit'])->toBeFalse()
            ->and(fn () => $page->runAutoMatch())
            ->toThrow(InvalidArgumentException::class, 'Reconciliation is locked');
    }
});

it('refreshes lifecycle state immediately after start review and return to preparation', function (): void {
    $page = new ReconcileWorkspace;
    $page->record = pageReconciliation(['status' => 'in_progress']);

    $page->startReview();
    expect($page->record->status)->toBe('review')
        ->and($page->getSummaryProperty()['status'])->toBe('review');

    $page->backToInProgress();
    expect($page->record->status)->toBe('in_progress')
        ->and($page->getSummaryProperty()['status'])->toBe('in_progress')
        ->and($page->getSummaryProperty()['can_edit'])->toBeTrue();
});

it('scopes exception and dedicated adjustment selectors to the reconciliation company and ledger identity', function (): void {
    $reconciliation = pageReconciliation();
    $statement = BankStatement::query()->create([
        'company_id'       => $reconciliation->company_id,
        'journal_id'       => $reconciliation->journal_id,
        'date'             => $reconciliation->period_end,
        'name'             => 'PAGE-SELECTORS-'.$reconciliation->id,
        'balance_start'    => 0,
        'balance_end'      => 0,
        'balance_end_real' => 0,
    ]);
    $reconciliation->update(['statement_id' => $statement->id]);
    $line = StatementLine::query()->create([
        'statement_id'       => $statement->id,
        'company_id'         => $reconciliation->company_id,
        'journal_id'         => $reconciliation->journal_id,
        'currency_id'        => $reconciliation->currency_id,
        'transaction_date'   => $reconciliation->period_end,
        'amount'             => '-4.0000',
        'amount_residual'    => '-4.0000',
        'import_status'      => 'accepted',
        'source_fingerprint' => hash('sha256', 'page-selector-line-'.$reconciliation->id),
    ]);
    $page = new ReconcileWorkspace;
    $page->record = $reconciliation->refresh();

    $reflection = new ReflectionClass($page);
    $statementOptions = $reflection->getMethod('statementLineOptions');
    $statementOptions->setAccessible(true);
    $accountOptions = $reflection->getMethod('accountOptions');
    $accountOptions->setAccessible(true);
    $liquidityOptions = $reflection->getMethod('postedLiquidityLineOptions');
    $liquidityOptions->setAccessible(true);

    $accounts = $accountOptions->invoke($page, ['income', 'expense', 'asset_cash', 'asset_current']);
    $liquidityLines = $liquidityOptions->invoke($page);
    $statementLines = $statementOptions->invoke($page);

    expect($statementLines)->toHaveKey($line->id);
    foreach (array_keys($accounts) as $id) {
        expect(DB::table('accounts_accounts')->where('id', $id)->where('deprecated', false)->exists())->toBeTrue();
    }
    foreach (array_keys($liquidityLines) as $id) {
        expect(DB::table('accounts_account_move_lines')->where('id', $id)
            ->where('company_id', $reconciliation->company_id)
            ->where('account_id', $reconciliation->liquidity_account_id)
            ->where('currency_id', $reconciliation->currency_id)
            ->exists())->toBeTrue();
    }
});

it('renders a completed report from the immutable snapshot rather than live statement rows', function (): void {
    $reconciliation = pageReconciliation(['status' => 'completed', 'balance_end_real' => '125.0000']);
    $payload = [
        'statement_lines' => [[
            'transaction_date'  => '2026-09-30', 'partner_name' => 'Original bank row',
            'payment_reference' => 'ORIGINAL', 'amount' => '125.0000', 'is_reconciled' => true,
        ]],
        'matches'           => [],
        'reconciling_items' => [],
        'adjustments'       => [],
        'calculation'       => [
            'adjusted_bank_balance' => '125.00', 'adjusted_book_balance' => '125.00',
            'difference'            => '0.00',
        ],
    ];
    DB::table('recon_reconciliation_snapshots')->insert([
        'company_id'   => $reconciliation->company_id, 'reconciliation_id' => $reconciliation->id,
        'version'      => 1, 'payload' => json_encode($payload),
        'payload_hash' => hash('sha256', json_encode($payload)), 'created_by' => auth()->id(),
        'created_at'   => now(),
    ]);

    $page = new ReconReport;
    $page->record = $reconciliation->refresh();
    $report = $page->getReportProperty();

    expect($report['snapshot_version'])->toBe(1)
        ->and($report['snapshot_hash'])->toBe(hash('sha256', json_encode($payload)))
        ->and($report['reconciled']->first()->payment_reference)->toBe('ORIGINAL');
});

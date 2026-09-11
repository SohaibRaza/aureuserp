<?php

require_once __DIR__.'/ReconTestSupport.php';

use App\Models\Recon\BankReconciliation;
use App\Models\Recon\ReconciliationRule;
use App\Models\Recon\StatementLine;
use App\Services\Recon\FeedIngestionService;
use App\Services\Recon\ReconciliationRuleEngine;
use Illuminate\Validation\ValidationException;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('accounts');
    $this->company = Company::query()->firstOrFail();
    $this->actingAs(User::query()->firstOrFail());
    config(['reconciliation.financial_mutations_enabled' => true, 'reconciliation.require_company_rollout' => false]);
});

function u9Reconciliation(): BankReconciliation
{
    return BankReconciliation::query()->create([
        ...validBankReconciliationAttributes(),
        'period_start'   => '2026-09-01',
        'period_end'     => '2026-09-30',
        'statement_date' => '2026-09-30',
    ]);
}

it('selects the first active rule by priority and records versions', function (): void {
    $reconciliation = u9Reconciliation();
    $line = StatementLine::query()->create([
        'company_id'         => $reconciliation->company_id,
        'journal_id'         => $reconciliation->journal_id,
        'currency_id'        => $reconciliation->currency_id,
        'transaction_date'   => '2026-09-10',
        'amount'             => '100.0000',
        'payment_reference'  => 'POS-123',
        'source_fingerprint' => hash('sha256', uniqid('', true)),
    ]);
    ReconciliationRule::query()->create([
        'company_id' => $reconciliation->company_id, 'name' => 'Fallback', 'priority' => 20,
        'conditions' => ['reference_contains' => 'pos'], 'action' => 'propose', 'approval_threshold' => 14,
    ]);
    $winner = ReconciliationRule::query()->create([
        'company_id' => $reconciliation->company_id, 'name' => 'Preferred', 'priority' => 10,
        'conditions' => ['reference_contains' => 'pos'], 'action' => 'auto_confirm', 'approval_threshold' => 16,
    ]);

    expect(app(ReconciliationRuleEngine::class)->forLine($reconciliation, $line)?->is($winner))->toBeTrue();
});

it('stages provider data without creating financial statement lines and rejects incomplete acceptance', function (): void {
    $reconciliation = u9Reconciliation();
    $batch = app(FeedIngestionService::class)->stage($reconciliation, [
        'event_id' => 'evt-u9-1', 'complete' => false, 'rows' => [], 'opening_balance' => '0', 'closing_balance' => '0',
    ]);

    expect($batch->status)->toBe('staged')
        ->and($batch->statementLines()->count())->toBe(0)
        ->and(fn () => app(FeedIngestionService::class)->accept($batch))->toThrow(InvalidArgumentException::class);
});

it('rejects provider batches from another company before staging', function (): void {
    $reconciliation = u9Reconciliation();
    $otherCompany = Company::query()->where('id', '<>', $reconciliation->company_id)->first()
        ?? Company::factory()->create(['currency_id' => $reconciliation->currency_id]);

    expect(fn () => app(FeedIngestionService::class)->stage($reconciliation, [
        'event_id' => 'evt-u9-cross-company', 'company_id' => $otherCompany->id, 'complete' => true,
    ]))->toThrow(InvalidArgumentException::class);
});

it('accepts a complete provider batch exactly once', function (): void {
    $reconciliation = u9Reconciliation();
    $payload = [
        'event_id'                  => 'evt-u9-idempotent',
        'complete'                  => true,
        'opening_balance'           => '10.00',
        'closing_balance'           => '11.00',
        'account_identifier'        => $reconciliation->bankAccount->account_number,
        'currency_code'             => $reconciliation->currency->code,
        'statement_start_date'      => '2026-09-01',
        'statement_end_date'        => '2026-09-30',
        'statement_date'            => '2026-09-30',
        'control_debit_total'       => '0.00',
        'control_credit_total'      => '1.00',
        'control_transaction_count' => 1,
        'rows'                      => [['date' => '2026-09-03', 'amount' => '1.00', 'reference' => 'FEED-1']],
    ];

    $feed = app(FeedIngestionService::class);
    $staged = $feed->stage($reconciliation, $payload);
    $accepted = $feed->accept($staged);
    $replayed = $feed->accept($staged->refresh());

    expect($accepted->status)->toBe('accepted')
        ->and($accepted->source_type)->toBe('bank_feed')
        ->and($accepted->source_identifier)->toBe('evt-u9-idempotent')
        ->and($accepted->account_identifier)->toBe($reconciliation->bankAccount->account_number)
        ->and($accepted->currency_code)->toBe($reconciliation->currency->code)
        ->and($accepted->parser_version)->toBe('provider-neutral-v1')
        ->and($accepted->source_metadata['provider_event_id'])->toBe('evt-u9-idempotent')
        ->and($replayed->id)->toBe($accepted->id)
        ->and(BankReconciliation::query()->findOrFail($reconciliation->id)->statement_id)->not->toBeNull()
        ->and(StatementLine::query()->where('import_batch_id', $accepted->id)->count())->toBe(1);
});

it('rejects a provider event replay whose financial payload changed', function (): void {
    $reconciliation = u9Reconciliation();
    $feed = app(FeedIngestionService::class);
    $payload = [
        'event_id'        => 'evt-u9-conflict', 'complete' => true,
        'opening_balance' => '10.00', 'closing_balance' => '11.00',
        'rows'            => [['date' => '2026-09-03', 'amount' => '1.00', 'reference' => 'FEED-1']],
    ];

    $feed->stage($reconciliation, $payload);

    expect(fn () => $feed->stage($reconciliation, [
        ...$payload,
        'closing_balance' => '12.00',
        'rows'            => [['date' => '2026-09-03', 'amount' => '2.00', 'reference' => 'FEED-1']],
    ]))->toThrow(InvalidArgumentException::class, 'Provider event identity was reused with different content.');
});

it('validates rule values and date ranges on the server', function (): void {
    $companyId = $this->company->id;

    expect(fn () => app(ReconciliationRuleEngine::class)->createVersion([
        'company_id'         => $companyId,
        'name'               => 'Invalid amount rule',
        'conditions'         => ['amount_min' => 'five'],
        'action'             => 'auto_confirm',
        'approval_threshold' => 14,
        'active_from'        => '2026-10-01',
        'active_until'       => '2026-09-01',
    ]))->toThrow(ValidationException::class);
});

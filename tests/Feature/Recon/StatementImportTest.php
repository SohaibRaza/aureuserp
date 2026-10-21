<?php

require_once __DIR__.'/ReconTestSupport.php';

use App\Models\Recon\BankReconciliation;
use App\Models\Recon\ReconciliationRollout;
use App\Models\Recon\ReconImportBatch;
use App\Models\Recon\StatementLine;
use App\Services\Recon\StatementImporter;
use Illuminate\Support\Facades\DB;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('accounts');
    $this->company = Company::query()->firstOrFail();
    $this->actingAs(User::query()->firstOrFail());
    config(['reconciliation.financial_mutations_enabled' => true, 'reconciliation.require_company_rollout' => false]);
});

function importReconciliation(): BankReconciliation
{
    return BankReconciliation::query()->create([
        ...validBankReconciliationAttributes(),
        'period_start' => '2026-09-01',
        'period_end'   => '2026-09-30',
    ]);
}

it('accepts mapped csv decimals and preserves source dates and evidence', function (): void {
    $batch = app(StatementImporter::class)->importCsv(importReconciliation(), [[
        'date'      => '2026-09-03', 'value_date' => '2026-09-04', 'amount' => '0.10',
        'reference' => 'BANK-1', 'external_id' => 'txn-1', 'partner_name' => 'Acme',
    ]], '10.00', '10.10');

    expect($batch->status)->toBe('accepted')
        ->and($batch->original_filename)->toBe('pasted.csv')
        ->and($batch->parser_version)->toBe('mapped-csv-v2')
        ->and($batch->account_identifier)->toBe($batch->reconciliation->bankAccount->account_number)
        ->and($batch->currency_code)->toBe($batch->reconciliation->currency->code)
        ->and($batch->control_debit_total)->toBe('0.0000')
        ->and($batch->control_credit_total)->toBe('0.1000')
        ->and($batch->control_transaction_count)->toBe(1)
        ->and($batch->source_storage_path)->toBe('database:recon_import_batches.source_metadata')
        ->and($batch->source_metadata['canonical_source'])->not->toBeEmpty()
        ->and(StatementLine::query()->where('import_batch_id', $batch->id)->first()->company_id)->toBe($batch->company_id)
        ->and(StatementLine::query()->where('import_batch_id', $batch->id)->first()->journal_id)->toBe($batch->journal_id)
        ->and(StatementLine::query()->where('import_batch_id', $batch->id)->first()->transaction_date->toDateString())->toBe('2026-09-03')
        ->and(StatementLine::query()->where('import_batch_id', $batch->id)->first()->value_date->toDateString())
        ->toBe('2026-09-04');
});

it('preserves case-sensitive bank transaction identities while rejecting an exact external id replay', function (): void {
    $importer = app(StatementImporter::class);
    $firstReconciliation = importReconciliation();
    $first = $importer->importCsv($firstReconciliation, [[
        'date' => '2026-09-03', 'amount' => '1.00', 'reference' => 'CASE-1', 'external_id' => 'Txn-A',
    ]], '10.00', '11.00');
    expect($first->status)->toBe('accepted');
    $firstReconciliation->update(['status' => 'completed']);
    $firstReconciliation->statement()->update(['is_completed' => true]);

    $secondReconciliation = BankReconciliation::query()->create([
        ...validBankReconciliationAttributes($firstReconciliation->journal),
        'period_start' => '2026-10-01',
        'period_end'   => '2026-10-31',
    ]);
    $second = $importer->importCsv($secondReconciliation, [[
        'date' => '2026-10-04', 'amount' => '2.00', 'reference' => 'CASE-2', 'external_id' => 'txn-a',
    ]], '11.00', '13.00');
    expect($second->status)->toBe('accepted')
        ->and($second->lines_duplicate)->toBe(0)
        ->and(StatementLine::query()->whereIn('import_batch_id', [$first->id, $second->id])->orderBy('id')->pluck('external_id')->all())
        ->toBe(['Txn-A', 'txn-a']);
    $secondReconciliation->update(['status' => 'completed']);
    $secondReconciliation->statement()->update(['is_completed' => true]);

    $thirdReconciliation = BankReconciliation::query()->create([
        ...validBankReconciliationAttributes($firstReconciliation->journal),
        'period_start' => '2026-11-01',
        'period_end'   => '2026-11-30',
    ]);
    $third = $importer->importCsv($thirdReconciliation, [[
        'date' => '2026-11-05', 'amount' => '3.00', 'reference' => 'CASE-3', 'external_id' => 'Txn-A',
    ]], '13.00', '16.00');
    expect($third->status)->toBe('quarantined')
        ->and($third->lines_duplicate)->toBe(1)
        ->and($third->lines_imported)->toBe(0)
        ->and(StatementLine::query()->where('import_batch_id', $third->id)->exists())->toBeFalse();
});

it('quarantines a transaction date outside the preserved reconciliation period', function (): void {
    $reconciliation = importReconciliation();

    $batch = app(StatementImporter::class)->importCsv($reconciliation, [[
        'date' => '2026-10-01', 'amount' => '1.00', 'reference' => 'OUTSIDE-1',
    ]], '10.00', '11.00');

    expect($batch->status)->toBe('quarantined')
        ->and($batch->error_report)->toContain('Row 1: transaction date is outside the reconciliation period.')
        ->and($reconciliation->refresh()->statement_id)->toBeNull()
        ->and($reconciliation->period_end->toDateString())->toBe('2026-09-30');
});

it('preserves the user reconciliation period for a partial-period statement', function (): void {
    $reconciliation = importReconciliation();

    $batch = app(StatementImporter::class)->importCsv($reconciliation, [[
        'date' => '2026-09-10', 'amount' => '25.00', 'reference' => 'PARTIAL-1',
    ]], '100.00', '125.00', options: [
        'statement_start_date' => '2026-09-10',
        'statement_end_date'   => '2026-09-10',
        'account_identifier'   => $reconciliation->bankAccount->account_number,
        'currency_code'        => $reconciliation->currency->code,
    ]);

    expect($batch->status)->toBe('accepted')
        ->and($batch->statement_start_date->toDateString())->toBe('2026-09-10')
        ->and($batch->statement_end_date->toDateString())->toBe('2026-09-10')
        ->and($reconciliation->refresh()->period_start->toDateString())->toBe('2026-09-01')
        ->and($reconciliation->period_end->toDateString())->toBe('2026-09-30');
});

it('adopts a different statement period only when explicitly requested', function (): void {
    $reconciliation = importReconciliation();

    $batch = app(StatementImporter::class)->importCsv($reconciliation, [[
        'date' => '2026-10-02', 'amount' => '25.00', 'reference' => 'ADOPT-1',
    ]], '100.00', '125.00', options: [
        'statement_start_date'   => '2026-10-01',
        'statement_end_date'     => '2026-10-31',
        'account_identifier'     => $reconciliation->bankAccount->account_number,
        'currency_code'          => $reconciliation->currency->code,
        'adopt_statement_period' => true,
        'period_change_reason'   => 'The bank issued an October statement.',
    ]);

    expect($batch->status)->toBe('accepted')
        ->and($reconciliation->refresh()->period_start->toDateString())->toBe('2026-10-01')
        ->and($reconciliation->period_end->toDateString())->toBe('2026-10-31')
        ->and($reconciliation->events()->where('event', 'statement_period_adopted')->exists())->toBeTrue();
});

it('quarantines a requested period change without an audit reason', function (): void {
    $reconciliation = importReconciliation();

    $batch = app(StatementImporter::class)->importCsv($reconciliation, [[
        'date' => '2026-10-02', 'amount' => '25.00', 'reference' => 'ADOPT-NO-REASON',
    ]], '100.00', '125.00', options: [
        'statement_start_date'   => '2026-10-01',
        'statement_end_date'     => '2026-10-31',
        'adopt_statement_period' => true,
    ]);

    expect($batch->status)->toBe('quarantined')
        ->and($batch->error_report)->toContain('A reason is required to adopt the statement period.')
        ->and($reconciliation->refresh()->period_start->toDateString())->toBe('2026-09-01')
        ->and($reconciliation->period_end->toDateString())->toBe('2026-09-30')
        ->and($reconciliation->events()->where('event', 'statement_period_adopted')->exists())->toBeFalse();
});

it('revalidates a quarantined period adoption after the missing control is corrected', function (): void {
    $reconciliation = importReconciliation();
    $rows = [['date' => '2026-10-02', 'amount' => '25.00', 'reference' => 'ADOPT-RETRY']];
    $baseOptions = [
        'statement_start_date'   => '2026-10-01',
        'statement_end_date'     => '2026-10-31',
        'adopt_statement_period' => true,
    ];
    $importer = app(StatementImporter::class);

    $quarantined = $importer->importCsv($reconciliation, $rows, '100.00', '125.00', options: $baseOptions);
    $accepted = $importer->importCsv($reconciliation, $rows, '100.00', '125.00', options: [
        ...$baseOptions,
        'period_change_reason' => 'The bank issued an October statement.',
    ]);

    expect($quarantined->status)->toBe('quarantined')
        ->and($accepted->id)->not->toBe($quarantined->id)
        ->and($accepted->status)->toBe('accepted')
        ->and($reconciliation->refresh()->period_start->toDateString())->toBe('2026-10-01')
        ->and($reconciliation->period_end->toDateString())->toBe('2026-10-31');
});

it('quarantines wrong identity and control totals without changing the period', function (): void {
    $reconciliation = importReconciliation();

    $batch = app(StatementImporter::class)->importCsv($reconciliation, [[
        'date' => '2026-09-03', 'amount' => '5.00', 'reference' => 'IDENTITY-1',
    ]], '10.00', '15.00', options: [
        'statement_start_date'       => '2026-09-01',
        'statement_end_date'         => '2026-09-30',
        'account_identifier'         => 'WRONG-ACCOUNT',
        'currency_code'              => 'XXX',
        'control_debit_total'        => '0.00',
        'control_credit_total'       => '99.00',
        'control_transaction_count'  => 2,
    ]);

    expect($batch->status)->toBe('quarantined')
        ->and($batch->error_report)->toContain('Statement account identifier does not match the reconciliation bank account.')
        ->and($batch->error_report)->toContain('Statement currency does not match the reconciliation currency.')
        ->and($batch->error_report)->toContain('Statement credit control total does not match the imported rows.')
        ->and($batch->error_report)->toContain('Statement transaction count does not match the imported rows.')
        ->and($reconciliation->refresh()->statement_id)->toBeNull()
        ->and($reconciliation->period_start->toDateString())->toBe('2026-09-01')
        ->and($reconciliation->period_end->toDateString())->toBe('2026-09-30');
});

it('quarantines out-of-period value dates and returns the same quarantine on retry', function (): void {
    $reconciliation = importReconciliation();
    $rows = [[
        'date' => '2026-09-30', 'value_date' => '2026-10-01', 'amount' => '1.00', 'reference' => 'VALUE-1',
    ]];
    $importer = app(StatementImporter::class);

    $first = $importer->importCsv($reconciliation, $rows, '10.00', '11.00');
    $second = $importer->importCsv($reconciliation, $rows, '10.00', '11.00', 'renamed.csv');

    expect($first->status)->toBe('quarantined')
        ->and($first->error_report)->toContain('Row 1: value date is outside the reconciliation period.')
        ->and($second->id)->toBe($first->id)
        ->and(ReconImportBatch::query()->where('reconciliation_id', $reconciliation->id)->count())->toBe(1);
});

it('rejects malformed amounts, arithmetic mismatches, and out-of-period dates without a statement', function (): void {
    $reconciliation = importReconciliation();
    $batch = app(StatementImporter::class)->importCsv($reconciliation, [[
        'date' => '2026-09-31', 'amount' => '1,20', 'reference' => 'bad',
    ]], '10.00', '11.20');

    expect($batch->status)->toBe('quarantined')
        ->and($batch->error_report)->toHaveCount(2)
        ->and($reconciliation->refresh()->statement_id)->toBeNull()
        ->and(DB::table('accounts_bank_statements')->where('name', 'like', 'BANK/'.$reconciliation->id.'/%')->exists())->toBeFalse();
});

it('returns the same accepted batch for repeated source content and does not replace an active statement', function (): void {
    $reconciliation = importReconciliation();
    $rows = [['date' => '2026-09-03', 'amount' => '1.00', 'reference' => 'BANK-1']];
    $importer = app(StatementImporter::class);
    $first = $importer->importCsv($reconciliation, $rows, '10.00', '11.00');
    $second = $importer->importCsv($reconciliation, $rows, '10.00', '11.00', 'renamed.csv');

    expect($second->id)->toBe($first->id)
        ->and(ReconImportBatch::query()->where('reconciliation_id', $reconciliation->id)->count())->toBe(1)
        ->and(fn (): ReconImportBatch => $importer->importCsv($reconciliation, [['date' => '2026-09-04', 'amount' => '2.00']], '11.00', '13.00'))
        ->toThrow(RuntimeException::class);
});

it('quarantines an exact file already accepted by another reconciliation', function (): void {
    $firstReconciliation = importReconciliation();
    $rows = [['date' => '2026-09-03', 'amount' => '1.00', 'reference' => 'FILE-DUP-1']];
    $importer = app(StatementImporter::class);
    $accepted = $importer->importCsv($firstReconciliation, $rows, '10.00', '11.00');
    $firstReconciliation->update(['status' => 'completed']);
    $firstReconciliation->statement()->update(['is_completed' => true]);

    $secondReconciliation = importReconciliation();
    $quarantined = $importer->importCsv($secondReconciliation, $rows, '10.00', '11.00', 'same-file-renamed.csv');
    $replayed = $importer->importCsv($secondReconciliation, $rows, '10.00', '11.00', 'another-name.csv');

    expect($accepted->status)->toBe('accepted')
        ->and($quarantined->status)->toBe('quarantined')
        ->and($quarantined->source_metadata['duplicate_of_batch_id'])->toBe($accepted->id)
        ->and($quarantined->error_report)->toContain('This statement file was already imported in reconciliation '.$firstReconciliation->id.'.')
        ->and($replayed->id)->toBe($quarantined->id)
        ->and($secondReconciliation->refresh()->statement_id)->toBeNull();
});

it('rejects previously accepted rows without creating another bank statement', function (): void {
    $firstReconciliation = importReconciliation();
    $importer = app(StatementImporter::class);
    $firstBatch = $importer->importCsv($firstReconciliation, [[
        'date' => '2026-09-03', 'amount' => '1.00', 'reference' => 'BANK-1', 'external_id' => 'txn-1',
    ]], '10.00', '11.00');

    $firstReconciliation->update(['status' => 'completed']);
    $firstReconciliation->statement()->update(['is_completed' => true]);

    $secondReconciliation = importReconciliation();
    $secondBatch = $importer->importCsv($secondReconciliation, [
        ['date' => '2026-09-03', 'amount' => '1.00', 'reference' => 'BANK-1', 'external_id' => 'txn-1'],
        ['date' => '2026-09-04', 'amount' => '2.00', 'reference' => 'BANK-2', 'external_id' => 'txn-2'],
    ], '11.00', '14.00');

    expect($secondBatch->status)->toBe('quarantined')
        ->and($secondBatch->lines_duplicate)->toBe(1)
        ->and($secondBatch->lines_imported)->toBe(0)
        ->and($secondReconciliation->refresh()->statement_id)->toBeNull()
        ->and(StatementLine::query()->where('import_batch_id', $secondBatch->id)->exists())->toBeFalse()
        ->and(StatementLine::query()->where('import_batch_id', $firstBatch->id)->count())->toBe(1);
});

it('rejects an empty statement without creating a bank statement', function (): void {
    $reconciliation = importReconciliation();

    $batch = app(StatementImporter::class)->importCsv($reconciliation, [], '10.00', '10.00');

    expect($batch->status)->toBe('quarantined')
        ->and($batch->error_report)->toContain('The statement contains no rows.')
        ->and($reconciliation->refresh()->statement_id)->toBeNull()
        ->and(StatementLine::query()->where('import_batch_id', $batch->id)->exists())->toBeFalse();
});

it('rejects duplicate rows within one statement before persistence', function (): void {
    $reconciliation = importReconciliation();
    $rows = [
        ['date' => '2026-09-03', 'amount' => '1.00', 'reference' => 'DUP-1'],
        ['date' => '2026-09-03', 'amount' => '1.00', 'reference' => 'DUP-1'],
    ];

    $batch = app(StatementImporter::class)->importCsv($reconciliation, $rows, '10.00', '12.00');

    expect($batch->status)->toBe('quarantined')
        ->and($batch->error_report)->toContain('Row 2: duplicate row in file.')
        ->and($reconciliation->refresh()->statement_id)->toBeNull()
        ->and(StatementLine::query()->where('import_batch_id', $batch->id)->exists())->toBeFalse();
});

it('rejects zero-value transaction rows', function (): void {
    $reconciliation = importReconciliation();

    $batch = app(StatementImporter::class)->importCsv($reconciliation, [[
        'date' => '2026-09-03', 'amount' => '0.00', 'reference' => 'ZERO-1',
    ]], '10.00', '10.00');

    expect($batch->status)->toBe('quarantined')
        ->and($batch->error_report)->toContain('Row 1: amount must not be zero.')
        ->and($reconciliation->refresh()->statement_id)->toBeNull();
});

it('blocks imports when the company rollout gate is not enabled', function (): void {
    config([
        'reconciliation.require_company_rollout'     => true,
        'reconciliation.financial_mutations_enabled' => true,
    ]);
    ReconciliationRollout::query()->updateOrCreate(
        ['company_id' => $this->company->id],
        ['enabled_at' => null, 'disabled_at' => now(), 'phase' => 'p0'],
    );

    expect(fn () => app(StatementImporter::class)->importCsv(
        importReconciliation(),
        [['date' => '2026-09-03', 'amount' => '1.00', 'reference' => 'GATED-1']],
        '10.00',
        '11.00',
    ))->toThrow(InvalidArgumentException::class, 'Financial reconciliation actions are temporarily disabled');
});

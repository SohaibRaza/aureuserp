<?php

require_once __DIR__.'/ReconTestSupport.php';

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Webkul\Support\Models\Company;

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('accounts');
    $this->company = Company::query()->firstOrFail();
});

it('reports legacy blockers without writing reconciliation state', function (): void {
    $journal = testBankJournal();
    DB::table('recon_bank_reconciliations')->insert([
        'company_id' => $this->company->id,
        'journal_id' => $journal->id,
        'name'       => 'LEGACY-AUDIT',
        'status'     => 'legacy_unverified',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $before = collect(['recon_bank_reconciliations', 'recon_matches', 'recon_adjustments', 'recon_events', 'recon_import_batches'])
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()]);

    $exit = Artisan::call('reconciliation:audit-legacy', ['--company' => $this->company->id, '--json' => true]);
    $after = $before->keys()->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('legacy_unverified')
        ->and($after->all())->toBe($before->all());
});

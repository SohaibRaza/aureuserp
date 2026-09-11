<?php

require_once __DIR__.'/ReconTestSupport.php';

use App\Models\Recon\ReconciliationRollout;
use App\Services\Recon\ReconciliationSafety;
use Illuminate\Support\Facades\Artisan;
use Webkul\Support\Models\Company;

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('accounts');
    $this->company = Company::query()->firstOrFail();
    testBankJournal();
    config([
        'reconciliation.financial_mutations_enabled' => true,
        'reconciliation.require_company_rollout'     => true,
    ]);
});

it('requires rollout evidence and supports a non-destructive company rollback', function (): void {
    expect(Artisan::call('reconciliation:validate-readiness', ['company' => $this->company->id, '--json' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('backup_confirmed_at_missing');

    ReconciliationRollout::query()->updateOrCreate(['company_id' => $this->company->id], [
        'phase'                    => 'p0',
        'backup_confirmed_at'      => now(),
        'staging_verified_at'      => now(),
        'controller_signed_off_at' => now(),
        'controller_name'          => 'Test Controller',
        'enabled_at'               => now(),
        'enabled_by'               => 'test-operator',
        'disabled_at'              => null,
    ]);

    expect(Artisan::call('reconciliation:validate-readiness', ['company' => $this->company->id, '--json' => true]))->toBe(0)
        ->and(app(ReconciliationSafety::class)->financialMutationsEnabled($this->company->id))->toBeTrue();

    $exit = Artisan::call('reconciliation:rollout', [
        'company'    => $this->company->id,
        '--operator' => 'test-operator',
        '--disable'  => true,
        '--reason'   => 'Rollback drill',
    ]);

    expect($exit)->toBe(0)
        ->and(app(ReconciliationSafety::class)->financialMutationsEnabled($this->company->id))->toBeFalse()
        ->and(ReconciliationRollout::query()->where('company_id', $this->company->id)->whereNotNull('disabled_at')->exists())->toBeTrue();
});

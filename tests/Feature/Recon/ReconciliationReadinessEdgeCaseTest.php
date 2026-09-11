<?php

require_once __DIR__.'/ReconTestSupport.php';

use App\Models\Recon\BankReconciliation;
use App\Models\Recon\ReconciliationRollout;
use App\Services\Recon\ReconciliationSafety;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Webkul\Support\Models\Company;

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('accounts');
    $this->company = Company::query()->firstOrFail();
    $this->journal = testBankJournal();
    config([
        'reconciliation.financial_mutations_enabled' => true,
        'reconciliation.require_company_rollout'     => true,
    ]);
});

function readinessFindingCodes(int $companyId): array
{
    Artisan::call('reconciliation:validate-readiness', [
        'company' => $companyId,
        '--json'  => true,
    ]);

    return collect(json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR)['findings'])
        ->pluck('code')
        ->all();
}

function createCompleteRollout(int $companyId): ReconciliationRollout
{
    return ReconciliationRollout::query()->create([
        'company_id'               => $companyId,
        'phase'                    => 'p0',
        'backup_confirmed_at'      => now(),
        'staging_verified_at'      => now(),
        'controller_signed_off_at' => now(),
        'controller_name'          => 'Independent Controller',
        'enabled_at'               => now(),
        'enabled_by'               => 'release-operator',
    ]);
}

function createCompanyWithoutBankJournal(): Company
{
    return Company::query()->create([
        'name'        => fake()->unique()->company(),
        'company_id'  => fake()->unique()->uuid(),
        'currency_id' => test()->company->currency_id,
        'creator_id'  => DB::table('users')->value('id'),
        'is_active'   => true,
    ]);
}

it('returns invalid for a nonexistent company without creating rollout evidence', function (): void {
    $missingCompanyId = (int) Company::query()->max('id') + 1000;

    $exit = Artisan::call('reconciliation:validate-readiness', [
        'company' => $missingCompanyId,
        '--json'  => true,
    ]);

    expect($exit)->toBe(Command::INVALID)
        ->and(ReconciliationRollout::query()->where('company_id', $missingCompanyId)->exists())->toBeFalse();
});

it('reports both global and company rollout configuration gates independently', function (): void {
    createCompleteRollout($this->company->id);
    config([
        'reconciliation.financial_mutations_enabled' => false,
        'reconciliation.require_company_rollout'     => false,
    ]);

    $codes = readinessFindingCodes($this->company->id);

    expect($codes)->toContain('global_gate_disabled', 'company_gate_disabled')
        ->and(Artisan::call('reconciliation:validate-readiness', [
            'company' => $this->company->id,
            '--json'  => true,
        ]))->toBe(Command::FAILURE);
});

it('fails readiness and refuses enablement when a bank journal lacks required identity', function (): void {
    $this->journal->update(['bank_account_id' => null]);

    expect(readinessFindingCodes($this->company->id))->toContain('missing_bank_configuration');

    $exit = Artisan::call('reconciliation:rollout', [
        'company'                => $this->company->id,
        '--operator'             => 'release-operator',
        '--phase'                => 'p0',
        '--backup-confirmed'     => true,
        '--staging-verified'     => true,
        '--controller-signoff'   => 'Independent Controller',
    ]);

    expect($exit)->toBe(Command::FAILURE)
        ->and(ReconciliationRollout::query()->where('company_id', $this->company->id)->exists())->toBeFalse();
});

it('fails readiness when the company has no bank journal at all', function (): void {
    $company = createCompanyWithoutBankJournal();
    createCompleteRollout($company->id);

    expect(readinessFindingCodes($company->id))->toContain('missing_bank_configuration')
        ->and(Artisan::call('reconciliation:validate-readiness', [
            'company' => $company->id,
            '--json'  => true,
        ]))->toBe(Command::FAILURE);
});

it('fails readiness and refuses enablement while legacy state remains unverified', function (): void {
    BankReconciliation::query()->create([
        ...validBankReconciliationAttributes($this->journal),
        'status' => 'legacy_unverified',
    ]);

    expect(readinessFindingCodes($this->company->id))->toContain('legacy_unverified');

    $exit = Artisan::call('reconciliation:rollout', [
        'company'                => $this->company->id,
        '--operator'             => 'release-operator',
        '--phase'                => 'p0',
        '--backup-confirmed'     => true,
        '--staging-verified'     => true,
        '--controller-signoff'   => 'Independent Controller',
    ]);

    expect($exit)->toBe(Command::FAILURE)
        ->and(ReconciliationRollout::query()->where('company_id', $this->company->id)->exists())->toBeFalse();
});

it('requires complete enablement evidence and rejects invalid phases without partial records', function (array $arguments): void {
    $exit = Artisan::call('reconciliation:rollout', [
        'company' => $this->company->id,
        ...$arguments,
    ]);

    expect($exit)->toBe(Command::INVALID)
        ->and(ReconciliationRollout::query()->where('company_id', $this->company->id)->exists())->toBeFalse();
})->with([
    'operator missing' => [[
        '--phase'              => 'p0', '--backup-confirmed' => true, '--staging-verified' => true,
        '--controller-signoff' => 'Independent Controller',
    ]],
    'backup evidence missing' => [[
        '--operator'           => 'release-operator', '--phase' => 'p0', '--staging-verified' => true,
        '--controller-signoff' => 'Independent Controller',
    ]],
    'staging evidence missing' => [[
        '--operator'           => 'release-operator', '--phase' => 'p0', '--backup-confirmed' => true,
        '--controller-signoff' => 'Independent Controller',
    ]],
    'controller signoff missing' => [[
        '--operator'         => 'release-operator', '--phase' => 'p0', '--backup-confirmed' => true,
        '--staging-verified' => true,
    ]],
    'phase is invalid' => [[
        '--operator'         => 'release-operator', '--phase' => 'production', '--backup-confirmed' => true,
        '--staging-verified' => true, '--controller-signoff' => 'Independent Controller',
    ]],
]);

it('requires a disable reason and preserves rollout evidence across repeated disable commands', function (): void {
    $rollout = createCompleteRollout($this->company->id);

    $withoutReason = Artisan::call('reconciliation:rollout', [
        'company'    => $this->company->id,
        '--operator' => 'release-operator',
        '--disable'  => true,
    ]);
    expect($withoutReason)->toBe(Command::INVALID)
        ->and($rollout->refresh()->disabled_at)->toBeNull();

    $first = Artisan::call('reconciliation:rollout', [
        'company'    => $this->company->id,
        '--operator' => 'release-operator',
        '--disable'  => true,
        '--reason'   => 'Rollback drill evidence',
    ]);
    $disabledAt = $rollout->refresh()->disabled_at;

    $second = Artisan::call('reconciliation:rollout', [
        'company'    => $this->company->id,
        '--operator' => 'different-operator',
        '--disable'  => true,
        '--reason'   => 'Must not overwrite original evidence',
    ]);

    expect($first)->toBe(Command::SUCCESS)
        ->and($second)->toBe(Command::SUCCESS)
        ->and($rollout->refresh()->enabled_at)->not->toBeNull()
        ->and($rollout->disabled_at?->equalTo($disabledAt))->toBeTrue()
        ->and($rollout->disabled_by)->toBe('release-operator')
        ->and($rollout->disable_reason)->toBe('Rollback drill evidence');
});

it('enables a ready company idempotently without replacing original approval evidence', function (): void {
    $arguments = [
        'company'              => $this->company->id,
        '--operator'           => 'release-operator',
        '--phase'              => 'p1',
        '--backup-confirmed'   => true,
        '--staging-verified'   => true,
        '--controller-signoff' => 'Independent Controller',
    ];

    $first = Artisan::call('reconciliation:rollout', $arguments);
    $rollout = ReconciliationRollout::query()->where('company_id', $this->company->id)->firstOrFail();
    $enabledAt = $rollout->enabled_at;

    $second = Artisan::call('reconciliation:rollout', [
        ...$arguments,
        '--operator'           => 'later-operator',
        '--phase'              => 'p2',
        '--controller-signoff' => 'Later Controller',
    ]);

    expect($first)->toBe(Command::SUCCESS)
        ->and($second)->toBe(Command::SUCCESS)
        ->and($rollout->refresh()->enabled_at?->equalTo($enabledAt))->toBeTrue()
        ->and($rollout->enabled_by)->toBe('release-operator')
        ->and($rollout->phase)->toBe('p1')
        ->and($rollout->controller_name)->toBe('Independent Controller')
        ->and(Artisan::call('reconciliation:validate-readiness', [
            'company' => $this->company->id,
            '--json'  => true,
        ]))->toBe(Command::SUCCESS);
});

it('keeps rollout enablement and rollback strictly company scoped', function (): void {
    $otherCompanyId = createCompanyWithoutBankJournal()->id;
    createCompleteRollout($this->company->id);
    createCompleteRollout($otherCompanyId);

    $exit = Artisan::call('reconciliation:rollout', [
        'company'    => $this->company->id,
        '--operator' => 'release-operator',
        '--disable'  => true,
        '--reason'   => 'Company-specific rollback',
    ]);
    $safety = app(ReconciliationSafety::class);

    expect($exit)->toBe(Command::SUCCESS)
        ->and($safety->financialMutationsEnabled($this->company->id))->toBeFalse()
        ->and($safety->financialMutationsEnabled($otherCompanyId))->toBeTrue()
        ->and(ReconciliationRollout::query()->where('company_id', $otherCompanyId)->whereNull('disabled_at')->exists())->toBeTrue();
});

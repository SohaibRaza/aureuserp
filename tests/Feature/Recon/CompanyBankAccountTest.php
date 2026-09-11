<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Webkul\Account\Models\Journal;
use Webkul\Accounting\Filament\Clusters\Configuration\Resources\CompanyBankAccountResource;
use Webkul\Accounting\Models\BankAccount;
use Webkul\Partner\Models\BankAccount as PartnerBankAccount;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Bank;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\CompanyContext;

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('accounts');
    $this->company = Company::query()->firstOrFail();
    $this->user = User::query()->firstOrFail();
    $this->actingAs($this->user);
    $this->user->forceFill(['default_company_id' => $this->company->id])->saveQuietly();
    $this->user->allowedCompanies()->syncWithoutDetaching([$this->company->id]);
    session([CompanyContext::SESSION_KEY => [$this->company->id]]);
    app()->forgetInstance(CompanyContext::class);
});

it('stores company bank accounts without exposing them as partner bank details', function (): void {
    $bankAccount = BankAccount::query()->create([
        'account_number' => fake()->unique()->numerify('PK################'),
        'partner_id'     => null,
        'company_id'     => $this->company->id,
        'currency_id'    => $this->company->currency_id,
        'bank_id'        => Bank::factory()->create()->id,
        'source_type'    => 'manual',
        'is_active'      => true,
    ]);

    expect($bankAccount->account_holder_name)->toBe($this->company->name)
        ->and(CompanyBankAccountResource::getEloquentQuery()->whereKey($bankAccount)->exists())->toBeTrue()
        ->and(PartnerBankAccount::query()->whereHas('partner')->whereKey($bankAccount)->exists())->toBeFalse();
});

it('allows administrators to manage company bank accounts', function (): void {
    expect($this->user->can('view_any_accounting_bank::account'))->toBeTrue()
        ->and($this->user->can('create_accounting_bank::account'))->toBeTrue()
        ->and(CompanyBankAccountResource::canViewAny())->toBeTrue();
});

it('rejects a journal bank account that is not owned by its company', function (): void {
    $partnerBankAccount = PartnerBankAccount::factory()->create();
    $journal = Journal::query()->where('company_id', $this->company->id)->where('type', 'bank')->firstOrFail();

    expect(fn () => $journal->update(['bank_account_id' => $partnerBankAccount->id]))
        ->toThrow(ValidationException::class, 'Select an active bank account owned by this company');
});

it('enforces one owner for every bank account at the database boundary', function (): void {
    expect(fn () => DB::table('partners_bank_accounts')->insert([
        'account_number'      => fake()->unique()->numerify('PK################'),
        'account_holder_name' => 'Invalid owner',
        'partner_id'          => null,
        'company_id'          => null,
        'currency_id'         => null,
        'bank_id'             => Bank::factory()->create()->id,
        'is_active'           => true,
        'can_send_money'      => false,
        'created_at'          => now(),
        'updated_at'          => now(),
    ]))->toThrow(QueryException::class);
});

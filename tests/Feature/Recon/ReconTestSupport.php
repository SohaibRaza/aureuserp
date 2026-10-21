<?php

use Illuminate\Support\Facades\DB;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Security\Models\User;
use Webkul\Support\Services\CompanyContext;

function actAsReconciliationReviewer(int $companyId): void
{
    $reviewer = User::query()->create([
        'name'                => 'Independent reconciliation reviewer',
        'email'               => fake()->unique()->safeEmail(),
        'password'            => 'synthetic-reviewer-password',
        'default_company_id'  => $companyId,
        'resource_permission' => 'global',
        'is_active'           => true,
    ]);
    $reviewer->givePermissionTo(['reconciliation.reopen', 'reconciliation.complete']);
    test()->actingAs($reviewer);
    app()->forgetInstance(CompanyContext::class);
}

function validBankReconciliationAttributes(?Journal $journal = null): array
{
    $journal ??= testBankJournal();

    return [
        'company_id'           => test()->company->id,
        'journal_id'           => $journal->id,
        'bank_account_id'      => $journal->bank_account_id,
        'liquidity_account_id' => $journal->default_account_id,
        'currency_id'          => test()->company->currency_id,
        'name'                 => fake()->unique()->bothify('RECON-####'),
        'status'               => 'draft',
        'period_start'         => '2026-08-01',
        'period_end'           => '2026-08-31',
    ];
}

function testBankJournal(bool $dedicated = false): Journal
{
    $bankId = DB::table('banks')->insertGetId([
        'name'       => 'Test Bank',
        'code'       => fake()->unique()->bothify('BANK-####'),
        'creator_id' => DB::table('users')->value('id'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $bankAccountId = DB::table('partners_bank_accounts')->insertGetId([
        'account_number'      => fake()->unique()->numerify('TEST-############'),
        'account_holder_name' => 'Test Company',
        'is_active'           => true,
        'can_send_money'      => true,
        'creator_id'          => DB::table('users')->value('id'),
        'partner_id'          => null,
        'company_id'          => test()->company->id,
        'currency_id'         => test()->company->currency_id,
        'source_type'         => 'manual',
        'bank_id'             => $bankId,
        'created_at'          => now(),
        'updated_at'          => now(),
    ]);

    $journal = Journal::query()->where('type', 'bank')->orderBy('id')->firstOrFail();
    if ($dedicated) {
        $account = Account::query()->withoutGlobalScopes()->findOrFail($journal->default_account_id)->replicate();
        $account->forceFill([
            'name' => 'Isolated concurrency bank',
            'code' => fake()->unique()->bothify('CON-########'),
        ])->save();
        $account->companies()->sync([test()->company->id]);
        $journal = $journal->replicate();
        $journal->forceFill([
            'name'               => 'Isolated concurrency journal',
            'code'               => fake()->unique()->bothify('C####'),
            'sort'               => fake()->unique()->bothify('C####'),
            'default_account_id' => $account->id,
        ]);
    }
    $journal->forceFill(['bank_account_id' => $bankAccountId])->save();

    return $journal->refresh();
}

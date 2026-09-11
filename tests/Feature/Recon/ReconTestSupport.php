<?php

use Illuminate\Support\Facades\DB;
use Webkul\Account\Models\Journal;

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

function testBankJournal(): Journal
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

    $journal = Journal::query()->where('type', 'bank')->firstOrFail();
    $journal->update(['bank_account_id' => $bankAccountId]);

    return $journal->refresh();
}

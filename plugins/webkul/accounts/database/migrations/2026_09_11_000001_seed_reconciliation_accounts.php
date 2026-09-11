<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounts_accounts')
            || ! Schema::hasTable('accounts_account_companies')
            || ! Schema::hasTable('companies')) {
            return;
        }

        $creatorId = DB::table('users')->value('id');
        $now = now();
        $accounts = [
            ['code' => '101401', 'name' => 'Bank', 'type' => 'asset_cash', 'note' => 'Bank and deposit accounts.'],
            ['code' => '101402', 'name' => 'Bank Suspense Account', 'type' => 'asset_current', 'note' => 'Temporary holding account for unidentified bank activity.'],
            ['code' => '101403', 'name' => 'Outstanding Receipts', 'type' => 'asset_current', 'note' => 'Receipts pending settlement into the bank.'],
            ['code' => '101404', 'name' => 'Outstanding Payments', 'type' => 'asset_current', 'note' => 'Payments pending settlement by the bank.'],
            ['code' => '101701', 'name' => 'Liquidity Transfer', 'type' => 'asset_current', 'note' => 'Transfers between bank and cash liquidity accounts.'],
            ['code' => '300100', 'name' => 'Opening Balance Equity', 'type' => 'equity', 'note' => 'Controlled counterpart for opening balance migration entries.'],
            ['code' => '451000', 'name' => 'Interest Income', 'type' => 'income', 'note' => 'Interest earned on bank and cash balances.'],
            ['code' => '620000', 'name' => 'Bank Fees', 'type' => 'expense', 'note' => 'Bank charges and service fees.'],
        ];

        DB::table('companies')->orderBy('id')->eachById(function (object $company) use ($accounts, $creatorId, $now): void {
            foreach ($accounts as $definition) {
                $account = DB::table('accounts_accounts as account')
                    ->join('accounts_account_companies as mapping', 'mapping.account_id', '=', 'account.id')
                    ->where('mapping.company_id', $company->id)
                    ->where('account.code', $definition['code'])
                    ->where('account.account_type', $definition['type'])
                    ->where('account.deprecated', false)
                    ->orderBy('account.id')
                    ->first(['account.id']);

                if (! $account) {
                    $accountId = DB::table('accounts_accounts')->insertGetId([
                        'currency_id'  => $company->currency_id,
                        'creator_id'   => $creatorId,
                        'account_type' => $definition['type'],
                        'name'         => $definition['name'],
                        'code'         => $definition['code'],
                        'note'         => $definition['note'],
                        'deprecated'   => false,
                        'reconcile'    => false,
                        'non_trade'    => false,
                        'created_at'   => $now,
                        'updated_at'   => $now,
                    ]);
                } else {
                    $accountId = (int) $account->id;
                }

                $mapped = DB::table('accounts_account_companies')
                    ->where('account_id', $accountId)
                    ->where('company_id', $company->id)
                    ->exists();

                if (! $mapped) {
                    DB::table('accounts_account_companies')->insert([
                        'account_id' => $accountId,
                        'company_id' => $company->id,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Deliberately non-destructive: an account may have been used in GL
        // entries or customized after this forward seed ran.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners_bank_accounts', function (Blueprint $table): void {
            $table->foreignId('company_id')
                ->nullable()
                ->after('partner_id')
                ->constrained('companies')
                ->restrictOnDelete();
            $table->foreignId('currency_id')
                ->nullable()
                ->after('company_id')
                ->constrained('currencies')
                ->restrictOnDelete();
            $table->string('source_type', 32)->nullable()->after('currency_id');
            $table->string('source_identifier')->nullable()->after('source_type');
            $table->index(['company_id', 'is_active'], 'partner_bank_company_active_index');
            $table->foreignId('partner_id')->nullable()->change();
        });

        $this->promoteJournalBankAccounts();

        DB::statement(<<<'SQL'
            ALTER TABLE partners_bank_accounts
            ADD CONSTRAINT partner_bank_account_one_owner_check
            CHECK ((company_id IS NULL) <> (partner_id IS NULL))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE partners_bank_accounts
            ADD CONSTRAINT partner_bank_account_company_fields_check
            CHECK (company_id IS NULL OR (currency_id IS NOT NULL AND source_type IS NOT NULL))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE partners_bank_accounts DROP CONSTRAINT IF EXISTS partner_bank_account_company_fields_check');
        DB::statement('ALTER TABLE partners_bank_accounts DROP CONSTRAINT IF EXISTS partner_bank_account_one_owner_check');

        DB::table('partners_bank_accounts')
            ->whereNotNull('company_id')
            ->orderBy('id')
            ->eachById(function (object $account): void {
                $partnerId = DB::table('companies')->where('id', $account->company_id)->value('partner_id');

                if ($partnerId) {
                    DB::table('partners_bank_accounts')->where('id', $account->id)->update([
                        'partner_id' => $partnerId,
                    ]);
                }
            });

        Schema::table('partners_bank_accounts', function (Blueprint $table): void {
            $table->dropIndex('partner_bank_company_active_index');
            $table->dropForeign(['company_id']);
            $table->dropForeign(['currency_id']);
            $table->dropColumn(['company_id', 'currency_id', 'source_type', 'source_identifier']);
            $table->foreignId('partner_id')->nullable(false)->change();
        });
    }

    private function promoteJournalBankAccounts(): void
    {
        if (! Schema::hasTable('accounts_journals')) {
            return;
        }

        $accounts = DB::table('accounts_journals as journal')
            ->join('companies as company', 'company.id', '=', 'journal.company_id')
            ->join('partners_bank_accounts as bank_account', 'bank_account.id', '=', 'journal.bank_account_id')
            ->whereColumn('bank_account.partner_id', 'company.partner_id')
            ->get([
                'bank_account.id as bank_account_id',
                'journal.company_id',
                'journal.currency_id as journal_currency_id',
                'company.currency_id as company_currency_id',
            ])
            ->groupBy('bank_account_id');

        foreach ($accounts as $bankAccountId => $journals) {
            $companyIds = $journals->pluck('company_id')->unique()->values();
            $currencyIds = $journals
                ->map(fn (object $journal) => $journal->journal_currency_id ?: $journal->company_currency_id)
                ->filter()
                ->unique()
                ->values();

            if ($companyIds->count() !== 1 || $currencyIds->count() !== 1) {
                continue;
            }

            DB::table('partners_bank_accounts')->where('id', $bankAccountId)->update([
                'partner_id'  => null,
                'company_id'  => $companyIds->first(),
                'currency_id' => $currencyIds->first(),
                'source_type' => 'manual',
            ]);
        }
    }
};

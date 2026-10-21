<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Webkul\Support\Database\ImmutableHistory;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE recon_bank_reconciliations DROP INDEX recon_one_active_session_per_bank, MODIFY COLUMN recon_active_bank_account_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status IN ('draft', 'in_progress', 'review', 'reopen_requested', 'reopened') THEN bank_account_id ELSE NULL END) VIRTUAL, ADD UNIQUE INDEX recon_one_active_session_per_bank (recon_active_bank_account_id)");
        } else {
            DB::statement('DROP INDEX IF EXISTS recon_one_active_session_per_bank');
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX recon_one_active_session_per_bank
                ON recon_bank_reconciliations (bank_account_id)
                WHERE bank_account_id IS NOT NULL
                  AND status IN ('draft', 'in_progress', 'review', 'reopen_requested', 'reopened')
            SQL);
        }
    }

    public function down(): void
    {
        ImmutableHistory::refuseMySqlRollback();

        DB::statement('DROP INDEX IF EXISTS recon_one_active_session_per_bank');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX recon_one_active_session_per_bank
            ON recon_bank_reconciliations (bank_account_id)
            WHERE bank_account_id IS NOT NULL
              AND status IN ('draft', 'in_progress', 'review', 'reopen_requested', 'reopened', 'stale')
        SQL);
    }
};

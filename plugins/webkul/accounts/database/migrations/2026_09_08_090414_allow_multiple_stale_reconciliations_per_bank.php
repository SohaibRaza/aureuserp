<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS recon_one_active_session_per_bank');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX recon_one_active_session_per_bank
            ON recon_bank_reconciliations (bank_account_id)
            WHERE bank_account_id IS NOT NULL
              AND status IN ('draft', 'in_progress', 'review', 'reopen_requested', 'reopened')
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS recon_one_active_session_per_bank');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX recon_one_active_session_per_bank
            ON recon_bank_reconciliations (bank_account_id)
            WHERE bank_account_id IS NOT NULL
              AND status IN ('draft', 'in_progress', 'review', 'reopen_requested', 'reopened', 'stale')
        SQL);
    }
};

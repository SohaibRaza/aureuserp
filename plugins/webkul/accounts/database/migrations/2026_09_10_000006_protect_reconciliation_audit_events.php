<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Webkul\Support\Database\ImmutableHistory;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE recon_bank_reconciliations DROP INDEX recon_one_active_session_per_bank, MODIFY COLUMN recon_active_bank_account_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status IN ('draft', 'in_progress', 'review', 'reopened') THEN bank_account_id ELSE NULL END) VIRTUAL, ADD UNIQUE INDEX recon_one_active_session_per_bank (recon_active_bank_account_id)");
        } else {
            DB::statement('DROP INDEX IF EXISTS recon_one_active_session_per_bank');
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX recon_one_active_session_per_bank
                ON recon_bank_reconciliations (bank_account_id)
                WHERE bank_account_id IS NOT NULL
                  AND status IN ('draft', 'in_progress', 'review', 'reopened')
            SQL);
        }
        DB::statement(<<<'SQL'
            ALTER TABLE recon_reconciliation_snapshots
            ADD CONSTRAINT recon_snapshot_reconciliation_company_foreign
            FOREIGN KEY (reconciliation_id, company_id)
            REFERENCES recon_bank_reconciliations (id, company_id)
            ON DELETE RESTRICT
        SQL);

        if (DB::getDriverName() === 'mysql') {
            ImmutableHistory::protect('recon_events');

            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION recon_event_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Reconciliation events are append-only';
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS recon_event_immutable_trigger ON recon_events;
            CREATE TRIGGER recon_event_immutable_trigger
            BEFORE UPDATE OR DELETE ON recon_events
            FOR EACH ROW EXECUTE FUNCTION recon_event_immutable();
        SQL);
    }

    public function down(): void
    {
        ImmutableHistory::refuseMySqlRollback();

        DB::statement('ALTER TABLE recon_reconciliation_snapshots DROP CONSTRAINT IF EXISTS recon_snapshot_reconciliation_company_foreign');
        DB::unprepared('DROP TRIGGER IF EXISTS recon_event_immutable_trigger ON recon_events');
        DB::unprepared('DROP FUNCTION IF EXISTS recon_event_immutable()');
        DB::statement('DROP INDEX IF EXISTS recon_one_active_session_per_bank');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX recon_one_active_session_per_bank
            ON recon_bank_reconciliations (bank_account_id)
            WHERE bank_account_id IS NOT NULL
              AND status IN ('draft', 'in_progress', 'review', 'reopen_requested', 'reopened')
        SQL);
    }
};

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
              AND status IN ('draft', 'in_progress', 'review', 'reopened')
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE recon_reconciliation_snapshots
            ADD CONSTRAINT recon_snapshot_reconciliation_company_foreign
            FOREIGN KEY (reconciliation_id, company_id)
            REFERENCES recon_bank_reconciliations (id, company_id)
            ON DELETE RESTRICT
        SQL);

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

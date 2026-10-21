<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Support\Database\ImmutableHistory;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recon_reconciliation_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('reconciliation_id')->constrained('recon_bank_reconciliations')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('payload');
            $table->char('payload_hash', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['reconciliation_id', 'version'], 'recon_snapshot_version_unique');
            $table->unique(['reconciliation_id', 'payload_hash'], 'recon_snapshot_hash_unique');
        });

        if (DB::getDriverName() === 'mysql') {
            ImmutableHistory::protect('recon_reconciliation_snapshots');

            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION recon_snapshot_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Reconciliation snapshots are immutable';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER recon_snapshot_immutable_trigger
            BEFORE UPDATE OR DELETE ON recon_reconciliation_snapshots
            FOR EACH ROW EXECUTE FUNCTION recon_snapshot_immutable();
        SQL);
    }

    public function down(): void
    {
        ImmutableHistory::refuseMySqlRollback();

        DB::transaction(function (): void {
            DB::statement('LOCK TABLE recon_reconciliation_snapshots IN ACCESS EXCLUSIVE MODE');
            if (DB::table('recon_reconciliation_snapshots')->exists()) {
                throw new RuntimeException('Saved reconciliation snapshots require forward recovery; rollback cannot discard that history.');
            }

            DB::unprepared('DROP TRIGGER IF EXISTS recon_snapshot_immutable_trigger ON recon_reconciliation_snapshots');
            DB::unprepared('DROP FUNCTION IF EXISTS recon_snapshot_immutable()');
            Schema::dropIfExists('recon_reconciliation_snapshots');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE recon_import_batches DROP CONSTRAINT IF EXISTS recon_import_status_check');
        DB::statement(<<<'SQL'
            ALTER TABLE recon_import_batches
            ADD CONSTRAINT recon_import_status_check CHECK (
                status IS NULL OR status IN ('staged', 'validated', 'accepted', 'quarantined', 'rejected', 'legacy_unverified')
            )
        SQL);
    }

    public function down(): void
    {
        DB::table('recon_import_batches')->where('status', 'quarantined')->update(['status' => 'rejected']);
        DB::statement('ALTER TABLE recon_import_batches DROP CONSTRAINT IF EXISTS recon_import_status_check');
        DB::statement(<<<'SQL'
            ALTER TABLE recon_import_batches
            ADD CONSTRAINT recon_import_status_check CHECK (
                status IS NULL OR status IN ('staged', 'validated', 'accepted', 'rejected', 'legacy_unverified')
            )
        SQL);
    }
};

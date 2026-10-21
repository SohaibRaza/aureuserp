<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' || Schema::getColumnType('recon_reconciliation_snapshots', 'payload') !== 'json') {
            return;
        }

        if (DB::table('recon_reconciliation_snapshots')->exists()) {
            throw new RuntimeException('Existing MySQL snapshots may have lost their original JSON serialization. Review their evidence before changing snapshot storage; do not rewrite saved hashes.');
        }

        DB::statement('ALTER TABLE recon_reconciliation_snapshots MODIFY COLUMN payload LONGTEXT NOT NULL, ADD CONSTRAINT recon_snapshot_payload_json_check CHECK (JSON_VALID(payload))');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            throw new RuntimeException('Snapshot storage requires forward recovery; native MySQL JSON would change recorded evidence.');
        }
    }
};

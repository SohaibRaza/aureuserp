<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS recon_import_active_content_hash_unique');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX recon_import_active_content_hash_unique
            ON recon_import_batches (company_id, journal_id, content_hash)
            WHERE content_hash IS NOT NULL
              AND status IN ('staged', 'validated', 'accepted', 'quarantined')
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS recon_import_active_content_hash_unique');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX recon_import_active_content_hash_unique
            ON recon_import_batches (company_id, journal_id, content_hash)
            WHERE content_hash IS NOT NULL
              AND status IN ('staged', 'validated', 'accepted')
        SQL);
    }
};

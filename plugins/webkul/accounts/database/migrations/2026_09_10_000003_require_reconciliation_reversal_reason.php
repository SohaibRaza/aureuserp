<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE recon_matches ADD CONSTRAINT recon_match_reversal_reason_check CHECK (status <> 'reversed' OR NULLIF(BTRIM(reversal_reason), '') IS NOT NULL)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE recon_matches DROP CONSTRAINT IF EXISTS recon_match_reversal_reason_check');
    }
};

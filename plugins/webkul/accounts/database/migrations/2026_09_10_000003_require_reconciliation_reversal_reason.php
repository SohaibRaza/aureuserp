<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $trimFunction = DB::getDriverName() === 'mysql' ? 'TRIM' : 'BTRIM';
        DB::statement("ALTER TABLE recon_matches ADD CONSTRAINT recon_match_reversal_reason_check CHECK (status <> 'reversed' OR NULLIF({$trimFunction}(reversal_reason), '') IS NOT NULL)");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE recon_matches DROP CHECK recon_match_reversal_reason_check');

            return;
        }

        DB::statement('ALTER TABLE recon_matches DROP CONSTRAINT IF EXISTS recon_match_reversal_reason_check');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION recon_bank_opening_balance_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Bank opening-balance evidence is immutable';
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS recon_bank_opening_balance_immutable_trigger ON recon_bank_opening_balances;
            CREATE TRIGGER recon_bank_opening_balance_immutable_trigger
            BEFORE UPDATE OR DELETE ON recon_bank_opening_balances
            FOR EACH ROW EXECUTE FUNCTION recon_bank_opening_balance_immutable();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS recon_bank_opening_balance_immutable_trigger ON recon_bank_opening_balances');
        DB::unprepared('DROP FUNCTION IF EXISTS recon_bank_opening_balance_immutable()');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql'
            || (int) DB::selectOne('SHOW server_version_num')->server_version_num < 150000) {
            throw new RuntimeException('Inventory balance identity requires PostgreSQL 15 or newer.');
        }

        DB::statement('LOCK TABLE inventories_product_quantities IN SHARE ROW EXCLUSIVE MODE');

        $duplicate = DB::table('inventories_product_quantities')
            ->select('company_id', 'product_id', 'location_id', 'lot_id', 'package_id', 'partner_id')
            ->groupBy('company_id', 'product_id', 'location_id', 'lot_id', 'package_id', 'partner_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicate) {
            throw new RuntimeException('Duplicate Inventory balance identities require reviewed reconciliation before this migration. No balances were merged.');
        }

        DB::statement('ALTER TABLE inventories_product_quantities ADD CONSTRAINT inventories_product_quantities_identity_unique UNIQUE NULLS NOT DISTINCT (company_id, product_id, location_id, lot_id, package_id, partner_id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE inventories_product_quantities DROP CONSTRAINT inventories_product_quantities_identity_unique');
    }
};

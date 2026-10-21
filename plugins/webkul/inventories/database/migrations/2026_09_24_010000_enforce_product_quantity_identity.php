<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            if ((int) DB::selectOne('SHOW server_version_num')->server_version_num < 150000) {
                throw new RuntimeException('Inventory balance identity requires PostgreSQL 15 or newer.');
            }

            DB::statement('LOCK TABLE inventories_product_quantities IN SHARE ROW EXCLUSIVE MODE');
        } elseif ($driver !== 'mysql') {
            throw new RuntimeException('Inventory balance identity requires PostgreSQL 15 or newer, or MySQL 8.0.13 or newer.');
        }

        $duplicate = DB::table('inventories_product_quantities')
            ->select('company_id', 'product_id', 'location_id', 'lot_id', 'package_id', 'partner_id')
            ->groupBy('company_id', 'product_id', 'location_id', 'lot_id', 'package_id', 'partner_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicate) {
            throw new RuntimeException('Duplicate Inventory balance identities require reviewed reconciliation before this migration. No balances were merged.');
        }

        if ($driver === 'mysql') {
            // The null flag keeps NULL distinct from every real ID, including zero.
            // Hidden functional columns also keep model replication free of generated attributes.
            // MySQL 8.0 can crash rolling back a cascading SET NULL index collision.
            // Retain referenced owners/companies and install their restrictions atomically with the index.
            DB::statement('ALTER TABLE inventories_product_quantities
                DROP FOREIGN KEY inventories_product_quantities_company_id_foreign,
                DROP FOREIGN KEY inventories_product_quantities_partner_id_foreign,
                ADD CONSTRAINT inventories_quant_company_identity_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE RESTRICT,
                ADD CONSTRAINT inventories_quant_partner_identity_foreign FOREIGN KEY (partner_id) REFERENCES partners_partners (id) ON DELETE RESTRICT,
                ADD UNIQUE INDEX inventories_product_quantities_identity_unique (
                    (company_id IS NULL), (COALESCE(company_id, 0)), product_id, location_id,
                    (lot_id IS NULL), (COALESCE(lot_id, 0)),
                    (package_id IS NULL), (COALESCE(package_id, 0)),
                    (partner_id IS NULL), (COALESCE(partner_id, 0))
                ), LOCK = EXCLUSIVE');
        } else {
            DB::statement('ALTER TABLE inventories_product_quantities ADD CONSTRAINT inventories_product_quantities_identity_unique UNIQUE NULLS NOT DISTINCT (company_id, product_id, location_id, lot_id, package_id, partner_id)');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            throw new RuntimeException('MySQL stock identities and parent deletion restrictions require forward recovery; rollback cannot restore unsafe cascading updates.');
        } else {
            DB::statement('ALTER TABLE inventories_product_quantities DROP CONSTRAINT inventories_product_quantities_identity_unique');
        }
    }
};

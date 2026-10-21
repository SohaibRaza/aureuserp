<?php

use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Shafique\Logistics\Services\EvidenceStore;
use Webkul\Support\Database\ImmutableHistory;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../../../webkul/support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../../webkul/inventories/tests/Helpers/InventoryHelper.php';

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('logistics');
    Storage::fake('logistics-evidence');
});

it('refuses to roll back accepted evidence, policy, lifecycle, and legal hold history', function (): void {
    $user = SecurityHelper::authenticateWithPermissions([
        'create_logistics_evidence',
        'manage_logistics_evidence_retention',
        'place_logistics_evidence_legal_hold',
    ]);
    $company = Company::query()->findOrFail($user->default_company_id);
    $store = app(EvidenceStore::class);
    $store->approveRetentionPolicy($user, $company->id, 365, 'Synthetic rollback preservation policy');
    $file = UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.7\nsynthetic delivery evidence\n");
    $evidence = $store->store($user, $company->id, $file, 'delivery_receipt', 'rollback-preservation-evidence');
    $store->placeLegalHold($user, $evidence->id, 'Synthetic legal hold for rollback preservation');
    foreach (['logistics_evidence_retention_policies', 'logistics_evidence', 'logistics_evidence_lifecycle_events', 'logistics_evidence_legal_holds'] as $table) {
        $id = DB::table($table)->where('company_id', $company->id)->value('id');
        expect($id)->not->toBeNull();
        expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->update(['id' => $id])))
            ->toThrow(QueryException::class);
        expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $id)->delete()))
            ->toThrow(QueryException::class);
        expect(DB::table($table)->where('id', $id)->exists())->toBeTrue();
    }

    $migration = require __DIR__.'/../../database/migrations/2026_10_16_000200_create_logistics_evidence_records.php';

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward recovery');
    expect(DB::table('logistics_evidence_retention_policies')->where('company_id', $company->id)->count())->toBe(1)
        ->and(DB::table('logistics_evidence')->where('id', $evidence->id)->exists())->toBeTrue()
        ->and(DB::table('logistics_evidence_lifecycle_events')->where('evidence_id', $evidence->id)->exists())->toBeTrue()
        ->and(DB::table('logistics_evidence_legal_holds')->where('evidence_id', $evidence->id)->exists())->toBeTrue();
});

it('refuses to roll back populated outbound trip history before dropping execution tables', function (): void {
    $user = SecurityHelper::authenticateWithPermissions(['view_any_logistics_trip']);
    $warehouse = InventoryHelper::warehouse();
    $tripId = DB::table('logistics_trips')->insertGetId([
        'company_id'         => $user->default_company_id,
        'mode'               => 'dedicated_vehicle',
        'state'              => 'planning',
        'source_location_id' => $warehouse->lot_stock_location_id,
        'revision'           => 1,
        'created_by'         => $user->id,
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);
    $migration = require __DIR__.'/../../database/migrations/2026_10_16_000400_create_outbound_trip_execution.php';

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward recovery');
    expect(DB::table('logistics_trips')->where('id', $tripId)->exists())->toBeTrue();
    if (DB::getDriverName() === 'pgsql') {
        expect(DB::table('pg_trigger')->where('tgname', 'logistics_trip_move_links_immutable')->exists())->toBeTrue();
    } else {
        expect(DB::table('information_schema.TRIGGERS')->whereRaw('TRIGGER_SCHEMA = DATABASE()')->where('EVENT_OBJECT_TABLE', 'logistics_trip_move_links')->count())->toBe(2);
    }
});

it('refuses to roll back dispatch policy and receipt history before changing accepted fields', function (): void {
    $user = SecurityHelper::authenticateWithPermissions(['view_any_logistics_trip']);
    $warehouse = InventoryHelper::warehouse();
    $companyId = $user->default_company_id;
    $tripId = DB::table('logistics_trips')->insertGetId([
        'company_id'         => $companyId,
        'mode'               => 'dedicated_vehicle',
        'state'              => 'planning',
        'source_location_id' => $warehouse->lot_stock_location_id,
        'revision'           => 1,
        'created_by'         => $user->id,
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);
    $stopId = DB::table('logistics_trip_stops')->insertGetId([
        'trip_id'    => $tripId,
        'company_id' => $companyId,
        'sequence'   => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $policyId = DB::table('logistics_dispatch_policies')->insertGetId([
        'company_id'               => $companyId,
        'revision'                 => 1,
        'source_location_ids'      => json_encode([$warehouse->lot_stock_location_id]),
        'product_rules'            => json_encode([]),
        'require_load_evidence'    => true,
        'require_receipt_evidence' => true,
        'reason'                   => 'Synthetic rollback preservation policy',
        'approved_by'              => $user->id,
        'approved_at'              => now(),
    ]);
    $receiptId = DB::table('logistics_receipt_observations')->insertGetId([
        'trip_id'       => $tripId,
        'stop_id'       => $stopId,
        'company_id'    => $companyId,
        'recorded_by'   => $user->id,
        'request_key'   => 'rollback-preservation-receipt',
        'intent'        => 'received',
        'reporter_name' => 'Synthetic recipient',
        'recorded_at'   => now(),
    ]);
    $migration = require __DIR__.'/../../database/migrations/2026_10_16_000500_add_dispatch_readiness_controls.php';

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward recovery');
    expect(DB::table('logistics_dispatch_policies')->where('id', $policyId)->exists())->toBeTrue()
        ->and(DB::table('logistics_receipt_observations')->where('id', $receiptId)->value('reporter_name'))->toBe('Synthetic recipient')
        ->and(Schema::hasColumn('logistics_load_revisions', 'source_location_id'))->toBeTrue();
});

it('rolls back empty PostgreSQL history but refuses MySQL rollback before any DDL', function (): void {
    if (DB::getDriverName() === 'mysql') {
        foreach ([
            __DIR__.'/../../database/migrations/2026_09_19_000001_create_logistics_company_controls_table.php',
            __DIR__.'/../../database/migrations/2026_10_16_000100_add_logistics_control_revisions.php',
            __DIR__.'/../../database/migrations/2026_10_16_000200_create_logistics_evidence_records.php',
            __DIR__.'/../../database/migrations/2026_10_16_000300_create_logistics_commands_and_approvals.php',
            __DIR__.'/../../database/migrations/2026_10_16_000400_create_outbound_trip_execution.php',
            __DIR__.'/../../database/migrations/2026_10_16_000500_add_dispatch_readiness_controls.php',
            __DIR__.'/../../database/migrations/2026_10_21_000100_add_trip_recovery_history.php',
            __DIR__.'/../../../../webkul/inventories/database/migrations/2026_10_15_220000_create_operation_results_table.php',
            __DIR__.'/../../../../webkul/sales/database/migrations/2026_10_15_000100_create_sales_quantity_commitments_table.php',
            __DIR__.'/../../../../webkul/accounts/database/migrations/2026_09_07_000007_create_reconciliation_snapshots_table.php',
            __DIR__.'/../../../../webkul/accounts/database/migrations/2026_09_10_000006_protect_reconciliation_audit_events.php',
            base_path('database/migrations/2026_09_12_000002_protect_bank_opening_balance_evidence.php'),
            base_path('database/migrations/2026_09_11_000001_create_recon_bank_opening_balances_table.php'),
            base_path('database/migrations/2026_09_07_000001_create_recon_tables.php'),
        ] as $path) {
            $migration = require $path;
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward recovery');
                expect(DB::getQueryLog())->toBe([]);
            } finally {
                DB::disableQueryLog();
            }
        }

        return;
    }

    $schema = 'rollback_'.str_replace('-', '', (string) Str::uuid());
    $quotedSchema = '"'.$schema.'"';
    $originalSearchPath = DB::selectOne("SELECT current_setting('search_path') AS search_path")->search_path;

    DB::statement('CREATE SCHEMA '.$quotedSchema);
    DB::selectOne('SELECT set_config(?, ?, true)', ['search_path', $quotedSchema]);

    try {
        foreach ([
            'companies', 'users', 'inventories_operations', 'sales_orders', 'sales_order_lines',
            'inventories_locations', 'partners_partners', 'products_products', 'inventories_moves',
            'inventories_move_lines', 'unit_of_measures',
        ] as $parentTable) {
            DB::statement('CREATE TABLE '.$parentTable.' (id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY)');
        }

        $migrations = [
            require __DIR__.'/../../database/migrations/2026_10_16_000200_create_logistics_evidence_records.php',
            require __DIR__.'/../../database/migrations/2026_10_16_000300_create_logistics_commands_and_approvals.php',
            require __DIR__.'/../../../../webkul/inventories/database/migrations/2026_10_15_220000_create_operation_results_table.php',
            require __DIR__.'/../../../../webkul/sales/database/migrations/2026_10_15_000100_create_sales_quantity_commitments_table.php',
            require __DIR__.'/../../database/migrations/2026_10_16_000400_create_outbound_trip_execution.php',
            require __DIR__.'/../../database/migrations/2026_10_16_000500_add_dispatch_readiness_controls.php',
        ];

        foreach ($migrations as $migration) {
            $migration->up();
        }

        foreach (array_reverse($migrations) as $migration) {
            $migration->down();
        }

        expect(Schema::hasTable('logistics_evidence_retention_policies'))->toBeFalse()
            ->and(Schema::hasTable('logistics_command_records'))->toBeFalse()
            ->and(Schema::hasTable('inventories_operation_results'))->toBeFalse()
            ->and(Schema::hasTable('sales_quantity_commitments'))->toBeFalse()
            ->and(Schema::hasTable('logistics_trips'))->toBeFalse()
            ->and(Schema::hasTable('logistics_dispatch_policies'))->toBeFalse();
    } finally {
        DB::selectOne('SELECT set_config(?, ?, true)', ['search_path', $originalSearchPath]);
        DB::statement('DROP SCHEMA '.$quotedSchema.' CASCADE');
    }
});

it('upgrades existing PostgreSQL Logistics history without replacing valid protections or saved facts', function (): void {
    $migration = require __DIR__.'/../../database/migrations/2026_10_21_000200_protect_existing_logistics_history.php';
    if (DB::getDriverName() === 'mysql') {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $migration->up();
            expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward recovery');
            expect(DB::getQueryLog())->toBe([]);
        } finally {
            DB::disableQueryLog();
        }

        return;
    }

    $schema = 'history_upgrade_'.str_replace('-', '', (string) Str::uuid());
    $quotedSchema = '"'.$schema.'"';
    $originalSearchPath = DB::selectOne("SELECT current_setting('search_path') AS search_path")->search_path;
    DB::statement('CREATE SCHEMA '.$quotedSchema);
    DB::selectOne('SELECT set_config(?, ?, true)', ['search_path', $quotedSchema]);
    try {
        $tables = [
            'logistics_control_changes', 'logistics_evidence_retention_policies', 'logistics_evidence',
            'logistics_evidence_lifecycle_events', 'logistics_evidence_legal_holds', 'logistics_command_records',
            'logistics_scoped_approvals', 'logistics_approval_events',
        ];
        foreach ($tables as $table) {
            DB::statement("CREATE TABLE {$table} (id BIGINT PRIMARY KEY, payload TEXT NOT NULL)");
            DB::table($table)->insert(['id' => 1, 'payload' => 'accepted historical fact']);
        }
        ImmutableHistory::protect('logistics_control_changes');
        $existingTrigger = DB::selectOne("SELECT oid FROM pg_trigger WHERE tgrelid = 'logistics_control_changes'::regclass AND tgname = 'logistics_control_changes_immutable'")->oid;
        $existingFunction = DB::selectOne("SELECT 'logistics_control_changes_immutable()'::regprocedure::oid AS oid")->oid;

        $migration->up();
        $migration->up();
        expect(DB::selectOne("SELECT oid FROM pg_trigger WHERE tgrelid = 'logistics_control_changes'::regclass AND tgname = 'logistics_control_changes_immutable'")->oid)->toBe($existingTrigger)
            ->and(DB::selectOne("SELECT 'logistics_control_changes_immutable()'::regprocedure::oid AS oid")->oid)->toBe($existingFunction);

        foreach ($tables as $table) {
            expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', 1)->update(['payload' => 'rewritten'])))
                ->toThrow(QueryException::class);
            expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', 1)->delete()))
                ->toThrow(QueryException::class);
            expect(DB::table($table)->where('id', 1)->value('payload'))->toBe('accepted historical fact');
        }
        expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward recovery');

        DB::statement('ALTER TABLE logistics_evidence DISABLE TRIGGER logistics_evidence_immutable');
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Conflicting or disabled');
        expect(DB::table('logistics_evidence')->where('id', 1)->value('payload'))->toBe('accepted historical fact');
        DB::statement('ALTER TABLE logistics_evidence ENABLE TRIGGER logistics_evidence_immutable');

        DB::statement('DROP TRIGGER logistics_control_changes_immutable ON logistics_control_changes');
        $migration->up();
        expect(DB::selectOne("SELECT 'logistics_control_changes_immutable()'::regprocedure::oid AS oid")->oid)->toBe($existingFunction);
        expect(fn () => DB::transaction(fn () => DB::table('logistics_control_changes')->where('id', 1)->delete()))
            ->toThrow(QueryException::class);
    } finally {
        DB::selectOne('SELECT set_config(?, ?, true)', ['search_path', $originalSearchPath]);
        DB::statement('DROP SCHEMA '.$quotedSchema.' CASCADE');
    }
});

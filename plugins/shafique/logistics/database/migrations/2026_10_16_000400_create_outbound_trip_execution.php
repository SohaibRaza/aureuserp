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
        Schema::create('logistics_trips', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('mode', 32);
            $table->string('state', 32)->default('planning');
            $table->foreignId('source_location_id')->constrained('inventories_locations')->restrictOnDelete();
            $table->string('vehicle_reference', 120)->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('load_revision_id')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->timestampsTz();
            $table->index(['company_id', 'state'], 'logistics_trip_company_state_idx');
        });

        Schema::create('logistics_trip_stops', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trip_id')->constrained('logistics_trips')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->foreignId('partner_id')->nullable()->constrained('partners_partners')->restrictOnDelete();
            $table->jsonb('address_snapshot')->nullable();
            $table->string('state', 32)->default('planned');
            $table->timestampsTz();
            $table->unique(['trip_id', 'sequence'], 'logistics_trip_stop_sequence_unique');
            $table->index(['company_id', 'trip_id'], 'logistics_trip_stop_company_trip_idx');
        });

        Schema::create('logistics_trip_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trip_id')->constrained('logistics_trips')->restrictOnDelete();
            $table->foreignId('stop_id')->constrained('logistics_trip_stops')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('order_line_id')->constrained('sales_order_lines')->restrictOnDelete();
            $table->foreignId('quantity_commitment_id')->constrained('sales_quantity_commitments')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products_products')->restrictOnDelete();
            $table->foreignId('source_location_id')->constrained('inventories_locations')->restrictOnDelete();
            $table->decimal('stock_quantity', 16, 4);
            $table->decimal('loaded_quantity', 16, 4)->default(0);
            $table->decimal('released_quantity', 16, 4)->default(0);
            $table->string('state', 32)->default('active');
            $table->timestampsTz();
            $table->index(['company_id', 'order_line_id', 'quantity_commitment_id'], 'logistics_trip_allocation_source_idx');
            $table->index(['trip_id', 'stop_id', 'state'], 'logistics_trip_allocation_trip_stop_idx');
        });

        Schema::create('logistics_trip_move_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trip_id')->constrained('logistics_trips')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('move_id')->constrained('inventories_moves')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->unique(['trip_id', 'move_id'], 'logistics_trip_move_link_unique');
        });

        Schema::create('logistics_load_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trip_id')->constrained('logistics_trips')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('trip_revision');
            $table->unsignedInteger('revision');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->char('contents_hash', 64);
            $table->timestampTz('recorded_at');
            $table->unique(['trip_id', 'revision'], 'logistics_load_revision_unique');
        });

        Schema::create('logistics_load_verification_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('load_revision_id')->constrained('logistics_load_revisions')->restrictOnDelete();
            $table->foreignId('trip_id')->constrained('logistics_trips')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approval_id')->constrained('logistics_scoped_approvals')->restrictOnDelete();
            $table->foreignId('evidence_id')->nullable()->constrained('logistics_evidence')->restrictOnDelete();
            $table->timestampTz('verified_at');
            $table->unique('load_revision_id', 'logistics_load_verification_once_unique');
        });

        Schema::table('logistics_trips', function (Blueprint $table): void {
            $table->foreign('load_revision_id')->references('id')->on('logistics_load_revisions')->restrictOnDelete();
        });

        Schema::create('logistics_load_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('load_revision_id')->constrained('logistics_load_revisions')->restrictOnDelete();
            $table->foreignId('allocation_id')->constrained('logistics_trip_allocations')->restrictOnDelete();
            $table->foreignId('move_id')->constrained('inventories_moves')->restrictOnDelete();
            $table->foreignId('move_line_id')->constrained('inventories_move_lines')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->decimal('stock_quantity', 16, 4);
            $table->timestampsTz();
            $table->unique(['load_revision_id', 'move_line_id'], 'logistics_load_item_move_line_unique');
        });

        Schema::create('logistics_release_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trip_id')->constrained('logistics_trips')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('load_revision_id')->constrained('logistics_load_revisions')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('command_idempotency_key', 128);
            $table->jsonb('operation_results');
            $table->timestampTz('released_at');
            $table->unique(['company_id', 'command_idempotency_key'], 'logistics_release_command_unique');
            $table->unique('trip_id', 'logistics_trip_single_release_unique');
        });

        Schema::create('logistics_receipt_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trip_id')->constrained('logistics_trips')->restrictOnDelete();
            $table->foreignId('stop_id')->constrained('logistics_trip_stops')->restrictOnDelete();
            $table->foreignId('allocation_id')->nullable()->constrained('logistics_trip_allocations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('evidence_id')->nullable()->constrained('logistics_evidence')->restrictOnDelete();
            $table->string('request_key', 128);
            $table->string('intent', 24);
            $table->decimal('quantity', 16, 4)->nullable();
            $table->foreignId('unit_id')->nullable()->constrained('unit_of_measures')->restrictOnDelete();
            $table->timestampTz('occurred_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('recorded_at');
            $table->unique(['company_id', 'request_key'], 'logistics_receipt_request_unique');
            $table->index(['trip_id', 'stop_id', 'recorded_at'], 'logistics_receipt_stop_time_idx');
        });

        if (DB::getDriverName() === 'mysql') {
            ImmutableHistory::protect('logistics_load_revisions');
            ImmutableHistory::protect('logistics_load_items');
            ImmutableHistory::protect('logistics_load_verification_events');
            ImmutableHistory::protect('logistics_release_batches');
            ImmutableHistory::protect('logistics_receipt_observations');
            ImmutableHistory::protect('logistics_trip_move_links');

            return;
        }

        DB::unprepared(<<<'SQL'
CREATE FUNCTION logistics_outbound_records_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Saved Logistics load, release, receipt and move-link records cannot be changed or deleted.';
END;
$$;
CREATE TRIGGER logistics_load_revisions_immutable BEFORE UPDATE OR DELETE ON logistics_load_revisions FOR EACH ROW EXECUTE FUNCTION logistics_outbound_records_immutable();
CREATE TRIGGER logistics_load_items_immutable BEFORE UPDATE OR DELETE ON logistics_load_items FOR EACH ROW EXECUTE FUNCTION logistics_outbound_records_immutable();
CREATE TRIGGER logistics_load_verification_events_immutable BEFORE UPDATE OR DELETE ON logistics_load_verification_events FOR EACH ROW EXECUTE FUNCTION logistics_outbound_records_immutable();
CREATE TRIGGER logistics_release_batches_immutable BEFORE UPDATE OR DELETE ON logistics_release_batches FOR EACH ROW EXECUTE FUNCTION logistics_outbound_records_immutable();
CREATE TRIGGER logistics_receipt_observations_immutable BEFORE UPDATE OR DELETE ON logistics_receipt_observations FOR EACH ROW EXECUTE FUNCTION logistics_outbound_records_immutable();
CREATE TRIGGER logistics_trip_move_links_immutable BEFORE UPDATE OR DELETE ON logistics_trip_move_links FOR EACH ROW EXECUTE FUNCTION logistics_outbound_records_immutable();
SQL);
    }

    public function down(): void
    {
        ImmutableHistory::refuseMySqlRollback();

        DB::transaction(function (): void {
            DB::statement('LOCK TABLE logistics_trips, logistics_trip_stops, logistics_trip_allocations, logistics_trip_move_links, logistics_load_revisions, logistics_load_verification_events, logistics_load_items, logistics_release_batches, logistics_receipt_observations IN ACCESS EXCLUSIVE MODE');

            if (DB::table('logistics_trips')->exists()
                || DB::table('logistics_trip_stops')->exists()
                || DB::table('logistics_trip_allocations')->exists()
                || DB::table('logistics_trip_move_links')->exists()
                || DB::table('logistics_load_revisions')->exists()
                || DB::table('logistics_load_verification_events')->exists()
                || DB::table('logistics_load_items')->exists()
                || DB::table('logistics_release_batches')->exists()
                || DB::table('logistics_receipt_observations')->exists()) {
                throw new RuntimeException('Accepted outbound trip, load, release or receipt history requires forward recovery; rollback cannot discard that history.');
            }

            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS logistics_trip_move_links_immutable ON logistics_trip_move_links;
DROP TRIGGER IF EXISTS logistics_receipt_observations_immutable ON logistics_receipt_observations;
DROP TRIGGER IF EXISTS logistics_release_batches_immutable ON logistics_release_batches;
DROP TRIGGER IF EXISTS logistics_load_verification_events_immutable ON logistics_load_verification_events;
DROP TRIGGER IF EXISTS logistics_load_items_immutable ON logistics_load_items;
DROP TRIGGER IF EXISTS logistics_load_revisions_immutable ON logistics_load_revisions;
DROP FUNCTION IF EXISTS logistics_outbound_records_immutable();
SQL);
            Schema::dropIfExists('logistics_receipt_observations');
            Schema::dropIfExists('logistics_release_batches');
            Schema::dropIfExists('logistics_load_items');
            Schema::dropIfExists('logistics_load_verification_events');
            Schema::table('logistics_trips', function (Blueprint $table): void {
                $table->dropForeign(['load_revision_id']);
            });
            Schema::dropIfExists('logistics_load_revisions');
            Schema::dropIfExists('logistics_trip_allocations');
            Schema::dropIfExists('logistics_trip_move_links');
            Schema::dropIfExists('logistics_trip_stops');
            Schema::dropIfExists('logistics_trips');
        });
    }
};

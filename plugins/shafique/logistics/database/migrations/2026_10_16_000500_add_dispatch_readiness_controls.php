<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_dispatch_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->jsonb('source_location_ids');
            $table->jsonb('product_rules');
            $table->boolean('require_load_evidence');
            $table->boolean('require_receipt_evidence');
            $table->text('reason');
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at');
            $table->unique(['company_id', 'revision']);
        });
        Schema::create('logistics_dispatch_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('trip_id')->constrained('logistics_trips')->restrictOnDelete();
            $table->foreignId('load_revision_id')->constrained('logistics_load_revisions')->restrictOnDelete();
            $table->foreignId('policy_id')->constrained('logistics_dispatch_policies')->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('loading_deadline');
            $table->decimal('vehicle_payload_kg', 16, 4);
            $table->decimal('loaded_gross_weight_kg', 16, 4);
            $table->decimal('vehicle_volume_m3', 16, 4)->nullable();
            $table->decimal('loaded_volume_m3', 16, 4)->nullable();
            $table->text('volume_not_required_reason')->nullable();
            $table->foreignId('provider_id')->constrained('partners_partners')->restrictOnDelete();
            $table->string('driver_name', 120);
            $table->string('custodian_name', 120);
            $table->string('vehicle_reference', 120);
            $table->text('handling_confirmation');
            $table->text('quality_release_reference')->nullable();
            $table->timestampTz('recorded_at');
            $table->index(['trip_id', 'load_revision_id']);
        });
        Schema::table('logistics_receipt_observations', function (Blueprint $table): void {
            $table->string('reporter_name', 120)->nullable();
        });
        Schema::table('logistics_load_revisions', function (Blueprint $table): void {
            $table->foreignId('source_location_id')->nullable()->constrained('inventories_locations')->restrictOnDelete();
        });
        Schema::table('logistics_release_batches', function (Blueprint $table): void {
            $table->dropUnique('logistics_trip_single_release_unique');
            $table->unique('load_revision_id', 'logistics_load_single_release_unique');
        });
        DB::unprepared(<<<'SQL'
CREATE TRIGGER logistics_dispatch_policies_immutable BEFORE UPDATE OR DELETE ON logistics_dispatch_policies FOR EACH ROW EXECUTE FUNCTION logistics_outbound_records_immutable();
CREATE TRIGGER logistics_dispatch_checks_immutable BEFORE UPDATE OR DELETE ON logistics_dispatch_checks FOR EACH ROW EXECUTE FUNCTION logistics_outbound_records_immutable();
SQL);
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement('LOCK TABLE logistics_dispatch_policies, logistics_dispatch_checks, logistics_receipt_observations, logistics_release_batches, logistics_load_revisions IN ACCESS EXCLUSIVE MODE');

            if (DB::table('logistics_dispatch_policies')->exists()
                || DB::table('logistics_dispatch_checks')->exists()
                || DB::table('logistics_receipt_observations')->exists()
                || DB::table('logistics_release_batches')->exists()
                || DB::table('logistics_load_revisions')->exists()) {
                throw new RuntimeException('Accepted dispatch, receipt or load history requires forward recovery; rollback cannot discard that history.');
            }

            Schema::table('logistics_receipt_observations', fn (Blueprint $table) => $table->dropColumn('reporter_name'));
            Schema::table('logistics_release_batches', function (Blueprint $table): void {
                $table->dropUnique('logistics_load_single_release_unique');
                $table->unique('trip_id', 'logistics_trip_single_release_unique');
            });
            Schema::table('logistics_load_revisions', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('source_location_id');
            });
            Schema::dropIfExists('logistics_dispatch_checks');
            Schema::dropIfExists('logistics_dispatch_policies');
        });
    }
};

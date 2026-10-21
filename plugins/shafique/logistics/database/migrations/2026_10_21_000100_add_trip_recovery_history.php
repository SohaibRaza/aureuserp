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
        Schema::table('logistics_load_items', function (Blueprint $table): void {
            $table->jsonb('native_line_snapshot')->nullable();
            // Reservation lines are temporary. Keep their original IDs and facts in load history.
            $table->dropForeign(['move_line_id']);
        });
        Schema::create('logistics_trip_recovery_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('trip_id')->constrained('logistics_trips')->restrictOnDelete();
            $table->foreignId('allocation_id')->nullable()->constrained('logistics_trip_allocations')->restrictOnDelete();
            $table->foreignId('load_revision_id')->nullable()->constrained('logistics_load_revisions')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 32);
            $table->text('reason');
            $table->unsignedBigInteger('previous_revision');
            $table->unsignedBigInteger('resulting_revision');
            $table->timestampTz('recorded_at');
            $table->index(['trip_id', 'id']);
        });
        if (DB::getDriverName() === 'mysql') {
            ImmutableHistory::protect('logistics_trip_recovery_events');

            return;
        }

        DB::unprepared('CREATE TRIGGER logistics_trip_recovery_events_immutable BEFORE UPDATE OR DELETE ON logistics_trip_recovery_events FOR EACH ROW EXECUTE FUNCTION logistics_outbound_records_immutable()');
    }

    public function down(): void
    {
        ImmutableHistory::refuseMySqlRollback();

        DB::transaction(function (): void {
            DB::statement('LOCK TABLE logistics_trip_recovery_events, logistics_load_items, inventories_move_lines IN ACCESS EXCLUSIVE MODE');

            if (DB::table('logistics_trip_recovery_events')->exists()
                || DB::table('logistics_load_items')->whereNotNull('native_line_snapshot')->exists()
                || DB::table('logistics_load_items as items')->leftJoin('inventories_move_lines as lines', 'lines.id', '=', 'items.move_line_id')->whereNull('lines.id')->exists()) {
                throw new RuntimeException('Accepted load snapshots, recovery history or removed reservation rows require forward recovery; rollback cannot discard that history.');
            }
            Schema::dropIfExists('logistics_trip_recovery_events');
            Schema::table('logistics_load_items', function (Blueprint $table): void {
                $table->foreign('move_line_id')->references('id')->on('inventories_move_lines')->restrictOnDelete();
                $table->dropColumn('native_line_snapshot');
            });
        });
    }
};

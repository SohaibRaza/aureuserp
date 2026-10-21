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
        Schema::table('logistics_company_controls', function (Blueprint $table): void {
            $table->bigInteger('revision')->default(0);
        });
        Schema::table('logistics_control_changes', function (Blueprint $table): void {
            $table->bigInteger('revision')->nullable();
            $table->unique(['company_id', 'revision']);
        });
        DB::statement('ALTER TABLE logistics_company_controls ADD CONSTRAINT logistics_control_revision_nonnegative CHECK (revision >= 0)');
        DB::statement('ALTER TABLE logistics_control_changes ADD CONSTRAINT logistics_history_revision_positive CHECK (revision IS NULL OR revision > 0)');
    }

    public function down(): void
    {
        ImmutableHistory::refuseMySqlRollback();

        DB::transaction(function (): void {
            DB::statement('LOCK TABLE logistics_company_controls, logistics_control_changes IN ACCESS EXCLUSIVE MODE');

            if (DB::table('logistics_control_changes')->exists()) {
                throw new RuntimeException('Accepted control revisions require forward recovery; rollback cannot discard that history.');
            }

            DB::statement('ALTER TABLE logistics_control_changes DROP CONSTRAINT logistics_history_revision_positive');
            DB::statement('ALTER TABLE logistics_company_controls DROP CONSTRAINT logistics_control_revision_nonnegative');
            Schema::table('logistics_control_changes', function (Blueprint $table): void {
                $table->dropUnique(['company_id', 'revision']);
                $table->dropColumn('revision');
            });
            Schema::table('logistics_company_controls', function (Blueprint $table): void {
                $table->dropColumn('revision');
            });
        });
    }
};

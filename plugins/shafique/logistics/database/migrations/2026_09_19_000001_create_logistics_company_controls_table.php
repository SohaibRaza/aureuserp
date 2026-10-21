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
        Schema::create('logistics_company_controls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('companies')->restrictOnDelete();
            $table->boolean('accept_new_activity')->default(false);
            $table->timestamps();
        });

        Schema::create('logistics_control_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->boolean('old_value');
            $table->boolean('new_value');
            $table->text('reason');
            $table->timestamp('created_at');
            $table->index(['company_id', 'created_at']);
        });

        ImmutableHistory::protect('logistics_control_changes');
    }

    public function down(): void
    {
        ImmutableHistory::refuseMySqlRollback();

        DB::transaction(function (): void {
            DB::statement('LOCK TABLE logistics_company_controls, logistics_control_changes IN ACCESS EXCLUSIVE MODE');

            if (DB::table('logistics_control_changes')->exists()) {
                throw new RuntimeException('Accepted control history requires forward recovery; rollback cannot discard that history.');
            }

            Schema::dropIfExists('logistics_control_changes');
            Schema::dropIfExists('logistics_company_controls');
            DB::statement('DROP FUNCTION IF EXISTS logistics_control_changes_immutable()');
        });
    }
};

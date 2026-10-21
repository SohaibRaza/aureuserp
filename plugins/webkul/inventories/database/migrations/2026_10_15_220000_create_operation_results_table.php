<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventories_operation_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_id')->unique()->constrained('inventories_operations')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action');
            $table->unsignedSmallInteger('version')->default(1);
            $table->jsonb('intent');
            $table->jsonb('result');
            $table->timestamp('created_at');
        });

        DB::statement("ALTER TABLE inventories_operation_results ADD CONSTRAINT inventories_operation_results_action_check CHECK (action IN ('complete', 'cancel')), ADD CONSTRAINT inventories_operation_results_version_check CHECK (version > 0)");

        DB::unprepared(<<<'SQL'
CREATE FUNCTION inventories_operation_results_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Saved Inventory operation results cannot be changed or deleted.';
END;
$$;
CREATE TRIGGER inventories_operation_results_immutable BEFORE UPDATE OR DELETE ON inventories_operation_results
FOR EACH ROW EXECUTE FUNCTION inventories_operation_results_immutable();
SQL);
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement('LOCK TABLE inventories_operation_results IN ACCESS EXCLUSIVE MODE');

            if (DB::table('inventories_operation_results')->exists()) {
                throw new RuntimeException('Saved Inventory operation results require forward recovery; rollback cannot discard that history.');
            }

            Schema::dropIfExists('inventories_operation_results');
            DB::statement('DROP FUNCTION IF EXISTS inventories_operation_results_immutable()');
        });
    }
};

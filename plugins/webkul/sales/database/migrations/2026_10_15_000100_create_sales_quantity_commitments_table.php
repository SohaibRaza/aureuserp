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
        Schema::create('sales_quantity_commitments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('order_line_id')->constrained('sales_order_lines')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->jsonb('data');
            $table->timestampTz('created_at');
            $table->unique(['order_line_id', 'revision']);
        });

        if (DB::getDriverName() === 'mysql') {
            ImmutableHistory::protect('sales_quantity_commitments');

            return;
        }

        DB::unprepared(<<<'SQL'
CREATE FUNCTION sales_quantity_commitments_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Confirmed quantity records cannot be changed or deleted.';
END;
$$;
CREATE TRIGGER sales_quantity_commitments_immutable
BEFORE UPDATE OR DELETE ON sales_quantity_commitments
FOR EACH ROW EXECUTE FUNCTION sales_quantity_commitments_immutable();
SQL);
    }

    public function down(): void
    {
        ImmutableHistory::refuseMySqlRollback();

        DB::transaction(function (): void {
            DB::statement('LOCK TABLE sales_quantity_commitments IN ACCESS EXCLUSIVE MODE');

            if (DB::table('sales_quantity_commitments')->exists()) {
                throw new RuntimeException('Confirmed Sales quantity records require forward recovery; rollback cannot discard that history.');
            }

            Schema::dropIfExists('sales_quantity_commitments');
            DB::unprepared('DROP FUNCTION IF EXISTS sales_quantity_commitments_immutable()');
        });
    }
};

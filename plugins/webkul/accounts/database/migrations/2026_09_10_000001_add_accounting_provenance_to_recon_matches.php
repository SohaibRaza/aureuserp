<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recon_matches', function (Blueprint $table): void {
            $table->string('accounting_mode')->nullable();
            $table->json('accounting_partial_ids')->nullable();
            $table->json('accounting_full_ids')->nullable();
            $table->json('ledger_previous_residuals')->nullable();
            $table->decimal('accounting_applied_amount', 18, 4)->nullable();
            $table->timestamp('accounting_applied_at')->nullable();
            $table->foreignId('accounting_applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accounting_reversed_at')->nullable();
            $table->foreignId('accounting_reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key')->nullable();
        });

        Schema::table('recon_matches', function (Blueprint $table): void {
            $table->unique(['reconciliation_id', 'idempotency_key'], 'recon_match_idempotency_unique');
        });

        Schema::table('recon_match_lines', function (Blueprint $table): void {
            $table->decimal('previous_ledger_residual', 18, 4)->nullable();
            $table->decimal('previous_ledger_residual_currency', 18, 4)->nullable();
            $table->decimal('applied_ledger_amount', 18, 4)->nullable();
            $table->unsignedBigInteger('accounting_partial_id')->nullable()->index();
            $table->timestamp('accounting_reversed_at')->nullable();
            $table->foreignId('accounting_reversed_by')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::statement("ALTER TABLE recon_matches ADD CONSTRAINT recon_match_accounting_mode_check CHECK (accounting_mode IS NULL OR accounting_mode IN ('allocation_only', 'ledger_group'))");
        DB::statement('ALTER TABLE recon_matches ADD CONSTRAINT recon_match_accounting_amount_check CHECK (accounting_applied_amount IS NULL OR accounting_applied_amount >= 0)');
        DB::statement('ALTER TABLE recon_match_lines ADD CONSTRAINT recon_match_line_nonzero_allocation_check CHECK (amount_applied <> 0)');
        DB::statement("ALTER TABLE recon_match_lines ADD CONSTRAINT recon_match_line_side_type_consistency_check CHECK ((side = 'bank' AND lineable_type = 'statement_line') OR (side = 'ledger' AND lineable_type = 'move_line'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE recon_match_lines DROP CONSTRAINT IF EXISTS recon_match_line_side_type_consistency_check, DROP CONSTRAINT IF EXISTS recon_match_line_nonzero_allocation_check');
        DB::statement('ALTER TABLE recon_matches DROP CONSTRAINT IF EXISTS recon_match_accounting_amount_check, DROP CONSTRAINT IF EXISTS recon_match_accounting_mode_check');

        Schema::table('recon_match_lines', function (Blueprint $table): void {
            $table->dropForeign(['accounting_reversed_by']);
            $table->dropColumn([
                'previous_ledger_residual',
                'previous_ledger_residual_currency',
                'applied_ledger_amount',
                'accounting_partial_id',
                'accounting_reversed_at',
                'accounting_reversed_by',
            ]);
        });

        Schema::table('recon_matches', function (Blueprint $table): void {
            $table->dropUnique('recon_match_idempotency_unique');
        });

        Schema::table('recon_matches', function (Blueprint $table): void {
            $table->dropForeign(['accounting_applied_by']);
            $table->dropForeign(['accounting_reversed_by']);
            $table->dropColumn([
                'accounting_mode',
                'accounting_partial_ids',
                'accounting_full_ids',
                'ledger_previous_residuals',
                'accounting_applied_amount',
                'accounting_applied_at',
                'accounting_applied_by',
                'accounting_reversed_at',
                'accounting_reversed_by',
                'idempotency_key',
            ]);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recon_reconciling_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('reconciliation_id')->constrained('recon_bank_reconciliations')->restrictOnDelete();
            $table->foreignId('originating_reconciliation_id')->nullable()->constrained('recon_bank_reconciliations')->restrictOnDelete();
            $table->foreignId('resolved_reconciliation_id')->nullable()->constrained('recon_bank_reconciliations')->restrictOnDelete();
            $table->foreignId('statement_line_id')->nullable()->constrained('accounts_bank_statement_lines')->restrictOnDelete();
            $table->foreignId('move_line_id')->nullable()->constrained('accounts_account_move_lines')->restrictOnDelete();
            $table->string('side');
            $table->string('classification');
            $table->decimal('amount', 18, 4);
            $table->date('origin_period_end');
            $table->string('status')->default('open');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('evidence')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'reconciliation_id', 'status'], 'recon_item_company_session_status_index');
            $table->index(['company_id', 'origin_period_end', 'status'], 'recon_item_carry_forward_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE recon_reconciling_items
            ADD CONSTRAINT recon_item_side_check CHECK (side IN ('bank', 'book')),
            ADD CONSTRAINT recon_item_classification_check CHECK (classification IN (
                'outstanding_payment', 'deposit_in_transit', 'missing_book_entry', 'bank_error',
                'duplicate', 'returned_payment', 'transfer', 'unidentified', 'disputed',
                'investigation_required'
            )),
            ADD CONSTRAINT recon_item_status_check CHECK (status IN ('open', 'carried_forward', 'resolved', 'excluded')),
            ADD CONSTRAINT recon_item_nonzero_amount_check CHECK (amount <> 0),
            ADD CONSTRAINT recon_item_resolution_check CHECK (
                status NOT IN ('resolved', 'excluded') OR resolved_at IS NOT NULL
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('recon_reconciling_items');
    }
};

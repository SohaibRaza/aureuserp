<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recon_bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('journal_id');
            $table->unsignedBigInteger('statement_id')->nullable();
            $table->string('name');
            $table->string('status')->default('draft')->index(); // draft|in_progress|review|completed|reopened
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('balance_start', 18, 4)->default(0);
            $table->decimal('balance_end_real', 18, 4)->default(0);
            $table->decimal('gl_balance_start', 18, 4)->default(0);
            $table->decimal('gl_balance_end', 18, 4)->default(0);
            $table->decimal('difference', 18, 4)->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->unsignedBigInteger('reopened_by')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->unsignedBigInteger('creator_id')->nullable();
            $table->timestamps();

            $table->index(['journal_id', 'status']);
        });

        Schema::create('recon_matches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reconciliation_id')->index();
            $table->string('status')->default('proposed'); // proposed|confirmed|rejected
            $table->string('match_type')->default('manual'); // auto|manual
            $table->decimal('amount', 18, 4)->default(0);
            $table->decimal('variance', 18, 4)->default(0);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('creator_id')->nullable();
            $table->timestamps();
        });

        Schema::create('recon_match_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('match_id')->index();
            $table->string('lineable_type'); // statement_line|move_line
            $table->unsignedBigInteger('lineable_id');
            $table->string('side'); // bank|ledger
            $table->decimal('amount_applied', 18, 4)->default(0);
            $table->timestamps();

            $table->unique(['match_id', 'lineable_type', 'lineable_id'], 'recon_match_lines_unique');
            $table->index(['lineable_type', 'lineable_id']);
        });

        Schema::create('recon_adjustments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reconciliation_id')->index();
            $table->string('type'); // bank_fee|interest|direct_charge|nsf|fx_difference|correction|other
            $table->decimal('amount', 18, 4);
            $table->unsignedBigInteger('counterpart_account_id')->nullable();
            $table->unsignedBigInteger('move_id')->nullable();
            $table->unsignedBigInteger('statement_line_id')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('creator_id')->nullable();
            $table->timestamps();
        });

        Schema::create('recon_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reconciliation_id')->index();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('event');
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['reconciliation_id', 'created_at']);
        });

        Schema::create('recon_import_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('journal_id');
            $table->unsignedBigInteger('statement_id')->nullable();
            $table->unsignedBigInteger('reconciliation_id')->nullable();
            $table->string('filename')->nullable();
            $table->string('format')->default('csv');
            $table->unsignedInteger('lines_total')->default(0);
            $table->unsignedInteger('lines_imported')->default(0);
            $table->unsignedInteger('lines_duplicate')->default(0);
            $table->json('error_report')->nullable();
            $table->unsignedBigInteger('imported_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recon_import_batches');
        Schema::dropIfExists('recon_events');
        Schema::dropIfExists('recon_adjustments');
        Schema::dropIfExists('recon_match_lines');
        Schema::dropIfExists('recon_matches');
        Schema::dropIfExists('recon_bank_reconciliations');
    }
};

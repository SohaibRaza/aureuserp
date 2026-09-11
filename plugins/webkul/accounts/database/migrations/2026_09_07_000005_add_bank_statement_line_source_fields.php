<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts_bank_statement_lines', function (Blueprint $table): void {
            $table->date('transaction_date')->nullable();
            $table->date('value_date')->nullable();
            $table->string('external_id')->nullable();
            $table->foreignId('import_batch_id')->nullable();
            $table->char('source_fingerprint', 64)->nullable();
            $table->string('transaction_code')->nullable();
            $table->string('import_status')->nullable();
            $table->foreignId('duplicate_of_statement_line_id')->nullable();
            $table->json('source_payload')->nullable();

            $table->index(['company_id', 'journal_id', 'transaction_date'], 'bank_statement_line_source_date_index');
            $table->foreign('import_batch_id')->references('id')->on('recon_import_batches')->restrictOnDelete();
            $table->foreign('duplicate_of_statement_line_id')->references('id')->on('accounts_bank_statement_lines')->restrictOnDelete();
        });

        DB::table('accounts_bank_statement_lines')->update([
            'import_status' => 'legacy_unverified',
        ]);

        DB::statement(<<<'SQL'
            ALTER TABLE accounts_bank_statement_lines
            ADD CONSTRAINT bank_statement_line_import_status_check CHECK (
                import_status IS NULL OR import_status IN ('accepted', 'quarantined', 'duplicate', 'legacy_unverified')
            ),
            ADD CONSTRAINT bank_statement_line_duplicate_source_check CHECK (
                import_status <> 'duplicate' OR duplicate_of_statement_line_id IS NOT NULL
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX bank_statement_line_external_id_unique
            ON accounts_bank_statement_lines (company_id, journal_id, external_id)
            WHERE external_id IS NOT NULL AND import_status = 'accepted'
        SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX bank_statement_line_fingerprint_unique
            ON accounts_bank_statement_lines (company_id, journal_id, source_fingerprint)
            WHERE source_fingerprint IS NOT NULL AND import_status = 'accepted'
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS bank_statement_line_fingerprint_unique');
        DB::statement('DROP INDEX IF EXISTS bank_statement_line_external_id_unique');

        Schema::table('accounts_bank_statement_lines', function (Blueprint $table): void {
            $table->dropForeign(['duplicate_of_statement_line_id']);
            $table->dropForeign(['import_batch_id']);
            $table->dropIndex('bank_statement_line_source_date_index');
            $table->dropColumn([
                'transaction_date', 'value_date', 'external_id', 'import_batch_id',
                'source_fingerprint', 'transaction_code', 'import_status',
                'duplicate_of_statement_line_id', 'source_payload',
            ]);
        });
    }
};

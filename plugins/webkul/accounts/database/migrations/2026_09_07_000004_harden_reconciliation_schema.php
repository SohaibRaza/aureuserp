<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recon_bank_reconciliations', function (Blueprint $table): void {
            $table->foreignId('bank_account_id')->nullable();
            $table->foreignId('liquidity_account_id')->nullable();
            $table->foreignId('currency_id')->nullable();
            $table->date('statement_date')->nullable();
            $table->foreignId('prior_reconciliation_id')->nullable();
            $table->string('continuity_type')->nullable();
            $table->text('continuity_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable();
            $table->timestamp('reopen_requested_at')->nullable();
            $table->foreignId('reopen_requested_by')->nullable();
            $table->text('reopen_request_reason')->nullable();
            $table->timestamp('stale_at')->nullable();
            $table->text('stale_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('legacy_verified_at')->nullable();

            $table->index(['company_id', 'bank_account_id', 'period_end'], 'recon_company_bank_period_index');
        });

        Schema::table('recon_matches', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable();
            $table->text('reversal_reason')->nullable();
        });

        Schema::table('recon_match_lines', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable();
        });

        Schema::table('recon_adjustments', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable();
            $table->foreignId('currency_id')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable();
            $table->text('reversal_reason')->nullable();
        });

        Schema::table('recon_events', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable();
        });

        Schema::table('recon_import_batches', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable();
            $table->foreignId('currency_id')->nullable();
            $table->string('status')->nullable();
            $table->string('source_type')->nullable();
            $table->string('source_identifier')->nullable();
            $table->string('source_storage_path')->nullable();
            $table->string('original_filename')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->string('parser_version')->nullable();
            $table->string('account_identifier')->nullable();
            $table->string('currency_code', 3)->nullable();
            $table->date('statement_start_date')->nullable();
            $table->date('statement_end_date')->nullable();
            $table->date('statement_date')->nullable();
            $table->decimal('opening_balance', 18, 4)->nullable();
            $table->decimal('closing_balance', 18, 4)->nullable();
            $table->decimal('control_debit_total', 18, 4)->nullable();
            $table->decimal('control_credit_total', 18, 4)->nullable();
            $table->unsignedInteger('control_transaction_count')->nullable();
            $table->json('source_metadata')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable();

            $table->index(['company_id', 'account_identifier', 'statement_end_date'], 'recon_import_account_period_index');
        });

        $this->backfillDeterministicIdentity();
        $this->addForeignKeys();
        $this->addConstraints();
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS recon_validate_identity_trigger ON recon_bank_reconciliations');
        DB::unprepared('DROP FUNCTION IF EXISTS recon_validate_identity()');
        DB::statement('DROP INDEX IF EXISTS recon_one_active_session_per_bank');
        DB::statement('ALTER TABLE recon_import_batches DROP CONSTRAINT IF EXISTS recon_import_status_check, DROP CONSTRAINT IF EXISTS recon_import_period_order_check, DROP CONSTRAINT IF EXISTS recon_accepted_import_identity_check');
        DB::statement('ALTER TABLE recon_match_lines DROP CONSTRAINT IF EXISTS recon_match_line_side_check, DROP CONSTRAINT IF EXISTS recon_match_line_type_check');
        DB::statement('ALTER TABLE recon_matches DROP CONSTRAINT IF EXISTS recon_match_status_check, DROP CONSTRAINT IF EXISTS recon_match_type_check');
        DB::statement('ALTER TABLE recon_bank_reconciliations DROP CONSTRAINT IF EXISTS recon_status_check, DROP CONSTRAINT IF EXISTS recon_period_order_check, DROP CONSTRAINT IF EXISTS recon_required_identity_check, DROP CONSTRAINT IF EXISTS recon_continuity_check, DROP CONSTRAINT IF EXISTS recon_discontinuity_reason_check');

        Schema::table('recon_import_batches', function (Blueprint $table): void {
            $table->dropUnique('recon_import_batch_company_unique');
        });
        Schema::table('recon_import_batches', function (Blueprint $table): void {
            $table->dropForeign(['reconciliation_id', 'company_id']);
            $table->dropForeign(['accepted_by']);
            $table->dropForeign(['currency_id']);
            $table->dropForeign(['company_id']);
            $table->dropIndex('recon_import_account_period_index');
            $table->dropColumn([
                'company_id', 'currency_id', 'status', 'source_type', 'source_identifier',
                'source_storage_path', 'original_filename', 'content_hash', 'parser_version',
                'account_identifier', 'currency_code', 'statement_start_date', 'statement_end_date',
                'statement_date', 'opening_balance', 'closing_balance', 'control_debit_total',
                'control_credit_total', 'control_transaction_count', 'source_metadata', 'accepted_at',
                'accepted_by',
            ]);
        });

        Schema::table('recon_events', function (Blueprint $table): void {
            $table->dropForeign(['reconciliation_id', 'company_id']);
            $table->dropForeign(['actor_id']);
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });

        Schema::table('recon_adjustments', function (Blueprint $table): void {
            $table->dropForeign(['reconciliation_id', 'company_id']);
            $table->dropForeign(['creator_id']);
            $table->dropForeign(['statement_line_id']);
            $table->dropForeign(['move_id']);
            $table->dropForeign(['counterpart_account_id']);
            $table->dropForeign(['reversed_by']);
            $table->dropForeign(['currency_id']);
            $table->dropForeign(['company_id']);
            $table->dropColumn(['company_id', 'currency_id', 'posted_at', 'reversed_at', 'reversed_by', 'reversal_reason']);
        });

        Schema::table('recon_match_lines', function (Blueprint $table): void {
            $table->dropForeign(['match_id', 'company_id']);
            $table->dropForeign(['reversed_by']);
            $table->dropForeign(['company_id']);
            $table->dropColumn(['company_id', 'reversed_at', 'reversed_by']);
        });

        // The child composite foreign key above depends on this unique index.
        // PostgreSQL therefore requires the foreign key to be removed first.
        Schema::table('recon_matches', function (Blueprint $table): void {
            $table->dropUnique('recon_match_company_unique');
        });

        Schema::table('recon_matches', function (Blueprint $table): void {
            $table->dropForeign(['reconciliation_id', 'company_id']);
            $table->dropForeign(['creator_id']);
            $table->dropForeign(['reversed_by']);
            $table->dropForeign(['confirmed_by']);
            $table->dropForeign(['company_id']);
            $table->dropColumn(['company_id', 'confirmed_at', 'confirmed_by', 'reversed_at', 'reversed_by', 'reversal_reason']);
        });

        Schema::table('recon_bank_reconciliations', function (Blueprint $table): void {
            $table->dropForeign(['statement_id', 'company_id']);
            $table->dropForeign(['journal_id', 'company_id']);
            $table->dropForeign(['creator_id']);
            $table->dropForeign(['reopened_by']);
            $table->dropForeign(['completed_by']);
            $table->dropForeign(['cancelled_by']);
            $table->dropForeign(['reopen_requested_by']);
            $table->dropForeign(['reviewed_by']);
            $table->dropForeign(['submitted_by']);
            $table->dropForeign(['prior_reconciliation_id']);
            $table->dropForeign(['currency_id']);
            $table->dropForeign(['liquidity_account_id']);
            $table->dropForeign(['bank_account_id']);
            $table->dropIndex('recon_company_bank_period_index');
            $table->dropUnique('recon_bank_reconciliation_company_unique');
            $table->dropColumn([
                'bank_account_id', 'liquidity_account_id', 'currency_id', 'statement_date',
                'prior_reconciliation_id', 'continuity_type', 'continuity_reason', 'submitted_at',
                'submitted_by', 'reviewed_at', 'reviewed_by', 'reopen_requested_at',
                'reopen_requested_by', 'reopen_request_reason', 'stale_at', 'stale_reason',
                'cancelled_at', 'cancelled_by', 'cancellation_reason', 'legacy_verified_at',
            ]);
        });

        Schema::table('accounts_bank_statements', function (Blueprint $table): void {
            $table->dropUnique('accounts_bank_statement_company_unique');
        });
        Schema::table('accounts_journals', function (Blueprint $table): void {
            $table->dropUnique('accounts_journal_company_unique');
        });
    }

    private function backfillDeterministicIdentity(): void
    {
        DB::statement(<<<'SQL'
            UPDATE recon_bank_reconciliations AS reconciliation
            SET company_id = COALESCE(reconciliation.company_id, journal.company_id),
                bank_account_id = journal.bank_account_id,
                liquidity_account_id = journal.default_account_id,
                currency_id = COALESCE(journal.currency_id, company.currency_id)
            FROM accounts_journals AS journal
            LEFT JOIN companies AS company ON company.id = journal.company_id
            WHERE journal.id = reconciliation.journal_id
        SQL);

        DB::statement(<<<'SQL'
            UPDATE recon_bank_reconciliations AS reconciliation
            SET statement_date = statement.date
            FROM accounts_bank_statements AS statement
            WHERE statement.id = reconciliation.statement_id
            AND reconciliation.statement_date IS NULL
        SQL);

        DB::table('recon_bank_reconciliations')->update([
            'status' => 'legacy_unverified',
        ]);

        DB::statement(<<<'SQL'
            UPDATE recon_matches AS child
            SET company_id = parent.company_id
            FROM recon_bank_reconciliations AS parent
            WHERE parent.id = child.reconciliation_id
        SQL);
        DB::statement(<<<'SQL'
            UPDATE recon_match_lines AS child
            SET company_id = parent.company_id
            FROM recon_matches AS parent
            WHERE parent.id = child.match_id
        SQL);
        DB::statement(<<<'SQL'
            UPDATE recon_adjustments AS child
            SET company_id = parent.company_id,
                currency_id = parent.currency_id
            FROM recon_bank_reconciliations AS parent
            WHERE parent.id = child.reconciliation_id
        SQL);
        DB::statement(<<<'SQL'
            UPDATE recon_events AS child
            SET company_id = parent.company_id
            FROM recon_bank_reconciliations AS parent
            WHERE parent.id = child.reconciliation_id
        SQL);
        DB::statement(<<<'SQL'
            UPDATE recon_import_batches AS batch
            SET company_id = COALESCE(batch.company_id, parent.company_id),
                currency_id = COALESCE(batch.currency_id, parent.currency_id)
            FROM recon_bank_reconciliations AS parent
            WHERE parent.id = batch.reconciliation_id
        SQL);
        DB::statement(<<<'SQL'
            UPDATE recon_import_batches AS batch
            SET company_id = COALESCE(batch.company_id, journal.company_id),
                currency_id = COALESCE(batch.currency_id, journal.currency_id, company.currency_id)
            FROM accounts_journals AS journal
            LEFT JOIN companies AS company ON company.id = journal.company_id
            WHERE journal.id = batch.journal_id
        SQL);
        DB::table('recon_import_batches')->update([
            'status'            => 'legacy_unverified',
            'original_filename' => DB::raw('filename'),
        ]);
    }

    private function addForeignKeys(): void
    {
        Schema::table('accounts_journals', function (Blueprint $table): void {
            $table->unique(['id', 'company_id'], 'accounts_journal_company_unique');
        });
        Schema::table('accounts_bank_statements', function (Blueprint $table): void {
            $table->unique(['id', 'company_id'], 'accounts_bank_statement_company_unique');
        });
        Schema::table('recon_bank_reconciliations', function (Blueprint $table): void {
            $table->unique(['id', 'company_id'], 'recon_bank_reconciliation_company_unique');
            $table->foreign(['journal_id', 'company_id'])->references(['id', 'company_id'])->on('accounts_journals')->restrictOnDelete();
            $table->foreign(['statement_id', 'company_id'])->references(['id', 'company_id'])->on('accounts_bank_statements')->restrictOnDelete();
            $table->foreign('bank_account_id')->references('id')->on('partners_bank_accounts')->restrictOnDelete();
            $table->foreign('liquidity_account_id')->references('id')->on('accounts_accounts')->restrictOnDelete();
            $table->foreign('currency_id')->references('id')->on('currencies')->restrictOnDelete();
            $table->foreign('prior_reconciliation_id')->references('id')->on('recon_bank_reconciliations')->restrictOnDelete();
            $table->foreign('submitted_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reopen_requested_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('cancelled_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('completed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reopened_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('creator_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('recon_matches', function (Blueprint $table): void {
            $table->unique(['id', 'company_id'], 'recon_match_company_unique');
            $table->foreign(['reconciliation_id', 'company_id'])->references(['id', 'company_id'])->on('recon_bank_reconciliations')->restrictOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('creator_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('confirmed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reversed_by')->references('id')->on('users')->nullOnDelete();
        });
        Schema::table('recon_match_lines', function (Blueprint $table): void {
            $table->foreign(['match_id', 'company_id'])->references(['id', 'company_id'])->on('recon_matches')->restrictOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('reversed_by')->references('id')->on('users')->nullOnDelete();
        });
        Schema::table('recon_adjustments', function (Blueprint $table): void {
            $table->foreign(['reconciliation_id', 'company_id'])->references(['id', 'company_id'])->on('recon_bank_reconciliations')->restrictOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('currency_id')->references('id')->on('currencies')->restrictOnDelete();
            $table->foreign('counterpart_account_id')->references('id')->on('accounts_accounts')->restrictOnDelete();
            $table->foreign('move_id')->references('id')->on('accounts_account_moves')->restrictOnDelete();
            $table->foreign('statement_line_id')->references('id')->on('accounts_bank_statement_lines')->restrictOnDelete();
            $table->foreign('creator_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reversed_by')->references('id')->on('users')->nullOnDelete();
        });
        Schema::table('recon_events', function (Blueprint $table): void {
            $table->foreign(['reconciliation_id', 'company_id'])->references(['id', 'company_id'])->on('recon_bank_reconciliations')->restrictOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
        });
        Schema::table('recon_import_batches', function (Blueprint $table): void {
            $table->unique(['id', 'company_id'], 'recon_import_batch_company_unique');
            $table->foreign(['reconciliation_id', 'company_id'])->references(['id', 'company_id'])->on('recon_bank_reconciliations')->restrictOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('currency_id')->references('id')->on('currencies')->restrictOnDelete();
            $table->foreign('accepted_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function addConstraints(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE recon_bank_reconciliations
            ADD CONSTRAINT recon_status_check CHECK (status IN (
                'draft', 'in_progress', 'review', 'completed', 'reopen_requested',
                'reopened', 'stale', 'cancelled', 'legacy_unverified'
            )),
            ADD CONSTRAINT recon_period_order_check CHECK (
                status = 'legacy_unverified' OR period_end >= period_start
            ),
            ADD CONSTRAINT recon_required_identity_check CHECK (
                status = 'legacy_unverified' OR (
                    company_id IS NOT NULL AND journal_id IS NOT NULL AND bank_account_id IS NOT NULL
                    AND liquidity_account_id IS NOT NULL AND currency_id IS NOT NULL
                    AND period_start IS NOT NULL AND period_end IS NOT NULL
                )
            ),
            ADD CONSTRAINT recon_continuity_check CHECK (
                continuity_type IS NULL OR continuity_type IN ('continuous', 'first_time', 'discontinuity')
            ),
            ADD CONSTRAINT recon_discontinuity_reason_check CHECK (
                continuity_type <> 'discontinuity' OR continuity_reason IS NOT NULL
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE recon_matches
            ADD CONSTRAINT recon_match_status_check CHECK (status IN ('proposed', 'confirmed', 'rejected', 'reversed')),
            ADD CONSTRAINT recon_match_type_check CHECK (match_type IN ('auto', 'manual'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE recon_match_lines
            ADD CONSTRAINT recon_match_line_side_check CHECK (side IN ('bank', 'ledger')),
            ADD CONSTRAINT recon_match_line_type_check CHECK (lineable_type IN ('statement_line', 'move_line'))
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE recon_import_batches
            ADD CONSTRAINT recon_import_status_check CHECK (
                status IS NULL OR status IN ('staged', 'validated', 'accepted', 'rejected', 'legacy_unverified')
            ),
            ADD CONSTRAINT recon_import_period_order_check CHECK (
                statement_start_date IS NULL OR statement_end_date IS NULL OR statement_end_date >= statement_start_date
            ),
            ADD CONSTRAINT recon_accepted_import_identity_check CHECK (
                status <> 'accepted' OR (
                    company_id IS NOT NULL AND journal_id IS NOT NULL AND currency_id IS NOT NULL
                    AND source_type IS NOT NULL AND source_identifier IS NOT NULL
                    AND source_storage_path IS NOT NULL AND original_filename IS NOT NULL
                    AND content_hash IS NOT NULL AND parser_version IS NOT NULL
                    AND account_identifier IS NOT NULL AND statement_date IS NOT NULL
                    AND imported_by IS NOT NULL AND accepted_at IS NOT NULL AND accepted_by IS NOT NULL
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX recon_one_active_session_per_bank
            ON recon_bank_reconciliations (bank_account_id)
            WHERE bank_account_id IS NOT NULL
            AND status IN ('draft', 'in_progress', 'review', 'reopen_requested', 'reopened', 'stale')
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION recon_validate_identity() RETURNS trigger AS $$
            DECLARE
                journal_company_id bigint;
                journal_bank_account_id bigint;
                journal_liquidity_account_id bigint;
                journal_currency_id bigint;
                journal_type text;
                statement_company_id bigint;
                statement_journal_id bigint;
            BEGIN
                SELECT journal.company_id, journal.bank_account_id, journal.default_account_id,
                    COALESCE(journal.currency_id, company.currency_id), journal.type
                INTO journal_company_id, journal_bank_account_id, journal_liquidity_account_id,
                    journal_currency_id, journal_type
                FROM accounts_journals AS journal
                LEFT JOIN companies AS company ON company.id = journal.company_id
                WHERE journal.id = NEW.journal_id;

                IF NEW.status <> 'legacy_unverified' AND journal_type NOT IN ('bank', 'credit') THEN
                    RAISE EXCEPTION 'Bank reconciliation requires a bank or credit journal';
                END IF;

                IF NEW.company_id IS NOT NULL AND journal_company_id IS DISTINCT FROM NEW.company_id THEN
                    RAISE EXCEPTION 'Reconciliation company does not match journal company';
                END IF;
                IF NEW.bank_account_id IS NOT NULL AND journal_bank_account_id IS DISTINCT FROM NEW.bank_account_id THEN
                    RAISE EXCEPTION 'Reconciliation bank account does not match journal bank account';
                END IF;
                IF NEW.liquidity_account_id IS NOT NULL AND journal_liquidity_account_id IS DISTINCT FROM NEW.liquidity_account_id THEN
                    RAISE EXCEPTION 'Reconciliation liquidity account does not match journal default account';
                END IF;
                IF NEW.currency_id IS NOT NULL AND journal_currency_id IS DISTINCT FROM NEW.currency_id THEN
                    RAISE EXCEPTION 'Reconciliation currency does not match journal or company currency';
                END IF;

                IF NEW.statement_id IS NOT NULL THEN
                    SELECT company_id, journal_id
                    INTO statement_company_id, statement_journal_id
                    FROM accounts_bank_statements
                    WHERE id = NEW.statement_id;

                    IF statement_company_id IS DISTINCT FROM NEW.company_id
                        OR statement_journal_id IS DISTINCT FROM NEW.journal_id THEN
                        RAISE EXCEPTION 'Statement does not belong to reconciliation company and journal';
                    END IF;
                END IF;

                IF TG_OP = 'UPDATE'
                    AND OLD.status = 'legacy_unverified'
                    AND NEW.status <> 'legacy_unverified'
                    AND NEW.legacy_verified_at IS NULL THEN
                    RAISE EXCEPTION 'Legacy reconciliation must be verified before lifecycle transition';
                END IF;

                IF NEW.status <> 'cancelled'
                    AND NEW.bank_account_id IS NOT NULL
                    AND NEW.period_start IS NOT NULL
                    AND NEW.period_end IS NOT NULL
                    AND EXISTS (
                        SELECT 1
                        FROM recon_bank_reconciliations AS existing
                        WHERE existing.id <> COALESCE(NEW.id, 0)
                            AND existing.bank_account_id = NEW.bank_account_id
                            AND existing.status <> 'cancelled'
                            AND existing.period_start IS NOT NULL
                            AND existing.period_end IS NOT NULL
                            AND daterange(existing.period_start, existing.period_end, '[]')
                                && daterange(NEW.period_start, NEW.period_end, '[]')
                    ) THEN
                    RAISE EXCEPTION 'Reconciliation period overlaps an existing session for this bank account';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER recon_validate_identity_trigger
            BEFORE INSERT OR UPDATE ON recon_bank_reconciliations
            FOR EACH ROW EXECUTE FUNCTION recon_validate_identity();
        SQL);
    }
};

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
        Schema::table('accounts_bank_statement_lines', function (Blueprint $table): void {
            $table->string('terminal_treatment')->nullable();
            $table->string('terminal_reference_type')->nullable();
            $table->unsignedBigInteger('terminal_reference_id')->nullable();
            $table->text('terminal_treatment_reason')->nullable();
            $table->timestamp('terminal_treatment_at')->nullable();
            $table->foreignId('terminal_treatment_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->index(['statement_id', 'terminal_treatment'], 'bank_statement_line_terminal_index');
        });

        $this->backfillConfirmedMatches();
        $this->backfillAdjustments();
        $this->backfillApprovedExceptions();

        $trimFunction = DB::getDriverName() === 'mysql' ? 'TRIM' : 'BTRIM';
        DB::statement(<<<SQL
            ALTER TABLE accounts_bank_statement_lines
            ADD CONSTRAINT bank_statement_line_terminal_treatment_check
            CHECK (terminal_treatment IS NULL OR terminal_treatment IN ('matched', 'adjustment', 'bank_error', 'duplicate')),
            ADD CONSTRAINT bank_statement_line_terminal_reference_check
            CHECK (
                (terminal_treatment IS NULL AND terminal_reference_type IS NULL AND terminal_reference_id IS NULL AND terminal_treatment_at IS NULL)
                OR
                (terminal_treatment IS NOT NULL AND terminal_reference_type IS NOT NULL AND terminal_reference_id IS NOT NULL AND terminal_treatment_at IS NOT NULL)
            ),
            ADD CONSTRAINT bank_statement_line_terminal_reference_type_check
            CHECK (terminal_reference_type IS NULL OR terminal_reference_type IN ('match', 'adjustment', 'reconciling_item')),
            ADD CONSTRAINT bank_statement_line_terminal_reason_check
            CHECK (terminal_treatment NOT IN ('bank_error', 'duplicate') OR NULLIF({$trimFunction}(terminal_treatment_reason), '') IS NOT NULL)
        SQL);
    }

    public function down(): void
    {
        ImmutableHistory::refuseMySqlRollback();

        DB::statement(<<<'SQL'
            ALTER TABLE accounts_bank_statement_lines
            DROP CONSTRAINT IF EXISTS bank_statement_line_terminal_treatment_check,
            DROP CONSTRAINT IF EXISTS bank_statement_line_terminal_reference_check,
            DROP CONSTRAINT IF EXISTS bank_statement_line_terminal_reference_type_check,
            DROP CONSTRAINT IF EXISTS bank_statement_line_terminal_reason_check
        SQL);

        Schema::table('accounts_bank_statement_lines', function (Blueprint $table): void {
            $table->dropIndex('bank_statement_line_terminal_index');
            $table->dropForeign(['terminal_treatment_by']);
            $table->dropColumn([
                'terminal_treatment',
                'terminal_reference_type',
                'terminal_reference_id',
                'terminal_treatment_reason',
                'terminal_treatment_at',
                'terminal_treatment_by',
            ]);
        });
    }

    private function backfillConfirmedMatches(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                UPDATE accounts_bank_statement_lines AS statement_line
                JOIN recon_match_lines AS match_line ON match_line.lineable_id = statement_line.id
                JOIN recon_matches AS recon_match ON recon_match.id = match_line.match_id
                SET statement_line.terminal_treatment = 'matched',
                    statement_line.terminal_reference_type = 'match',
                    statement_line.terminal_reference_id = recon_match.id,
                    statement_line.terminal_treatment_at = COALESCE(recon_match.confirmed_at, recon_match.updated_at),
                    statement_line.terminal_treatment_by = recon_match.confirmed_by
                WHERE match_line.lineable_type = 'statement_line'
                  AND match_line.reversed_at IS NULL
                  AND recon_match.status = 'confirmed'
                  AND recon_match.reversed_at IS NULL
                  AND ABS(COALESCE(statement_line.amount_residual, 0)) <= 0.005
            SQL);

            return;
        }

        DB::statement(<<<'SQL'
            UPDATE accounts_bank_statement_lines AS statement_line
            SET terminal_treatment = 'matched',
                terminal_reference_type = 'match',
                terminal_reference_id = recon_match.id,
                terminal_treatment_at = COALESCE(recon_match.confirmed_at, recon_match.updated_at),
                terminal_treatment_by = recon_match.confirmed_by
            FROM recon_match_lines AS match_line
            JOIN recon_matches AS recon_match ON recon_match.id = match_line.match_id
            WHERE match_line.lineable_type = 'statement_line'
              AND match_line.lineable_id = statement_line.id
              AND match_line.reversed_at IS NULL
              AND recon_match.status = 'confirmed'
              AND recon_match.reversed_at IS NULL
              AND ABS(COALESCE(statement_line.amount_residual, 0)) <= 0.005
        SQL);
    }

    private function backfillAdjustments(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                UPDATE accounts_bank_statement_lines AS statement_line
                JOIN recon_adjustments AS adjustment ON adjustment.statement_line_id = statement_line.id
                SET statement_line.terminal_treatment = 'adjustment',
                    statement_line.terminal_reference_type = 'adjustment',
                    statement_line.terminal_reference_id = adjustment.id,
                    statement_line.terminal_treatment_at = COALESCE(adjustment.posted_at, adjustment.updated_at),
                    statement_line.terminal_treatment_by = adjustment.creator_id
                WHERE adjustment.reversed_at IS NULL
                  AND statement_line.terminal_treatment IS NULL
            SQL);

            return;
        }

        DB::statement(<<<'SQL'
            UPDATE accounts_bank_statement_lines AS statement_line
            SET terminal_treatment = 'adjustment',
                terminal_reference_type = 'adjustment',
                terminal_reference_id = adjustment.id,
                terminal_treatment_at = COALESCE(adjustment.posted_at, adjustment.updated_at),
                terminal_treatment_by = adjustment.creator_id
            FROM recon_adjustments AS adjustment
            WHERE adjustment.statement_line_id = statement_line.id
              AND adjustment.reversed_at IS NULL
              AND statement_line.terminal_treatment IS NULL
        SQL);
    }

    private function backfillApprovedExceptions(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                UPDATE accounts_bank_statement_lines AS statement_line
                JOIN recon_reconciling_items AS item ON item.statement_line_id = statement_line.id
                SET statement_line.terminal_treatment = CASE item.classification WHEN 'bank_error' THEN 'bank_error' ELSE 'duplicate' END,
                    statement_line.terminal_reference_type = 'reconciling_item',
                    statement_line.terminal_reference_id = item.id,
                    statement_line.terminal_treatment_reason = COALESCE(NULLIF(TRIM(item.notes), ''), 'Legacy approved exception'),
                    statement_line.terminal_treatment_at = item.resolved_at,
                    statement_line.terminal_treatment_by = item.resolved_by
                WHERE ((item.classification = 'bank_error' AND item.status = 'resolved')
                    OR (item.classification = 'duplicate' AND item.status = 'excluded'))
                  AND statement_line.terminal_treatment IS NULL
            SQL);

            return;
        }

        DB::statement(<<<'SQL'
            UPDATE accounts_bank_statement_lines AS statement_line
            SET terminal_treatment = CASE item.classification WHEN 'bank_error' THEN 'bank_error' ELSE 'duplicate' END,
                terminal_reference_type = 'reconciling_item',
                terminal_reference_id = item.id,
                terminal_treatment_reason = COALESCE(NULLIF(BTRIM(item.notes), ''), 'Legacy approved exception'),
                terminal_treatment_at = item.resolved_at,
                terminal_treatment_by = item.resolved_by
            FROM recon_reconciling_items AS item
            WHERE item.statement_line_id = statement_line.id
              AND ((item.classification = 'bank_error' AND item.status = 'resolved')
                OR (item.classification = 'duplicate' AND item.status = 'excluded'))
              AND statement_line.terminal_treatment IS NULL
        SQL);
    }
};

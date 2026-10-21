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
        Schema::create('recon_bank_opening_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('bank_account_id')->constrained('partners_bank_accounts')->restrictOnDelete();
            $table->foreignId('journal_id')->constrained('accounts_journals')->restrictOnDelete();
            $table->foreignId('liquidity_account_id')->constrained('accounts_accounts')->restrictOnDelete();
            $table->foreignId('counterpart_account_id')->constrained('accounts_accounts')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->foreignId('move_id')->unique()->constrained('accounts_account_moves')->restrictOnDelete();
            $table->date('effective_date');
            $table->decimal('amount', 18, 4);
            $table->text('notes')->nullable();
            $creator = $table->foreignId('creator_id')->nullable()->constrained('users');
            if (DB::getDriverName() === 'mysql') {
                // MySQL FK actions bypass triggers; retain the immutable recorded actor.
                $creator->restrictOnDelete();
            } else {
                $creator->nullOnDelete();
            }
            $table->timestamps();

            // An opening balance establishes the first controlled GL baseline for a bank account.
            // Corrections must be made through a reversing entry, never by creating a second baseline.
            $table->unique(['company_id', 'bank_account_id'], 'recon_bank_opening_balance_once_per_account');
        });

        DB::statement('ALTER TABLE recon_bank_opening_balances ADD CONSTRAINT recon_bank_opening_balance_nonzero_check CHECK (amount <> 0)');
    }

    public function down(): void
    {
        ImmutableHistory::refuseMySqlRollback();

        DB::transaction(function (): void {
            DB::statement('LOCK TABLE recon_bank_opening_balances IN ACCESS EXCLUSIVE MODE');
            if (DB::table('recon_bank_opening_balances')->exists()) {
                throw new RuntimeException('Saved bank opening evidence requires forward recovery; rollback cannot discard that history.');
            }

            Schema::dropIfExists('recon_bank_opening_balances');
        });
    }
};

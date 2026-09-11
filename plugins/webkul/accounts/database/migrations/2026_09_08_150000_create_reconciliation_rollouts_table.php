<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_rollouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('companies')->restrictOnDelete();
            $table->string('phase')->default('p0');
            $table->timestamp('backup_confirmed_at')->nullable();
            $table->timestamp('staging_verified_at')->nullable();
            $table->timestamp('controller_signed_off_at')->nullable();
            $table->string('controller_name')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->string('enabled_by')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->string('disabled_by')->nullable();
            $table->text('disable_reason')->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE reconciliation_rollouts ADD CONSTRAINT reconciliation_rollout_phase_check CHECK (phase IN ('p0', 'p1', 'p2'))");
        DB::table('recon_bank_reconciliations')->whereNotNull('company_id')->distinct()->pluck('company_id')->each(
            fn (int $companyId) => DB::table('reconciliation_rollouts')->insertOrIgnore([
                'company_id'     => $companyId,
                'phase'          => 'p0',
                'disabled_at'    => now(),
                'disabled_by'    => 'migration',
                'disable_reason' => 'Awaiting legacy audit and controller sign-off.',
                'created_at'     => now(),
                'updated_at'     => now(),
            ]),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_rollouts');
    }
};

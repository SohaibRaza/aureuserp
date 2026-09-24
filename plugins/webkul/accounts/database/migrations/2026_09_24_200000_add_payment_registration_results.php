<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts_payment_registers', function (Blueprint $table): void {
            $table->string('registration_state')->default('legacy_unverified');
            $table->string('registration_fingerprint', 64)->nullable();
            $table->json('registered_payment_ids')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->string('request_key', 128)->nullable();
            $table->string('request_fingerprint', 64)->nullable();
            $table->unique(['company_id', 'request_key'], 'accounts_payment_request_unique');
        });

        Schema::table('accounts_payment_registers', function (Blueprint $table): void {
            $table->string('registration_state')->default('pending')->change();
        });
    }

    public function down(): void
    {
        if (DB::table('accounts_payment_registers')->where('registration_state', 'completed')->exists()) {
            throw new RuntimeException('Payment registration results must be retained after payments have been recorded.');
        }

        Schema::table('accounts_payment_registers', function (Blueprint $table): void {
            $table->dropUnique('accounts_payment_request_unique');
            $table->dropColumn(['request_key', 'request_fingerprint', 'registration_state', 'registration_fingerprint', 'registered_payment_ids', 'registered_at']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recon_matches', function (Blueprint $table): void {
            $table->string('idempotency_fingerprint', 64)->nullable()->after('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('recon_matches', function (Blueprint $table): void {
            $table->dropColumn('idempotency_fingerprint');
        });
    }
};

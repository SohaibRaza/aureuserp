<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('accounts_partial_reconciles', 'recon_match_id')) {
            Schema::table('accounts_partial_reconciles', function (Blueprint $table): void {
                $table->unsignedBigInteger('recon_match_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('accounts_partial_reconciles', 'recon_match_id')) {
            Schema::table('accounts_partial_reconciles', function (Blueprint $table): void {
                $table->dropColumn('recon_match_id');
            });
        }
    }
};

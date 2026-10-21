<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('accounts_payment_registers', function (Blueprint $table): void {
            $table->string('request_key', 128)->charset('utf8mb4')->collation('utf8mb4_bin')->nullable()->change();
        });
        if (! Schema::hasTable('accounts_payment_request_locks')) {
            Schema::create('accounts_payment_request_locks', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('request_hash', 64)->primary();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            throw new RuntimeException('Payment request identities and transaction locks require forward recovery.');
        }
    }
};

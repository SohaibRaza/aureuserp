<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_company_controls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('companies')->restrictOnDelete();
            $table->boolean('accept_new_activity')->default(false);
            $table->timestamps();
        });

        Schema::create('logistics_control_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->boolean('old_value');
            $table->boolean('new_value');
            $table->text('reason');
            $table->timestamp('created_at');
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_control_changes');
        Schema::dropIfExists('logistics_company_controls');
    }
};

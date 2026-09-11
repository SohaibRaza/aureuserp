<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('journal_id')->nullable()->constrained('accounts_journals')->restrictOnDelete();
            $table->string('name');
            $table->unsignedInteger('priority')->default(100);
            $table->json('conditions');
            $table->string('action')->default('propose');
            $table->decimal('approval_threshold', 8, 2)->default(14);
            $table->unsignedInteger('version')->default(1);
            $table->boolean('active')->default(true);
            $table->date('active_from')->nullable();
            $table->date('active_until')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'name', 'version']);
            $table->index(['company_id', 'journal_id', 'active', 'priority']);
        });

        Schema::table('recon_matches', function (Blueprint $table): void {
            $table->foreignId('rule_id')->nullable()->constrained('reconciliation_rules')->nullOnDelete();
        });

    }

    public function down(): void
    {
        Schema::table('recon_matches', function (Blueprint $table): void {
            $table->dropForeign(['rule_id']);
            $table->dropColumn('rule_id');
        });
        Schema::dropIfExists('reconciliation_rules');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_evidence_retention_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('evidence_class', 80);
            $table->unsignedInteger('revision');
            $table->unsignedInteger('retention_days');
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->text('approval_reason');
            $table->timestampTz('approved_at');
            $table->timestampTz('created_at');
            $table->unique(['company_id', 'evidence_class', 'revision'], 'logistics_evidence_policy_revision_unique');
            $table->index(['company_id', 'evidence_class', 'approved_at'], 'logistics_evidence_policy_current_idx');
        });

        Schema::create('logistics_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('retention_policy_id')->constrained('logistics_evidence_retention_policies')->restrictOnDelete();
            $table->string('evidence_class', 80);
            $table->string('purpose', 120);
            $table->string('original_name', 255);
            $table->string('object_key', 180)->unique();
            $table->char('sha256', 64);
            $table->unsignedBigInteger('byte_size');
            $table->string('detected_mime_type', 100);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('accepted_at');
            $table->timestampTz('retain_until');
            $table->timestampsTz();
            $table->index(['company_id', 'evidence_class', 'accepted_at'], 'logistics_evidence_company_class_idx');
        });

        Schema::create('logistics_evidence_lifecycle_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('evidence_id')->constrained('logistics_evidence')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('event_type', 50);
            $table->text('reason')->nullable();
            $table->string('deletion_result', 100)->nullable();
            $table->timestampTz('created_at');
            $table->index(['evidence_id', 'created_at'], 'logistics_evidence_lifecycle_history_idx');
        });

        Schema::create('logistics_evidence_legal_holds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('evidence_id')->constrained('logistics_evidence')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 16);
            $table->text('reason');
            $table->foreignId('related_hold_id')->nullable()->constrained('logistics_evidence_legal_holds')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->index(['evidence_id', 'created_at'], 'logistics_evidence_hold_history_idx');
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement('LOCK TABLE logistics_evidence_retention_policies, logistics_evidence, logistics_evidence_lifecycle_events, logistics_evidence_legal_holds IN ACCESS EXCLUSIVE MODE');

            if (DB::table('logistics_evidence_retention_policies')->exists()
                || DB::table('logistics_evidence')->exists()
                || DB::table('logistics_evidence_lifecycle_events')->exists()
                || DB::table('logistics_evidence_legal_holds')->exists()) {
                throw new RuntimeException('Accepted evidence, retention policy, lifecycle or legal-hold history requires forward recovery; rollback cannot discard that history.');
            }

            Schema::dropIfExists('logistics_evidence_legal_holds');
            Schema::dropIfExists('logistics_evidence_lifecycle_events');
            Schema::dropIfExists('logistics_evidence');
            Schema::dropIfExists('logistics_evidence_retention_policies');
        });
    }
};

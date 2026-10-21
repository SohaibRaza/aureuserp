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
        Schema::create('logistics_command_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('command_type', 120);
            $table->string('idempotency_key', 128);
            $table->char('intent_hash', 64);
            $table->unsignedBigInteger('control_revision')->nullable();
            $table->json('result');
            $table->timestampTz('created_at');
            $table->unique(['company_id', 'command_type', 'idempotency_key'], 'logistics_command_identity_unique');
            $table->index(['company_id', 'created_at'], 'logistics_command_company_created_idx');
        });

        Schema::create('logistics_scoped_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('command_idempotency_key', 128);
            $table->string('action', 120);
            $table->string('scope_type', 120);
            $table->unsignedBigInteger('scope_id');
            $table->unsignedBigInteger('scope_revision');
            $table->json('limits');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->unsignedBigInteger('control_revision');
            $table->timestampTz('valid_until');
            $table->timestampTz('created_at');
            $table->unique(['company_id', 'command_idempotency_key'], 'logistics_approval_command_unique');
            $table->index(['company_id', 'action', 'scope_type', 'scope_id'], 'logistics_scoped_approval_scope_idx');
        });

        Schema::create('logistics_approval_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('approval_id')->constrained('logistics_scoped_approvals')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('event_type', 20);
            $table->text('reason')->nullable();
            $table->string('command_idempotency_key', 128)->nullable();
            $table->timestampTz('created_at');
            $table->index(['approval_id', 'id'], 'logistics_approval_event_history_idx');
        });

        ImmutableHistory::protect('logistics_command_records');
        ImmutableHistory::protect('logistics_scoped_approvals');
        ImmutableHistory::protect('logistics_approval_events');
    }

    public function down(): void
    {
        ImmutableHistory::refuseMySqlRollback();

        DB::transaction(function (): void {
            DB::statement('LOCK TABLE logistics_command_records, logistics_scoped_approvals, logistics_approval_events IN ACCESS EXCLUSIVE MODE');

            if (DB::table('logistics_command_records')->exists()
                || DB::table('logistics_scoped_approvals')->exists()
                || DB::table('logistics_approval_events')->exists()) {
                throw new RuntimeException('Accepted command or approval history requires forward recovery; rollback cannot discard that history.');
            }

            Schema::dropIfExists('logistics_approval_events');
            Schema::dropIfExists('logistics_scoped_approvals');
            Schema::dropIfExists('logistics_command_records');
            DB::statement('DROP FUNCTION IF EXISTS logistics_command_records_immutable()');
            DB::statement('DROP FUNCTION IF EXISTS logistics_scoped_approvals_immutable()');
            DB::statement('DROP FUNCTION IF EXISTS logistics_approval_events_immutable()');
        });
    }
};

#!/usr/bin/env bash

set -euo pipefail

PHP_BIN="${PHP_BIN:-/opt/homebrew/opt/php@8.4/bin/php}"
REHEARSAL_DATABASE="${1:-}"

if [[ ! "$REHEARSAL_DATABASE" =~ ^aureuserp_u18_rehearsal_[a-zA-Z0-9_]+$ ]]; then
    echo "Refusing to run: database must match aureuserp_u18_rehearsal_<suffix>." >&2
    exit 2
fi

export APP_ENV=testing
export DB_CONNECTION=pgsql
export DB_DATABASE="$REHEARSAL_DATABASE"

artisan() {
    "$PHP_BIN" -d memory_limit=512M -d max_execution_time=0 artisan "$@"
}

db_scalar() {
    local php_expression="$1"

    "$PHP_BIN" -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();
        echo ('"$php_expression"');
    '
}

migrations=(
    database/migrations/2026_09_07_000001_create_recon_tables.php
    plugins/webkul/accounts/database/migrations/2026_09_07_000002_add_recon_match_id_to_partial_reconciles.php
    plugins/webkul/accounts/database/migrations/2026_09_07_000004_harden_reconciliation_schema.php
    plugins/webkul/accounts/database/migrations/2026_09_07_000005_add_bank_statement_line_source_fields.php
    plugins/webkul/accounts/database/migrations/2026_09_07_000006_create_reconciling_items_table.php
    plugins/webkul/accounts/database/migrations/2026_09_07_000007_create_reconciliation_snapshots_table.php
    plugins/webkul/accounts/database/migrations/2026_09_07_193726_add_active_content_hash_unique_index_to_recon_import_batches.php
    plugins/webkul/accounts/database/migrations/2026_09_08_090414_allow_multiple_stale_reconciliations_per_bank.php
    plugins/webkul/accounts/database/migrations/2026_09_08_130000_add_terminal_treatment_to_bank_statement_lines.php
    plugins/webkul/accounts/database/migrations/2026_09_08_140000_create_reconciliation_rules_table.php
    plugins/webkul/accounts/database/migrations/2026_09_08_150000_create_reconciliation_rollouts_table.php
    database/migrations/2026_09_10_000001_add_accounting_provenance_to_recon_matches.php
    database/migrations/2026_09_10_000002_add_idempotency_fingerprint_to_recon_matches.php
    plugins/webkul/accounts/database/migrations/2026_09_10_000003_require_reconciliation_reversal_reason.php
    plugins/webkul/accounts/database/migrations/2026_09_10_000004_add_quarantined_reconciliation_import_status.php
    plugins/webkul/accounts/database/migrations/2026_09_10_000005_include_quarantined_reconciliation_import_hashes.php
    plugins/webkul/accounts/database/migrations/2026_09_10_000006_protect_reconciliation_audit_events.php
)

echo "Rebuilding disposable database: $REHEARSAL_DATABASE"
artisan migrate:fresh --force --no-interaction
artisan erp:install \
    --force \
    --admin-name='U18 Rehearsal' \
    --admin-email='u18@example.invalid' \
    --admin-password='Rehearsal-Only-2026!' \
    --no-interaction
artisan accounts:install --no-interaction

schema_ready="$(db_scalar "(int) (Illuminate\\Support\\Facades\\Schema::hasTable('recon_reconciliation_snapshots') && Illuminate\\Support\\Facades\\Schema::hasColumn('accounts_bank_statement_lines', 'terminal_treatment'))")"
if [[ "$schema_ready" != "1" ]]; then
    echo "Fresh-install schema assertion failed." >&2
    exit 1
fi

evidence_rows="$(db_scalar "(int) (Illuminate\\Support\\Facades\\DB::table('recon_events')->count() + Illuminate\\Support\\Facades\\DB::table('recon_reconciliation_snapshots')->count())")"
if [[ "$evidence_rows" != "0" ]]; then
    echo "Refusing rollback: immutable reconciliation evidence exists in the rehearsal database." >&2
    exit 3
fi

echo "Rolling back reconciliation plugin migrations in dependency-safe reverse order."
for ((index=${#migrations[@]}-1; index>=0; index--)); do
    migration_path="${migrations[$index]}"
    migration_name="$(basename "$migration_path" .php)"
    batch="$(db_scalar "(int) Illuminate\\Support\\Facades\\DB::table('migrations')->where('migration', '$migration_name')->value('batch')")"

    if [[ "$batch" == "0" ]]; then
        echo "Expected migration is not registered: $migration_name" >&2
        exit 1
    fi

    artisan migrate:rollback \
        --force \
        --no-interaction \
        --batch="$batch" \
        --path="$migration_path" >/dev/null
done

baseline_intact="$(db_scalar "(int) (! Illuminate\\Support\\Facades\\Schema::hasTable('recon_bank_reconciliations') && ! Illuminate\\Support\\Facades\\Schema::hasTable('recon_reconciliation_snapshots') && ! Illuminate\\Support\\Facades\\Schema::hasColumn('accounts_bank_statement_lines', 'terminal_treatment') && ! Illuminate\\Support\\Facades\\Schema::hasColumn('accounts_partial_reconciles', 'recon_match_id'))")"
if [[ "$baseline_intact" != "1" ]]; then
    echo "Rollback-state schema assertion failed." >&2
    exit 1
fi

echo "Reapplying reconciliation plugin migrations."
migrate_args=()
for migration_path in "${migrations[@]}"; do
    migrate_args+=(--path="$migration_path")
done
artisan migrate --force --no-interaction "${migrate_args[@]}"

controls_restored="$(db_scalar "(int) (Illuminate\\Support\\Facades\\DB::selectOne(\"SELECT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'recon_event_immutable_trigger' AND NOT tgisinternal) AS present\")->present && Illuminate\\Support\\Facades\\DB::selectOne(\"SELECT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'recon_snapshot_reconciliation_company_foreign') AS present\")->present && Illuminate\\Support\\Facades\\DB::selectOne(\"SELECT EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = 'recon_one_active_session_per_bank') AS present\")->present)")"
if [[ "$controls_restored" != "1" ]]; then
    echo "Reapplied database-control assertion failed." >&2
    exit 1
fi

echo "Running reconciliation schema behavior tests on $REHEARSAL_DATABASE."
AUREUSERP_TEST_REUSE_DATABASE=1 "$PHP_BIN" \
    -d memory_limit=512M \
    -d max_execution_time=0 \
    vendor/bin/pest --compact tests/Feature/Recon/ReconciliationSchemaTest.php

echo "Running isolated two-connection contention proof on $REHEARSAL_DATABASE."
AUREUSERP_TEST_REUSE_DATABASE=1 AUREUSERP_RUN_CONCURRENCY_TEST=1 "$PHP_BIN" \
    -d memory_limit=512M \
    -d max_execution_time=0 \
    vendor/bin/pest --compact tests/Feature/Recon/MatchConcurrencyTest.php

echo "U18 migration rehearsal passed on disposable database: $REHEARSAL_DATABASE"

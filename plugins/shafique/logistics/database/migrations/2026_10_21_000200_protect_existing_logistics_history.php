<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Fresh MySQL installs receive these triggers in their owning migrations.
        // A partially failed MySQL installation needs inspected forward recovery.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'logistics_control_changes',
            'logistics_evidence_retention_policies',
            'logistics_evidence',
            'logistics_evidence_lifecycle_events',
            'logistics_evidence_legal_holds',
            'logistics_command_records',
            'logistics_scoped_approvals',
            'logistics_approval_events',
        ] as $table) {
            $relation = DB::selectOne(<<<'SQL'
                SELECT relation.oid, namespace.nspname AS schema_name
                FROM pg_class AS relation JOIN pg_namespace AS namespace ON namespace.oid = relation.relnamespace
                WHERE relation.oid = to_regclass(?) AND relation.relkind = 'r'
            SQL, [$table]);
            if (! $relation) {
                throw new RuntimeException("Missing {$table}; inspect the Logistics installation before protecting history.");
            }

            $name = $table.'_immutable';
            $function = DB::selectOne(<<<'SQL'
                SELECT routine.oid, routine.prosrc, routine.prorettype = 'trigger'::regtype AS returns_trigger,
                    language.lanname AS language
                FROM pg_proc AS routine
                JOIN pg_namespace AS namespace ON namespace.oid = routine.pronamespace
                JOIN pg_language AS language ON language.oid = routine.prolang
                WHERE namespace.nspname = ? AND routine.proname = ? AND routine.pronargs = 0
            SQL, [$relation->schema_name, $name]);
            $body = "BEGIN RAISE EXCEPTION 'Saved history cannot be changed or deleted.'; END;";
            if ($function && (! $function->returns_trigger || $function->language !== 'plpgsql'
                || preg_replace('/\s+/', ' ', trim($function->prosrc)) !== $body)) {
                throw new RuntimeException("Conflicting {$name} function; inspect it before continuing.");
            }

            $trigger = DB::selectOne(<<<'SQL'
                SELECT tgfoid, tgtype, tgenabled, tgqual IS NULL AS unconditional, tgnargs
                FROM pg_trigger WHERE tgrelid = ? AND tgname = ? AND NOT tgisinternal
            SQL, [$relation->oid, $name]);
            if ($trigger) {
                if (! $function || (int) $trigger->tgfoid !== (int) $function->oid
                    || (int) $trigger->tgtype !== 27 || ! $trigger->unconditional
                    || (int) $trigger->tgnargs !== 0 || ! in_array($trigger->tgenabled, ['O', 'A'], true)) {
                    throw new RuntimeException("Conflicting or disabled {$name} trigger; inspect it before continuing.");
                }

                continue;
            }

            $schema = '"'.str_replace('"', '""', $relation->schema_name).'"';
            $qualifiedFunction = $schema.'."'.$name.'"';
            if (! $function) {
                DB::unprepared("CREATE FUNCTION {$qualifiedFunction}() RETURNS trigger LANGUAGE plpgsql AS $$ {$body} $$");
            }
            DB::unprepared("CREATE TRIGGER {$name} BEFORE UPDATE OR DELETE ON {$schema}.\"{$table}\" FOR EACH ROW EXECUTE FUNCTION {$qualifiedFunction}()");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Saved Logistics history protections require forward recovery; rollback cannot remove them.');
    }
};

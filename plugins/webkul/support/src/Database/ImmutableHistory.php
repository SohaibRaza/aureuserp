<?php

namespace Webkul\Support\Database;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class ImmutableHistory
{
    public static function protect(string $table): void
    {
        if (! preg_match('/^[a-z][a-z0-9_]{0,43}$/D', $table)) {
            throw new InvalidArgumentException('History tables require a short SQL identifier.');
        }

        if (DB::getDriverName() === 'mysql') {
            foreach (['update', 'delete'] as $event) {
                DB::unprepared("CREATE TRIGGER {$table}_immutable_{$event} BEFORE {$event} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Saved history cannot be changed or deleted.'");
            }

            return;
        }

        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Immutable history requires PostgreSQL or MySQL.');
        }

        DB::unprepared("CREATE FUNCTION {$table}_immutable() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Saved history cannot be changed or deleted.'; END; $$;
CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION {$table}_immutable();");
    }

    public static function refuseMySqlRollback(): void
    {
        // MySQL DDL commits implicitly: a transaction cannot protect a check-then-drop.
        if (DB::getDriverName() === 'mysql') {
            throw new RuntimeException('MySQL history migrations require forward recovery; rollback is refused before any schema or history changes, even when empty.');
        }
    }
}

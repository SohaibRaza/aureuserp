<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSIONS = [
        'page_filament_list_reconciliations',
        'page_filament_reconcile_workspace',
        'page_filament_recon_report',
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $name) {
            DB::table('permissions')->insertOrIgnore([
                'name'       => $name,
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('name', self::PERMISSIONS)
            ->pluck('id');
        $roleIds = DB::table('roles')->whereIn('name', ['Admin', 'Accountant'])->pluck('id');

        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id'       => $roleId,
                ]);
            }
        }
    }

    public function down(): void
    {
        // These names and role grants may have existed before this migration.
        // Without persisted provenance, deleting them on rollback can revoke
        // administrator-defined access. Retaining additive RBAC rows is the
        // only ownership-safe rollback behavior.
    }
};

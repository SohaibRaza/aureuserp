<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = ['reconciliation.view', 'reconciliation.prepare', 'reconciliation.review', 'reconciliation.complete', 'reconciliation.reopen'];
        foreach ($permissions as $name) {
            DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        }

        $permissionIds = DB::table('permissions')->whereIn('name', $permissions)->pluck('id');
        $roleIds = DB::table('roles')->whereIn('name', ['Admin', 'Accountant'])->pluck('id');
        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }
    }

    public function down(): void
    {
        // This migration uses insert-or-ignore and therefore cannot distinguish
        // permissions or grants it created from pre-existing administrator data.
        // Do not destructively revoke shared RBAC state during rollback.
    }
};

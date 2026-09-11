<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSION = 'reconciliation.opening_balance.post';

    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore([
            'name'       => self::PERMISSION,
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $permissionId = DB::table('permissions')->where('name', self::PERMISSION)->value('id');
        $roleIds = DB::table('roles')->whereIn('name', ['Admin', 'Accountant'])->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id'       => $roleId,
            ]);
        }
    }

    public function down(): void
    {
        // Additive RBAC rows may be customized after deployment; rollback must not revoke them.
    }
};

<?php

/**
 * Local customization for this AureusERP install.
 *
 * Applies:
 *   1. Deep-teal brand color (editable later in UI: Settings -> Branding)
 *   2. Four business roles with permissions scoped by the Shield naming
 *      scheme:  <verb>_<module>_<resource>...
 *   3. One demo user per role
 *
 * Run:
 *   cd ~/Projects/aureuserp
 *   /opt/homebrew/opt/php@8.4/bin/php -d memory_limit=1G scripts/setup_customization.php
 *
 * Re-running is idempotent: branding is re-applied, roles are updated
 * (syncPermissions), users are firstOrCreate'd.
 */

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Settings\BrandSettings;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';

$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// ---------------------------------------------------------------------------
// 1. Branding — deep teal
// ---------------------------------------------------------------------------
$brand = settings(BrandSettings::class);

$brand->primary_color = '#0F766E';   // deep teal
$brand->gray_color    = '#52525B';   // zinc-ish neutral
$brand->danger_color  = '#DC2626';
$brand->info_color    = '#0284C7';
$brand->success_color = '#16A34A';
$brand->warning_color = '#D97706';
$brand->save();

echo "✔ Branding applied (primary {$brand->primary_color}) — edit in Settings → Branding\n";

// ---------------------------------------------------------------------------
// 2. Roles
// ---------------------------------------------------------------------------
/*
 * Shield permission names follow:  <verb>_<token>[_<resource>...]
 *   verbs: view_any, view, create, update, delete_any, delete,
 *          restore_any, restore, force_delete_any, force_delete,
 *          reorder, page, widget
 * Tokens are module/cluster slugs (sale, inventory, account, accounting, ...).
 * Names containing "_manage_" are management/settings/dashboard surfaces —
 * excluded from business roles (admin keeps everything).
 */
$roleSpecs = [
    'Salesperson' => [
        [['view_any', 'view', 'create', 'update', 'page', 'widget'],
         ['sale', 'contact', 'partner', 'product']],
        [['view_any', 'view', 'page', 'widget'],
         ['invoice', 'accounting_aged', 'accounting_balance', 'accounting_profit', 'accounting_trial', 'accounting_general']],
    ],
    'Warehouse' => [
        [['view_any', 'view', 'create', 'update', 'page', 'widget'],
         ['inventory', 'product', 'barcode_launch']],
        [['view_any', 'view', 'page', 'widget'],
         ['sale', 'purchase', 'manufacturing']],
    ],
    'Accountant' => [
        [['view_any', 'view', 'create', 'update', 'page', 'widget'],
         ['account', 'accounting', 'invoice']],
        [['view_any', 'view', 'page', 'widget'],
         ['sale', 'purchase', 'product', 'contact', 'partner']],
    ],
    'HR Manager' => [
        [['view_any', 'view', 'create', 'update', 'page', 'widget'],
         ['employee', 'recruitment', 'time_off', 'timesheet']],
        [['view_any', 'view', 'page', 'widget'],
         ['project']],
    ],
];

function buildPermissionRegex(array $verbs, array $tokens): string
{
    $v = implode('|', array_map(fn ($x) => preg_quote($x, '/'), $verbs));
    $t = implode('|', array_map(fn ($x) => preg_quote($x, '/'), $tokens));

    return "/^(?:{$v})_(?:{$t})(?:_.+)?$/";
}

$allPermissionNames = DB::table('permissions')->pluck('name')->all();

foreach ($roleSpecs as $roleName => $groups) {
    $matched = [];

    foreach ($groups as [$verbs, $tokens]) {
        $regex = buildPermissionRegex($verbs, $tokens);

        foreach ($allPermissionNames as $name) {
            if (preg_match($regex, $name) && ! str_contains($name, '_manage_')) {
                $matched[$name] = true;
            }
        }
    }

    $names = array_keys($matched);

    if ($names === []) {
        echo "⚠ {$roleName}: no permissions matched — check tokens\n";

        continue;
    }

    $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    $role->syncPermissions($names);

    echo "✔ Role {$roleName}: ".count($names)." permissions\n";
}

// ---------------------------------------------------------------------------
// 3. Demo users
// ---------------------------------------------------------------------------
$companyId = DB::table('companies')->value('id');

$demoUsers = [
    ['Maya Chen',  'sales@erp.local',      'Salesperson'],
    ['Sam Okafor', 'warehouse@erp.local',  'Warehouse'],
    ['Priya Rao',  'accountant@erp.local', 'Accountant'],
    ['Liam Novak', 'hr@erp.local',         'HR Manager'],
];

foreach ($demoUsers as [$name, $email, $roleName]) {
    $user = User::firstOrCreate(
        ['email' => $email],
        [
            'name'                => $name,
            'password'            => Hash::make('Demo@1234'),
            'is_active'           => true,
            'default_company_id'  => $companyId,
        ]
    );

    $user->assignRole($roleName);

    echo "✔ User {$email} → {$roleName}".
        (DB::table('model_has_roles')
            ->where('model_id', $user->id)
            ->count() > 1 ? ' (already had roles)' : '').
        " — password Demo@1234\n";
}

// ---------------------------------------------------------------------------
// 4. Clear caches so colors + permission changes take effect immediately
// ---------------------------------------------------------------------------
Artisan::call('config:clear');
Artisan::call('cache:clear');

echo "\nDone. Login: http://aureuserp.test/admin/login\n";
echo "  admin@aureuserp.local / Admin@1234   (full access)\n";
echo "  sales@erp.local, warehouse@erp.local, accountant@erp.local, hr@erp.local / Demo@1234\n";

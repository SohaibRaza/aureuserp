<?php

use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Shafique\Logistics\Models\CompanyControl;
use Shafique\Logistics\Models\ControlChange;
use Symfony\Component\Console\Command\Command;
use Webkul\PluginManager\Filament\Resources\PluginResource\Pages\ListPlugins;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;

require_once __DIR__.'/../../../../webkul/support/tests/Helpers/FilamentHelper.php';

/**
 * @return list<string>
 */
function logisticsProtectedPluginNames(): array
{
    return ['logistics', 'sales', 'inventories', 'accounts', 'products', 'invoices', 'payments'];
}

/**
 * @return array<string, mixed>
 */
function logisticsRemovalSnapshot(): array
{
    $plugins = Plugin::query()
        ->whereIn('name', logisticsProtectedPluginNames())
        ->orderBy('name')
        ->get(['id', 'name', 'is_installed', 'is_active'])
        ->map(fn (Plugin $plugin): array => [
            'id'           => $plugin->id,
            'name'         => $plugin->name,
            'is_installed' => (bool) $plugin->is_installed,
            'is_active'    => (bool) $plugin->is_active,
        ])
        ->all();

    $pluginIds = collect($plugins)->pluck('id')->all();

    return [
        'plugins'            => $plugins,
        'installation_cache' => collect(logisticsProtectedPluginNames())
            ->mapWithKeys(fn (string $pluginName): array => [$pluginName => Package::isPluginInstalled($pluginName)])
            ->all(),
        'dependencies' => DB::table('plugin_dependencies')
            ->whereIn('plugin_id', $pluginIds)
            ->whereIn('dependency_id', $pluginIds)
            ->orderBy('plugin_id')
            ->orderBy('dependency_id')
            ->get(['plugin_id', 'dependency_id'])
            ->map(fn (object $dependency): array => (array) $dependency)
            ->all(),
        'migrations' => DB::table('migrations')
            ->orderBy('id')
            ->get(['id', 'migration', 'batch'])
            ->map(fn (object $migration): array => (array) $migration)
            ->all(),
        'controls' => DB::table('logistics_company_controls')
            ->orderBy('id')
            ->get()
            ->map(fn (object $control): array => (array) $control)
            ->all(),
        'changes' => DB::table('logistics_control_changes')
            ->orderBy('id')
            ->get()
            ->map(fn (object $change): array => (array) $change)
            ->all(),
        'native_sentinels' => collect([
            'sales_teams',
            'inventories_locations',
            'accounts_accounts',
            'products_categories',
        ])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all(),
    ];
}

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('logistics');
    TestBootstrapHelper::ensurePluginInstalled('barcode');

    Package::$plugins = Plugin::all()->keyBy('name');

    $user = FilamentHelper::actingAs([
        'view_any_plugin_manager_plugin',
        'delete_plugin_manager_plugin',
    ]);

    CompanyControl::query()->create([
        'company_id'          => $user->default_company_id,
        'accept_new_activity' => false,
    ]);

    ControlChange::query()->create([
        'company_id' => $user->default_company_id,
        'actor_id'   => $user->id,
        'old_value'  => false,
        'new_value'  => false,
        'reason'     => 'Removal preservation sentinel',
    ]);

    $snapshot = logisticsRemovalSnapshot();

    expect($snapshot['plugins'])->toHaveCount(count(logisticsProtectedPluginNames()))
        ->and(collect($snapshot['installation_cache'])->every(fn (bool $installed): bool => $installed))->toBeTrue()
        ->and(collect($snapshot['native_sentinels'])->every(fn (int $count): bool => $count > 0))->toBeTrue();
});

it('blocks native CLI removal before changing protected state', function (string $pluginName): void {
    $snapshot = logisticsRemovalSnapshot();
    $status = Artisan::call("{$pluginName}:uninstall", ['--force' => true]);

    expect($status)->toBe(Command::FAILURE)
        ->and(Artisan::output())->toContain('Uninstallation blocked:')
        ->toContain('Suspend new activity in Logistics Module settings instead.')
        ->and(logisticsRemovalSnapshot())->toBe($snapshot);
})->with([
    'Logistics' => 'logistics',
    'Sales'     => 'sales',
    'Inventory' => 'inventories',
    'Accounts'  => 'accounts',
    'Products'  => 'products',
    'Invoices'  => 'invoices',
    'Payments'  => 'payments',
]);

it('blocks the actual Plugin Manager table uninstall before changing protected state', function (string $pluginName, string $tab): void {
    $plugin = Plugin::query()->where('name', $pluginName)->sole();
    $snapshot = logisticsRemovalSnapshot();
    $message = 'Installed Logistics and its required dependencies cannot be uninstalled. Suspend new activity in Logistics Module settings instead.';

    Livewire::test(ListPlugins::class)
        ->set('activeTab', $tab)
        ->callTableAction('uninstall', $plugin)
        ->assertNotified(
            Notification::make()
                ->title(__('plugin-manager::filament/resources/plugin.notifications.uninstalled-blocked.title'))
                ->body($message)
                ->danger()
                ->persistent()
        );

    expect(logisticsRemovalSnapshot())->toBe($snapshot);
})->with([
    'Logistics'            => ['logistics', 'extra'],
    'Inventory dependency' => ['inventories', 'installed'],
    'Products dependency'  => ['products', 'installed'],
]);

it('fails closed when Logistics installation metadata disagrees with its retained schema', function (string $metadataState): void {
    $packageCache = Package::$plugins;

    if ($metadataState === 'missing') {
        DB::table('plugins')->where('name', 'logistics')->delete();
    } else {
        DB::table('plugins')->where('name', 'logistics')->update(['is_installed' => false]);
    }

    Package::$plugins = Plugin::all()->keyBy('name');
    $snapshot = logisticsRemovalSnapshot();

    try {
        expect(Artisan::call('logistics:uninstall', ['--force' => true]))->toBe(Command::FAILURE)
            ->and(Artisan::output())->toContain('Logistics installation metadata is inconsistent.')
            ->and(logisticsRemovalSnapshot())->toBe($snapshot);
    } finally {
        Package::$plugins = $packageCache;
    }
})->with([
    'false installed flag' => 'not-installed',
    'missing plugin row'   => 'missing',
]);

it('still allows native CLI removal of an unrelated plugin', function (): void {
    Package::$plugins = Plugin::all()->keyBy('name');
    $packageCache = Package::$plugins;
    $protectedSnapshot = logisticsRemovalSnapshot();

    try {
        expect(Artisan::call('barcode:uninstall', ['--force' => true]))->toBe(Command::SUCCESS)
            ->and(Plugin::query()->where('name', 'barcode')->exists())->toBeFalse()
            ->and(logisticsRemovalSnapshot())->toBe($protectedSnapshot);
    } finally {
        Package::$plugins = $packageCache;
    }
});

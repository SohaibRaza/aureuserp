<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Webkul\Inventory\Filament\Clusters\Configurations\Resources\PackagingResource;
use Webkul\Product\Settings\ProductSettings;

require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';

const PRODUCT_SETTINGS_GROUP = 'products_product';

const PRODUCT_PRICE_LIST_SETTINGS_MIGRATION = '2026_09_15_000300_add_enable_price_lists_to_products_product_settings';

function configureProductSettings(bool $enablePriceLists): void
{
    $settings = app(ProductSettings::class);
    $settings->enable_variants = true;
    $settings->enable_uom = false;
    $settings->enable_packagings = false;
    $settings->enable_price_lists = $enablePriceLists;
    $settings->save();
}

function discoverPackagingAsWebRequest(): bool
{
    $application = app();
    $runningInConsole = new ReflectionProperty($application, 'isRunningInConsole');
    $wasRunningInConsole = $runningInConsole->getValue($application);
    $runningInConsole->setValue($application, false);

    try {
        return PackagingResource::isDiscovered();
    } finally {
        $runningInConsole->setValue($application, $wasRunningInConsole);
    }
}

function removePriceListSettingAndMigrationRecord(): void
{
    DB::table('settings')
        ->where('group', PRODUCT_SETTINGS_GROUP)
        ->where('name', 'enable_price_lists')
        ->delete();

    DB::table('migrations')
        ->where('migration', PRODUCT_PRICE_LIST_SETTINGS_MIGRATION)
        ->delete();

    app()->forgetScopedInstances();
}

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('products');
});

it('allows packaging discovery while the new price list setting migration is pending', function (): void {
    configureProductSettings(false);
    removePriceListSettingAndMigrationRecord();

    expect(discoverPackagingAsWebRequest())->toBeFalse()
        ->and(app(ProductSettings::class)->enable_price_lists)->toBeFalse()
        ->and(DB::table('settings')
            ->where('group', PRODUCT_SETTINGS_GROUP)
            ->where('name', 'enable_price_lists')
            ->exists())->toBeFalse()
        ->and(app(ProductSettings::class)->enable_variants)->toBeTrue()
        ->and(app(ProductSettings::class)->enable_uom)->toBeFalse()
        ->and(app(ProductSettings::class)->enable_packagings)->toBeFalse();
});

it('honors a persisted true price list setting', function (): void {
    configureProductSettings(true);
    app()->forgetScopedInstances();

    expect(app(ProductSettings::class)->enable_price_lists)->toBeTrue();
});

it('runs the registered guarded migration without replacing existing settings', function (): void {
    configureProductSettings(false);
    removePriceListSettingAndMigrationRecord();

    expect(Artisan::call('migrate', [
        '--path'  => 'plugins/webkul/products/database/settings/'.PRODUCT_PRICE_LIST_SETTINGS_MIGRATION.'.php',
        '--force' => true,
    ]))->toBe(0);

    expect(DB::table('settings')
        ->where('group', PRODUCT_SETTINGS_GROUP)
        ->where('name', 'enable_price_lists')
        ->value('payload'))->toBe('false');

    $settings = app(ProductSettings::class);
    $settings->enable_price_lists = true;
    $settings->save();

    $migration = require base_path('plugins/webkul/products/database/settings/'.PRODUCT_PRICE_LIST_SETTINGS_MIGRATION.'.php');
    $migration->up();
    $migration->up();

    app()->forgetScopedInstances();

    expect(app(ProductSettings::class)->enable_variants)->toBeTrue()
        ->and(app(ProductSettings::class)->enable_uom)->toBeFalse()
        ->and(app(ProductSettings::class)->enable_packagings)->toBeFalse()
        ->and(app(ProductSettings::class)->enable_price_lists)->toBeTrue()
        ->and(DB::table('migrations')
            ->where('migration', PRODUCT_PRICE_LIST_SETTINGS_MIGRATION)
            ->exists())->toBeTrue();
});

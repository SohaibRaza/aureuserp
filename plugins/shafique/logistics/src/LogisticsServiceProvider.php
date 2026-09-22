<?php

namespace Shafique\Logistics;

use Filament\Panel;
use Illuminate\Support\Facades\Event;
use Shafique\Logistics\Listeners\PreventRemoval;
use Webkul\PluginManager\Console\Commands\InstallCommand;
use Webkul\PluginManager\Events\UninstallingPlugins;
use Webkul\PluginManager\Package;
use Webkul\PluginManager\PackageServiceProvider;

class LogisticsServiceProvider extends PackageServiceProvider
{
    public const CONTROL_MIGRATION = '2026_09_19_000001_create_logistics_company_controls_table';

    public static string $name = 'logistics';

    public static string $viewNamespace = 'logistics';

    public function configureCustomPackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasViews()
            ->hasTranslations()
            ->hasMigration(self::CONTROL_MIGRATION)
            ->runsMigrations()
            ->hasDependencies(['sales', 'inventories', 'accounts', 'products'])
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command->installDependencies()->runsMigrations();
            })
            ->hasUninstallCommand(function (): void {
                // Removal is vetoed before any native uninstall side effects.
            });
    }

    public function packageRegistered(): void
    {
        Panel::configureUsing(function (Panel $panel): void {
            $panel->plugin(LogisticsPlugin::make());
        });

        Event::listen(UninstallingPlugins::class, PreventRemoval::class);
    }
}

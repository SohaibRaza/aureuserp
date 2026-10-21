<?php

namespace Shafique\Logistics;

use Filament\Panel;
use Illuminate\Support\Facades\Event;
use Shafique\Logistics\Listeners\PreventRemoval;
use Shafique\Logistics\Services\ReleaseExecutionContext;
use Shafique\Logistics\Services\TripStockMutationPrecondition;
use Webkul\Inventory\Support\StockMutationGuard;
use Webkul\PluginManager\Console\Commands\InstallCommand;
use Webkul\PluginManager\Events\UninstallingPlugins;
use Webkul\PluginManager\Package;
use Webkul\PluginManager\PackageServiceProvider;

class LogisticsServiceProvider extends PackageServiceProvider
{
    public const CONTROL_MIGRATION = '2026_09_19_000001_create_logistics_company_controls_table';

    public const CONTROL_REVISION_MIGRATION = '2026_10_16_000100_add_logistics_control_revisions';

    public const EVIDENCE_MIGRATION = '2026_10_16_000200_create_logistics_evidence_records';

    public const COMMANDS_AND_APPROVALS_MIGRATION = '2026_10_16_000300_create_logistics_commands_and_approvals';

    public const OUTBOUND_TRIP_EXECUTION_MIGRATION = '2026_10_16_000400_create_outbound_trip_execution';

    public static string $name = 'logistics';

    public static string $viewNamespace = 'logistics';

    public function configureCustomPackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasConfigFile('logistics')
            ->hasViews()
            ->hasTranslations()
            ->hasMigrations([
                self::CONTROL_MIGRATION,
                self::CONTROL_REVISION_MIGRATION,
                self::EVIDENCE_MIGRATION,
                self::COMMANDS_AND_APPROVALS_MIGRATION,
                self::OUTBOUND_TRIP_EXECUTION_MIGRATION,
                '2026_10_16_000500_add_dispatch_readiness_controls',
                '2026_10_21_000100_add_trip_recovery_history',
            ])
            ->runsMigrations()
            ->hasSeeder('Shafique\\Logistics\\LogisticsDatabaseSeeder')
            ->hasDependencies(['sales', 'inventories', 'accounts', 'products'])
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command->installDependencies()->runsMigrations()->runsSeeders();
            })
            ->hasUninstallCommand(function (): void {
                // Removal is vetoed before any native uninstall side effects.
            });
    }

    public function packageRegistered(): void
    {
        $this->app->scoped(ReleaseExecutionContext::class);
        $this->app->tag([TripStockMutationPrecondition::class], StockMutationGuard::TAG);

        Panel::configureUsing(function (Panel $panel): void {
            $panel->plugin(LogisticsPlugin::make());
        });

        Event::listen(UninstallingPlugins::class, PreventRemoval::class);
    }
}

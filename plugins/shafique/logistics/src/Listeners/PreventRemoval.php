<?php

namespace Shafique\Logistics\Listeners;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Shafique\Logistics\LogisticsServiceProvider;
use Webkul\PluginManager\Events\UninstallingPlugins;
use Webkul\PluginManager\Models\Plugin;

class PreventRemoval
{
    public function handle(UninstallingPlugins $event): void
    {
        $logistics = Plugin::query()->where('name', 'logistics')->first();

        if (! $logistics?->is_installed) {
            if (Schema::hasTable('logistics_company_controls')
                || Schema::hasTable('logistics_control_changes')
                || DB::table('migrations')->where('migration', LogisticsServiceProvider::CONTROL_MIGRATION)->exists()) {
                throw new DomainException('Logistics installation metadata is inconsistent. Restore its installed state before removing plugins.');
            }

            return;
        }

        $pending = ['logistics'];
        $protected = [];

        while ($name = array_pop($pending)) {
            if (in_array($name, $protected, true)) {
                continue;
            }

            $protected[] = $name;
            $plugin = Plugin::query()->where('name', $name)->first();

            if (! $plugin?->package) {
                throw new DomainException('Cannot verify Logistics dependencies. Restore plugin metadata before removal.');
            }

            $pending = array_merge($pending, $plugin->getDependenciesFromConfig(), $plugin->dependencies()->pluck('name')->all());
        }

        if (array_intersect($event->names, $protected)) {
            throw new DomainException('Installed Logistics and its required dependencies cannot be uninstalled. Suspend new activity in Logistics Module settings instead.');
        }
    }
}

<?php

namespace Shafique\Logistics;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Shafique\Logistics\Filament\Resources\EvidenceResource;
use Shafique\Logistics\Filament\Resources\ModuleSettingResource;
use Shafique\Logistics\Filament\Resources\TripResource;
use Webkul\PluginManager\Package;

class LogisticsPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'logistics';
    }

    public function register(Panel $panel): void
    {
        if ($panel->getId() === 'admin' && Package::isPluginInstalled($this->getId())) {
            $panel->resources([ModuleSettingResource::class, TripResource::class, EvidenceResource::class]);
            // Panel registration may run before this package registers its translations.
            app()->booted(function (): void {
                $permissions = [];
                foreach (LogisticsDatabaseSeeder::PERMISSIONS as $permission) {
                    $permissions[$permission] = __('logistics::filament/resources/trip.permissions.'.$permission);
                }
                config(['filament-shield.custom_permissions' => array_merge(config('filament-shield.custom_permissions', []), $permissions)]);
                config(['filament-shield.shield_resource.tabs.custom_permissions' => true]);
            });
        }
    }

    public function boot(Panel $panel): void
    {
        // Resources delegate consequential operations to the Logistics services.
    }
}

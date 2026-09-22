<?php

namespace Shafique\Logistics;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Shafique\Logistics\Filament\Resources\ModuleSettingResource;
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
            $panel->resources([ModuleSettingResource::class]);
        }
    }

    public function boot(Panel $panel): void
    {
        // This non-posting foundation has no panel boot work.
    }
}

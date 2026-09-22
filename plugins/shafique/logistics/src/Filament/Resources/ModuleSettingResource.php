<?php

namespace Shafique\Logistics\Filament\Resources;

use Filament\Resources\Resource;
use Shafique\Logistics\Filament\Resources\ModuleSettingResource\Pages\ModuleSettings;
use Shafique\Logistics\Models\CompanyControl;
use Webkul\PluginManager\Models\Plugin;

class ModuleSettingResource extends Resource
{
    protected static ?string $model = CompanyControl::class;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): ?string
    {
        return __('logistics::settings.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('logistics::settings.title');
    }

    public static function getPluralModelLabel(): string
    {
        return static::getModelLabel();
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_logistics_module::setting')
            && Plugin::query()->where('name', 'logistics')->where('is_installed', true)->exists();
    }

    public static function getPages(): array
    {
        return ['index' => ModuleSettings::route('/')];
    }
}

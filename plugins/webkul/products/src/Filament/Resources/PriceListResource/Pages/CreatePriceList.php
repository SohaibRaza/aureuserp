<?php

namespace Webkul\Product\Filament\Resources\PriceListResource\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Webkul\Product\Filament\Resources\PriceListResource;
use Webkul\Support\Filament\Concerns\HasRepeaterColumnManager;

class CreatePriceList extends CreateRecord
{
    use HasRepeaterColumnManager;

    protected static string $resource = PriceListResource::class;

    public function getSubNavigation(): array
    {
        if (filled($cluster = static::getCluster())) {
            return $this->generateNavigationItems($cluster::getClusteredComponents());
        }

        return [];
    }

    protected function getCreatedNotification(): Notification
    {
        return Notification::make()
            ->success()
            ->title(__('products::filament/resources/price-list/pages/create-price-list.notification.title'))
            ->body(__('products::filament/resources/price-list/pages/create-price-list.notification.body'));
    }
}

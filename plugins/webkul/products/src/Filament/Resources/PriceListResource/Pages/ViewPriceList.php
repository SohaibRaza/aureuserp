<?php

namespace Webkul\Product\Filament\Resources\PriceListResource\Pages;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Webkul\Product\Filament\Resources\PriceListResource;
use Webkul\Support\Traits\HasRecordNavigationTabs;

class ViewPriceList extends ViewRecord
{
    use HasRecordNavigationTabs;

    protected static string $resource = PriceListResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}

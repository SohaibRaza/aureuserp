<?php

namespace Webkul\Manufacturing\Filament\Clusters\Operations\Resources\WorkOrderResource\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Webkul\Manufacturing\Filament\Clusters\Operations\Resources\WorkOrderResource;
use Webkul\Support\Filament\Concerns\HasRepeaterColumnManager;
use Webkul\Support\Traits\HasRecordNavigationTabs;

class EditWorkOrder extends EditRecord
{
    use HasRecordNavigationTabs, HasRepeaterColumnManager;

    protected ?bool $hasDatabaseTransactions = true;

    protected static string $resource = WorkOrderResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->display_name;
    }

    protected function getSavedNotification(): Notification
    {
        return Notification::make()
            ->success()
            ->title(__('manufacturing::filament/clusters/operations/resources/work-order/pages/edit-work-order.notification.title'))
            ->body(__('manufacturing::filament/clusters/operations/resources/work-order/pages/edit-work-order.notification.body'));
    }
}

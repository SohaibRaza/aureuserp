<?php

namespace Webkul\Sale\Filament\Clusters\Orders\Resources\OrderResource\Pages;

use Exception;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Webkul\Sale\Filament\Clusters\Orders\Resources\OrderResource;
use Webkul\Sale\Filament\Clusters\Orders\Resources\QuotationResource\Pages\CreateQuotation as BaseCreateOrders;
use Webkul\Sale\Services\OrderWorkflow;

class CreateOrder extends BaseCreateOrders
{
    protected static string $resource = OrderResource::class;

    protected function getRedirectUrl(): string
    {
        return OrderResource::getUrl('edit', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title(__('sales::filament/clusters/orders/resources/order.pages.create.notification.title'))
            ->body(__('sales::filament/clusters/orders/resources/order.pages.create.notification.body'));
    }

    protected function beforeCreate(): void
    {
        if (filled($this->data['products'] ?? [])) {
            return;
        }

        throw ValidationException::withMessages([
            'products' => 'Add at least one product line before creating an order.',
        ]);
    }

    protected function afterCreate(): void
    {
        try {
            app(OrderWorkflow::class)->confirmCreatedOrder($this->getRecord());
        } catch (Exception $exception) {
            Notification::make()
                ->danger()
                ->title(__('sales::filament/clusters/orders/resources/order.pages.create.notification.error-title'))
                ->body($exception->getMessage())
                ->send();

            $this->halt(shouldRollbackDatabaseTransaction: true);
        }
    }
}

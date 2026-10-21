<?php

namespace Webkul\Sale\Filament\Clusters\Orders\Resources\QuotationResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Component;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Webkul\Chatter\Filament\Actions\ChatterAction;
use Webkul\Sale\Enums\OrderState;
use Webkul\Sale\Facades\SaleOrder;
use Webkul\Sale\Filament\Clusters\Orders\Resources\QuotationResource;
use Webkul\Sale\Filament\Clusters\Orders\Resources\QuotationResource\Actions as BaseActions;
use Webkul\Sale\Services\OrderSourceRevision;
use Webkul\Support\Filament\Concerns\HandlesCrossCompanyException;
use Webkul\Support\Filament\Concerns\HasRepeaterColumnManager;
use Webkul\Support\Traits\HasRecordNavigationTabs;
use Webkul\Support\Traits\RefreshesRecordState;

class EditQuotation extends EditRecord
{
    use HandlesCrossCompanyException {
        save as protected saveWithCrossCompanyHandling;
    }
    use HasRecordNavigationTabs;
    use HasRepeaterColumnManager;
    use RefreshesRecordState;

    protected static string $resource = QuotationResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    #[Locked]
    public ?string $expectedSourceRevision = null;

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        try {
            $this->saveWithCrossCompanyHandling($shouldRedirect, $shouldSendSavedNotification);
        } catch (ValidationException $exception) {
            $this->rethrowProductFormErrors($exception);
        }
    }

    public function saveFormComponentOnly(Component $component): void
    {
        try {
            parent::saveFormComponentOnly($component);
        } catch (ValidationException $exception) {
            $this->rethrowProductFormErrors($exception);
        }
    }

    private function rethrowProductFormErrors(ValidationException $exception): never
    {
        $errors = $exception->validator->errors();

        foreach ($errors->get('products') as $message) {
            $errors->add('data.products', $message);
        }

        $errors->forget('products');

        throw $exception;
    }

    protected function afterFill(): void
    {
        $this->expectedSourceRevision = app(OrderSourceRevision::class)->token($this->getRecord());
    }

    protected function beforeValidate(): void
    {
        $revisions = app(OrderSourceRevision::class);
        $stored = $revisions->lock($this->getRecord());
        if ($stored->state === OrderState::SALE) {
            $revisions->assertCurrent($stored, $this->expectedSourceRevision, 'data.products');
        }
    }

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title(__('sales::filament/clusters/orders/resources/quotation/pages/edit-quotation.notification.title'))
            ->body(__('sales::filament/clusters/orders/resources/quotation/pages/edit-quotation.notification.body'));
    }

    protected function getHeaderActions(): array
    {
        return [
            ChatterAction::make()
                ->activityPlans($this->getRecord()->activityPlans())
                ->resource($this->getResource()),
            BaseActions\BackToQuotationAction::make(),
            BaseActions\CancelQuotationAction::make(),
            BaseActions\ConfirmAction::make(),
            BaseActions\CreateInvoiceAction::make(),
            BaseActions\PreviewAction::make(),
            BaseActions\SendByEmailAction::make(),
            BaseActions\LockAndUnlockAction::make(),
            DeleteAction::make()
                ->hidden(fn () => $this->getRecord()->state == OrderState::SALE)
                ->successNotification(
                    Notification::make()
                        ->success()
                        ->title(__('sales::filament/clusters/orders/resources/quotation/pages/edit-quotation.header-actions.notification.delete.title'))
                        ->body(__('sales::filament/clusters/orders/resources/quotation/pages/edit-quotation.header-actions.notification.delete.body')),
                ),
        ];
    }

    protected function afterSave(): void
    {
        try {
            SaleOrder::computeSaleOrder($this->getRecord());

            $this->refreshRecordState();
        } catch (\Exception $e) {
            Notification::make()
                ->danger()
                ->body($e->getMessage())
                ->send();

            $this->halt(shouldRollbackDatabaseTransaction: true);
        }
    }

    public function refreshFormData(array $statePaths): void
    {
        parent::refreshFormData($statePaths);

        $this->rememberData();
    }
}

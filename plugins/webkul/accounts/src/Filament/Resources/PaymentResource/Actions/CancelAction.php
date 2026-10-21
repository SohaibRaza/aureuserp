<?php

namespace Webkul\Account\Filament\Resources\PaymentResource\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Webkul\Account\Enums\PaymentStatus;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Models\Payment;

class CancelAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'customers.payment.cancel';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('accounts::filament/resources/payment/actions/cancel-action.title'))
            ->color('gray')
            ->authorize(fn (Payment $record, Component $livewire): bool => $livewire::getResource()::canEdit($record))
            ->requiresConfirmation()
            ->action(function (Payment $record, Component $livewire): void {
                $livewire::getResource()::authorizeEdit($record);
                try {
                    AccountFacade::cancelPayment($record);
                    $record->refresh();
                    $livewire->refreshFormData(['state', 'is_matched', 'is_reconciled']);
                } catch (ValidationException $exception) {
                    Notification::make()->warning()->body(collect($exception->errors())->flatten()->first())->send();
                    $this->halt();
                }
            })
            ->visible(fn (Payment $record) => $record->state === PaymentStatus::DRAFT);
    }
}

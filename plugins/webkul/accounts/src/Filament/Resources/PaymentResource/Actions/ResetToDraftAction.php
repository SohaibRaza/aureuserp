<?php

namespace Webkul\Account\Filament\Resources\PaymentResource\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Webkul\Account\Enums\PaymentStatus;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Models\Payment;

class ResetToDraftAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'customers.payment.reset-to-draft';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('accounts::filament/resources/payment/actions/reset-to-draft.title'))
            ->color('gray')
            ->authorize(fn (Payment $record, Component $livewire): bool => $livewire::getResource()::canEdit($record))
            ->action(function (Payment $record, Component $livewire): void {
                $livewire::getResource()::authorizeEdit($record);
                try {
                    AccountFacade::resetToDraftPayment($record);
                    $record->refresh();
                    $livewire->refreshFormData(['state', 'is_matched', 'is_reconciled']);
                } catch (ValidationException $exception) {
                    Notification::make()->warning()->body(collect($exception->errors())->flatten()->first())->send();
                    $this->halt();
                }
            })
            ->hidden(fn (Payment $record) => $record->state == PaymentStatus::DRAFT);
    }
}

<?php

namespace Webkul\Account\Filament\Resources\InvoiceResource\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Models\Move;

class CancelAction extends Action
{
    protected bool|Closure $hasDatabaseTransactions = true;

    public static function getDefaultName(): ?string
    {
        return 'customers.invoice.cancel';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('accounts::filament/resources/invoice/actions/cancel-action.title'))
            ->color('gray')
            ->authorize(fn (Move $record, Component $livewire): bool => $livewire::getResource()::canEdit($record))
            ->action(function (Move $record, Component $livewire): void {
                $livewire::getResource()::authorizeEdit($record);
                try {
                    $record = AccountFacade::cancelMove($record);

                    $livewire->refreshFormData(['state', 'parent_state']);

                    $livewire->dispatch('refreshInvoiceSummary');
                } catch (ValidationException $e) {
                    Notification::make()
                        ->warning()
                        ->body(collect($e->errors())->flatten()->first())
                        ->send();

                    $this->halt(shouldRollBackDatabaseTransaction: true);
                }
            })
            ->hidden(fn (Move $record) => $record->state != MoveState::DRAFT);
    }
}

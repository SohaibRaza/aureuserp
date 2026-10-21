<?php

namespace Webkul\Account\Livewire;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Filament\Resources\BillResource;
use Webkul\Account\Filament\Resources\CreditNoteResource;
use Webkul\Account\Filament\Resources\InvoiceResource;
use Webkul\Account\Filament\Resources\PaymentResource;
use Webkul\Account\Filament\Resources\RefundResource;
use Webkul\Account\Models\Bill;
use Webkul\Account\Models\CreditNote;
use Webkul\Account\Models\Invoice;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Models\PartialReconcile;
use Webkul\Account\Models\Refund;

class InvoiceSummary extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public $record = null;

    public $subtotal = 0;

    public $totalDiscount = 0;

    public $totalTax = 0;

    public $grandTotal = 0;

    public $amountTax = 0;

    public $rounding = 0;

    public $currency = null;

    public $reconcilablePayments = null;

    public $reconciledPayments = null;

    protected $listeners = [
        'itemUpdated'           => 'refreshSummary',
        'refreshInvoiceSummary' => 'refreshFromRecord',
    ];

    public function refreshSummary($totals)
    {
        $this->subtotal = $totals['subtotal'];
        $this->totalTax = $totals['totalTax'];
        $this->grandTotal = $totals['grandTotal'];
        $this->amountTax = $totals['totalTax'];
        $this->rounding = $totals['rounding'];
    }

    public function refreshFromRecord()
    {
        $this->record?->refresh();
    }

    public function reconcileAction(): Action
    {
        return Action::make('reconcile')
            ->label(__('accounts::filament/resources/invoice.summary.actions.reconcile.label'))
            ->modalHeading(__('accounts::filament/resources/invoice.summary.actions.reconcile.label'))
            ->modalSubmitActionLabel(__('accounts::filament/resources/invoice.summary.actions.reconcile.label'))
            ->icon('heroicon-o-check-circle')
            ->size('xs')
            ->authorize(fn (): bool => Gate::allows('update', $this->policyRecord($this->record)))
            ->requiresConfirmation()
            ->action(function (array $arguments, Action $action): void {
                $record = $this->freshRecord();
                Gate::authorize('update', $record);

                $title = __('accounts::filament/resources/invoice.summary.notifications.match-failed');
                $line = MoveLine::query()->where('company_id', $record->company_id)->find($arguments['lineId'] ?? null);
                $eligibleIds = collect($record->getReconcilablePayments()['lines'])->pluck('id');

                if ($record->state !== MoveState::POSTED || ! $line || ! $eligibleIds->contains($line->id)) {
                    $this->haltWithWarning($action, $title, __('accounts::filament/resources/invoice.summary.notifications.payment-unavailable'));
                }

                $lines = $record->lines->filter(fn (MoveLine $invoiceLine): bool => $invoiceLine->account_id === $line->account_id && ! $invoiceLine->reconciled);

                try {
                    AccountFacade::reconcile($lines->push($line));
                } catch (ValidationException $exception) {
                    $this->haltWithWarning($action, $title, collect($exception->errors())->flatten()->first());
                }

                $this->js('window.location.reload()');
            });
    }

    public function unReconcileAction(): Action
    {
        return Action::make('unReconcile')
            ->label(__('accounts::filament/resources/invoice.summary.actions.unreconcile.label'))
            ->modalHeading(__('accounts::filament/resources/invoice.summary.actions.unreconcile.label'))
            ->modalSubmitActionLabel(__('accounts::filament/resources/invoice.summary.actions.unreconcile.label'))
            ->icon('heroicon-o-x-circle')
            ->size('xs')
            ->authorize(fn (): bool => Gate::allows('update', $this->policyRecord($this->record)))
            ->requiresConfirmation()
            ->action(function (array $arguments, Action $action): void {
                $record = $this->freshRecord();
                Gate::authorize('update', $record);

                $title = __('accounts::filament/resources/invoice.summary.notifications.remove-failed');
                $partialReconcile = PartialReconcile::query()
                    ->where('company_id', $record->company_id)
                    ->where(function ($query) use ($record): void {
                        $query->whereHas('debitMove', fn ($lines) => $lines->where('move_id', $record->id))
                            ->orWhereHas('creditMove', fn ($lines) => $lines->where('move_id', $record->id));
                    })
                    ->find($arguments['partial_id'] ?? null);

                if ($record->state !== MoveState::POSTED || ! $partialReconcile) {
                    $this->haltWithWarning($action, $title, __('accounts::filament/resources/invoice.summary.notifications.match-unavailable'));
                }

                try {
                    AccountFacade::unReconcile($partialReconcile);
                } catch (ValidationException $exception) {
                    $this->haltWithWarning($action, $title, collect($exception->errors())->flatten()->first());
                }

                $this->js('window.location.reload()');
            });
    }

    private function freshRecord(): Move
    {
        $record = Move::query()->findOrFail($this->record?->getKey());
        $model = $this->policyRecord($record)::class;

        return $model::query()->findOrFail($record->id);
    }

    protected function policyRecord(Move $record): Move
    {
        $model = match ($record->move_type) {
            MoveType::OUT_INVOICE => Invoice::class,
            MoveType::IN_INVOICE  => Bill::class,
            MoveType::OUT_REFUND  => CreditNote::class,
            MoveType::IN_REFUND   => Refund::class,
            default               => throw new AuthorizationException,
        };

        return (new $model)->newFromBuilder($record->getAttributes());
    }

    private function haltWithWarning(Action $action, string $title, string $body): never
    {
        Notification::make()->warning()->title($title)->body($body)->send();
        $action->halt();
    }

    public function getResourceUrl($record): ?string
    {
        return match ($record['move_type']) {
            MoveType::OUT_INVOICE => InvoiceResource::getUrl('view', ['record' => $record['move_id']]),
            MoveType::IN_INVOICE  => BillResource::getUrl('view', ['record' => $record['move_id']]),
            MoveType::OUT_REFUND  => CreditNoteResource::getUrl('view', ['record' => $record['move_id']]),
            MoveType::IN_REFUND   => RefundResource::getUrl('view', ['record' => $record['move_id']]),
            MoveType::ENTRY       => PaymentResource::getUrl('view', ['record' => $record['account_payment_id']]),
        };
    }

    public function render()
    {
        $this->reconcilablePayments = $this->record?->getReconcilablePayments();

        $this->reconciledPayments = $this->record?->getReconciledPayments();

        return view('accounts::livewire/invoice-summary');
    }
}

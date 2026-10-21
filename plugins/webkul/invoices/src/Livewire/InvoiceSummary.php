<?php

namespace Webkul\Invoice\Livewire;

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Livewire\InvoiceSummary as BaseInvoiceSummary;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\Payment;
use Webkul\Invoice\Filament\Clusters\Customers\Resources\CreditNoteResource;
use Webkul\Invoice\Filament\Clusters\Customers\Resources\InvoiceResource;
use Webkul\Invoice\Filament\Clusters\Customers\Resources\PaymentResource as CustomerPaymentResource;
use Webkul\Invoice\Filament\Clusters\Vendors\Resources\BillResource;
use Webkul\Invoice\Filament\Clusters\Vendors\Resources\PaymentResource as VendorPaymentResource;
use Webkul\Invoice\Filament\Clusters\Vendors\Resources\RefundResource;
use Webkul\Invoice\Models\Bill;
use Webkul\Invoice\Models\CreditNote;
use Webkul\Invoice\Models\Invoice;
use Webkul\Invoice\Models\Refund;

class InvoiceSummary extends BaseInvoiceSummary
{
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

    public function getResourceUrl($record): ?string
    {
        $payment = Payment::find($record['account_payment_id']);

        return match ($record['move_type']) {
            MoveType::OUT_INVOICE => InvoiceResource::getUrl('view', ['record' => $record['move_id']]),
            MoveType::IN_INVOICE  => BillResource::getUrl('view', ['record' => $record['move_id']]),
            MoveType::OUT_REFUND  => CreditNoteResource::getUrl('view', ['record' => $record['move_id']]),
            MoveType::IN_REFUND   => RefundResource::getUrl('view', ['record' => $record['move_id']]),
            MoveType::ENTRY       => match ($payment?->partner_type) {
                'customer', 'company' => CustomerPaymentResource::getUrl('view', ['record' => $record['account_payment_id']]),
                'supplier'            => VendorPaymentResource::getUrl('view', ['record' => $record['account_payment_id']]),
                default               => null,
            },
        };
    }
}

<?php

namespace Webkul\Sale\Services;

use Illuminate\Support\Facades\DB;
use Webkul\Sale\Enums\InvoiceStatus;
use Webkul\Sale\Enums\OrderState;
use Webkul\Sale\Events\OrderCanceled;
use Webkul\Sale\Events\OrderConfirmed;
use Webkul\Sale\Events\OrderDrafted;
use Webkul\Sale\Events\OrderLocked;
use Webkul\Sale\Events\OrderUnlocked;
use Webkul\Sale\Exceptions\OrderConfirmationException;
use Webkul\Sale\Models\Order;
use Webkul\Sale\Settings\QuotationAndOrderSettings;

class OrderWorkflow
{
    public function __construct(protected QuotationAndOrderSettings $settings) {}

    public function sendByEmail(Order $record, array $data = []): array
    {
        $result = app(OrderMailer::class)->sendQuotation($record, $data);

        if (! empty($result['sent'])) {
            app(OrderCalculator::class)->recompute($record);
        }

        return $result;
    }

    public function toggleLock(Order $record): Order
    {
        $record->update(['locked' => ! $record->locked]);

        $record = app(OrderCalculator::class)->recompute($record);

        $record->locked
            ? OrderLocked::dispatch($record)
            : OrderUnlocked::dispatch($record);

        return $record;
    }

    public function confirm(Order $record): Order
    {
        return DB::transaction(function () use ($record): Order {
            $record = Order::query()
                ->lockForUpdate()
                ->findOrFail($record->getKey());

            if ($record->state === OrderState::SALE) {
                return $record;
            }

            if (! in_array($record->state, [OrderState::DRAFT, OrderState::SENT], true)) {
                throw new OrderConfirmationException('Only draft or sent orders can be confirmed.');
            }

            $record->update([
                'state'          => OrderState::SALE,
                'invoice_status' => InvoiceStatus::TO_INVOICE,
            ]);

            app(ProcurementRequester::class)->requestForLines($record->lines);

            $record->update(['locked' => $this->settings->enable_lock_confirm_sales]);

            $record = app(OrderCalculator::class)->recompute($record);

            OrderConfirmed::dispatch($record);

            return $record;
        });
    }

    public function backToQuotation(Order $record): Order
    {
        $record->update([
            'state'          => OrderState::DRAFT,
            'invoice_status' => InvoiceStatus::NO,
        ]);

        $record = app(OrderCalculator::class)->recompute($record);

        OrderDrafted::dispatch($record);

        return $record;
    }

    public function cancel(Order $record, array $data = []): Order
    {
        $record->update([
            'state'          => OrderState::CANCEL,
            'invoice_status' => InvoiceStatus::NO,
        ]);

        if ($data !== []) {
            app(OrderMailer::class)->sendCancellation($record, $data);
        }

        $record = app(OrderCalculator::class)->recompute($record);

        app(ProcurementRequester::class)->cancelOperations($record);

        OrderCanceled::dispatch($record);

        return $record;
    }
}

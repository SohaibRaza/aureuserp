<?php

use Illuminate\Support\Facades\Event;
use Webkul\Inventory\Enums\DeliveryStep;
use Webkul\Inventory\Enums\ReceptionStep;
use Webkul\Sale\Enums\OrderState;
use Webkul\Sale\Events\OrderConfirmed;

require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../../../inventories/tests/Helpers/InventoryHelper.php';
require_once __DIR__.'/../../Helpers/SaleHelper.php';

final class OrderConfirmationListenerFailure extends RuntimeException {}

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('inventories');
    TestBootstrapHelper::ensurePluginInstalled('sales');

    SaleHelper::actingAsAdmin();
});

it('rolls back confirmation when a late synchronous effect fails', function (): void {
    $warehouse = InventoryHelper::warehouse(ReceptionStep::ONE_STEP, DeliveryStep::ONE_STEP);
    $product = SaleHelper::product();
    $order = SaleHelper::order(['warehouse_id' => $warehouse->id]);

    SaleHelper::line($order, $product, 10, 100);

    Event::listen(OrderConfirmed::class, fn (): never => throw new OrderConfirmationListenerFailure('Injected late confirmation failure'));

    try {
        SaleHelper::confirm($order);
    } catch (OrderConfirmationListenerFailure $exception) {
        expect($exception->getMessage())->toBe('Injected late confirmation failure');
    }

    expect($order->refresh()->state)->toBe(OrderState::DRAFT)
        ->and($order->operations()->count())->toBe(0);
});

it('does not duplicate procurement when confirmation is replayed', function (): void {
    $warehouse = InventoryHelper::warehouse(ReceptionStep::ONE_STEP, DeliveryStep::ONE_STEP);
    $product = SaleHelper::product();
    $order = SaleHelper::order(['warehouse_id' => $warehouse->id]);

    SaleHelper::line($order, $product, 10, 100);

    SaleHelper::confirm($order);

    $operationIds = $order->refresh()->operations()->pluck('id')->all();

    SaleHelper::confirm($order);

    expect($order->refresh()->state)->toBe(OrderState::SALE)
        ->and($order->operations()->pluck('id')->all())->toBe($operationIds);
});

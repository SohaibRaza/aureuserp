<?php

use Webkul\Inventory\Facades\Inventory;
use Webkul\Inventory\Models\Operation;

require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../Helpers/InventoryHelper.php';

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('inventories');

    InventoryHelper::actingAsAdmin();
});

it('does not leave an orphan return when no return quantities are supplied', function (): void {
    $warehouse = InventoryHelper::warehouse();
    $product = InventoryHelper::product();

    InventoryHelper::stockUp($product, $warehouse->lotStockLocation, 10);

    $delivery = InventoryHelper::delivery($warehouse, [[$product, 10]]);

    Inventory::confirmTransfer($delivery);
    Inventory::completeTransfer($delivery->refresh());

    $operationCount = Operation::query()->count();

    expect(fn () => Inventory::createReturn($delivery->refresh()))
        ->toThrow(Throwable::class);

    expect(Operation::query()->count())->toBe($operationCount);
});

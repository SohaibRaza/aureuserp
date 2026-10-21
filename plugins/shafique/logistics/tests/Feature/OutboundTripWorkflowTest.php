<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Shafique\Logistics\Models\CompanyControl;
use Shafique\Logistics\Services\ApprovalWorkflow;
use Shafique\Logistics\Services\OutboundTripWorkflow;
use Webkul\Inventory\Enums\LocationType;
use Webkul\Inventory\Enums\OperationState;
use Webkul\Inventory\Facades\Inventory as InventoryFacade;
use Webkul\Inventory\Models\Delivery;
use Webkul\Inventory\Models\Location;
use Webkul\Inventory\Models\Operation;
use Webkul\Inventory\Models\OperationResult;
use Webkul\Inventory\Models\ProductQuantity;
use Webkul\Partner\Models\Partner;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../../../webkul/support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../../webkul/inventories/tests/Helpers/InventoryHelper.php';
require_once __DIR__.'/../../../../webkul/sales/tests/Helpers/SaleHelper.php';
require_once __DIR__.'/../Helpers/LogisticsHelper.php';

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('logistics');
});

it('creates dedicated-vehicle and multi-stop trips with their distinct stop rules', function (): void {
    $company = Company::query()->firstOrFail();
    $user = SecurityHelper::authenticateWithPermissions(['create_logistics_trip', 'manage_logistics_trip']);
    CompanyControl::query()->create(['company_id' => $company->id, 'accept_new_activity' => true, 'revision' => 1]);
    $location = Location::factory()->create(['company_id' => $company->id, 'type' => LocationType::INTERNAL]);
    $partner = Partner::factory()->create();
    $workflow = app(OutboundTripWorkflow::class);

    $dedicated = $workflow->createTrip($user, $company->id, 'trip-create-dedicated-0001', 'dedicated_vehicle', $location->id, 'Van 18');
    $workflow->addStop($user, $dedicated->id, 1, 'trip-stop-dedicated-0001', 1, $partner->id, ['name' => 'Delivery point']);

    expect(fn () => $workflow->addStop($user, $dedicated->id, 2, 'trip-stop-dedicated-0002', 2, $partner->id, ['name' => 'Second point']))
        ->toThrow(ValidationException::class);

    $multiStop = $workflow->createTrip($user, $company->id, 'trip-create-multi-0001', 'multi_stop', $location->id, 'Truck 42');
    $first = $workflow->addStop($user, $multiStop->id, 1, 'trip-stop-multi-0001', 1, $partner->id, ['name' => 'First delivery']);
    $second = $workflow->addStop($user, $multiStop->id, 2, 'trip-stop-multi-0002', 2, $partner->id, ['name' => 'Second delivery']);

    expect($first->sequence)->toBe(1)
        ->and($second->sequence)->toBe(2)
        ->and($multiStop->fresh()->mode)->toBe('multi_stop')
        ->and($multiStop->fresh()->revision)->toBe(3);
});

it('releases a verified dedicated-vehicle load through Inventory exactly once', function (string $loadedQuantity, float $countedStock): void {
    TestBootstrapHelper::ensurePluginInstalled('sales');
    TestBootstrapHelper::ensurePluginInstalled('inventories');
    SaleHelper::actingAsAdmin();

    $company = Company::query()->firstOrFail();
    $permissions = [
        'create_logistics_trip', 'manage_logistics_trip', 'allocate_logistics_demand',
        'record_logistics_load', 'verify_logistics_load', 'release_logistics_trip',
        'request_logistics_approval', 'approve_logistics_approval', 'consume_logistics_approval',
        'view_any_logistics_module::setting', 'update_inventory_delivery', 'configure_logistics_dispatch',
    ];
    CompanyControl::query()->create(['company_id' => $company->id, 'accept_new_activity' => true, 'revision' => 1]);

    $warehouse = InventoryHelper::warehouse();
    $product = SaleHelper::product();
    $order = SaleHelper::confirmedOrder($warehouse, $product, 10);
    $loader = SecurityHelper::authenticateWithPermissions($permissions);
    Auth::shouldUse('web');
    $verifier = InventoryHelper::actingAsAdmin();
    $verifier->givePermissionTo(Permission::query()->whereIn('name', $permissions)->where('guard_name', 'web')->get());
    $verifier->forceFill(['resource_permission' => PermissionType::GLOBAL])->saveQuietly();
    Auth::shouldUse('web');
    $line = $order->lines()->firstOrFail();
    $commitment = $line->currentQuantityCommitment()->firstOrFail();
    $operation = SaleHelper::customerDelivery($order);
    expect($operation)->not->toBeNull();
    $move = $operation->moves()->firstOrFail();
    $moveLine = $move->lines()->firstOrFail();
    $stopPartnerId = (int) $commitment->data['shipping_partner_id'];

    $workflow = app(OutboundTripWorkflow::class);
    $trip = $workflow->createTrip($loader, $company->id, 'trip-e2e-create-20261016-0001', 'dedicated_vehicle', (int) $move->source_location_id, 'Van 18');
    $address = $commitment->data['shipping_address'] ?? [];
    $stop = $workflow->addStop($loader, $trip->id, 1, 'trip-e2e-stop-20261016-0001', 1, $stopPartnerId, $address);
    $allocation = $workflow->allocateDemand($loader, $trip->id, 2, 'trip-e2e-allocate-20261016-0001', $stop->id, $line->id, $commitment->id, (int) $move->source_location_id, (string) $commitment->data['stock_quantity']);
    $load = $workflow->recordLoad($loader, $trip->id, 3, 'trip-e2e-load-20261016-0001', [[
        'allocation_id' => $allocation->id,
        'move_id'       => $move->id,
        'move_line_id'  => $moveLine->id,
        'stock_quantity'=> $loadedQuantity,
    ]]);

    expect(Gate::forUser($verifier)->allows('update', Delivery::query()->findOrFail($operation->id)))->toBeTrue();
    expect(fn () => InventoryFacade::completeTransfer($operation->refresh()))
        ->toThrow(ValidationException::class);
    expect($operation->fresh()->state)->not->toBe(OperationState::DONE);

    $approvals = app(ApprovalWorkflow::class);
    $verifyApproval = $approvals->approve($loader, $verifier, $company->id, 'trip-e2e-approve-verify-20261016-0001', 'load.verify', 'load_revision', $load->id, $load->revision, ['quantity' => $loadedQuantity], Carbon::now()->addHour(), 'Verify the dedicated-vehicle load');
    expect(fn () => $workflow->verifyLoad($verifier, $trip->id, 4, 'trip-no-policy-20261016-0001', $verifyApproval->id))->toThrow(ValidationException::class);
    LogisticsHelper::recordDispatchChecks($loader, $trip, 'trip-e2e-safety-20261016-0001');
    $trip = $workflow->verifyLoad($verifier, $trip->id, (int) $trip->fresh()->revision, 'trip-e2e-verify-20261016-0001', $verifyApproval->id);
    $releaseApproval = $approvals->approve($loader, $verifier, $company->id, 'trip-e2e-approve-release-20261016-0001', 'release.stock', 'trip', $trip->id, $trip->revision, ['quantity' => $loadedQuantity], Carbon::now()->addHour(), 'Release the verified dedicated-vehicle load');

    $stock = ProductQuantity::query()->where('product_id', $product->id)->where('location_id', $move->source_location_id)->sole();
    InventoryHelper::applyInventoryAdjustment($stock, $countedStock);
    expect((float) $stock->fresh()->reserved_quantity)->toBe(10.0);
    expect(fn () => $workflow->release($verifier, $trip->id, $trip->revision, 'trip-short-stock-20261021-0001', $releaseApproval->id))
        ->toThrow(ValidationException::class);
    expect($trip->fresh()->state)->toBe('verified')
        ->and($trip->releaseBatches()->count())->toBe(0)
        ->and(OperationResult::query()->where('operation_id', $operation->id)->exists())->toBeFalse()
        ->and((float) $stock->fresh()->quantity)->toBe($countedStock)
        ->and((float) $stock->fresh()->reserved_quantity)->toBe(10.0)
        ->and($releaseApproval->events()->where('event_type', 'consumed')->exists())->toBeFalse();
    InventoryHelper::applyInventoryAdjustment($stock->fresh(), 10);

    $released = $workflow->release($verifier, $trip->id, $trip->revision, 'trip-e2e-release-20261016-0001', $releaseApproval->id);
    $replayed = $workflow->release($verifier, $trip->id, $trip->revision, 'trip-e2e-release-20261016-0001', $releaseApproval->id);
    if ($loadedQuantity === '10.0000') {
        expect($operation->fresh()->state)->toBe(OperationState::DONE)
            ->and($trip->fresh()->state)->toBe('released')
            ->and($released->id)->toBe($replayed->id)
            ->and(InventoryHelper::onHand($product, $move->sourceLocation))->toBe(0.0)
            ->and($releaseApproval->events()->where('event_type', 'consumed')->count())->toBe(1);

        return;
    }
    $backorder = Operation::query()->where('back_order_id', $operation->id)->firstOrFail();
    $backorderMove = $backorder->moves()->firstOrFail();
    $nextTrip = $workflow->createTrip($loader, $company->id, 'trip-e2e-remainder-trip-20261016-0001', 'dedicated_vehicle', (int) $backorderMove->source_location_id, 'Van 18');
    $nextStop = $workflow->addStop($loader, $nextTrip->id, 1, 'trip-e2e-remainder-stop-20261016-0001', 1, $stopPartnerId, $address);
    $remainderAllocation = $workflow->allocateDemand($loader, $nextTrip->id, 2, 'trip-e2e-remainder-allocation-20261016-0001', $nextStop->id, $line->id, $commitment->id, (int) $backorderMove->source_location_id, '4.0000');

    expect($operation->fresh()->state)->toBe(OperationState::DONE)
        ->and(OperationResult::query()->where('operation_id', $operation->id)->exists())->toBeTrue()
        ->and($trip->fresh()->state)->toBe('released')
        ->and($allocation->fresh()->state)->toBe('partially_released')
        ->and((float) $allocation->fresh()->released_quantity)->toBe(6.0)
        ->and($backorder->back_order_id)->toBe($operation->id)
        ->and($remainderAllocation->stock_quantity)->toBe('4.0000')
        ->and($released->id)->toBe($replayed->id);

    $workflow->releaseAllocation($loader, $nextTrip->id, (int) $nextTrip->fresh()->revision, 'trip-release-unused-remainder-20261021', $remainderAllocation->id, 'Replan after the Sales quantity changes.');
    $line->refresh()->update(['product_qty' => 12]);
    $currentSnapshot = $line->currentQuantityCommitment()->firstOrFail();
    expect($currentSnapshot->id)->not->toBe($commitment->id);
    expect(fn () => $workflow->allocateDemand($loader, $nextTrip->id, (int) $nextTrip->fresh()->revision, 'trip-excess-after-revision-20261021', $nextStop->id, $line->id, $currentSnapshot->id, (int) $move->source_location_id, '7.0000'))->toThrow(ValidationException::class);
    $newRemainder = $workflow->allocateDemand($loader, $nextTrip->id, (int) $nextTrip->fresh()->revision, 'trip-remaining-after-revision-20261021', $nextStop->id, $line->id, $currentSnapshot->id, (int) $move->source_location_id, '6.0000');
    expect($newRemainder->stock_quantity)->toBe('6.0000');

})->with([
    'partial load'                 => ['6.0000', 4.0],
    'full reservation'             => ['10.0000', 4.0],
    'shortage below unit rounding' => ['10.0000', 9.9999],
]);

it('releases one multi-stop trip against separate customer orders and stops', function (): void {
    TestBootstrapHelper::ensurePluginInstalled('sales');
    TestBootstrapHelper::ensurePluginInstalled('inventories');
    SaleHelper::actingAsAdmin();

    $company = Company::query()->firstOrFail();
    $permissions = [
        'create_logistics_trip', 'manage_logistics_trip', 'allocate_logistics_demand',
        'record_logistics_load', 'verify_logistics_load', 'release_logistics_trip',
        'request_logistics_approval', 'approve_logistics_approval', 'consume_logistics_approval',
        'view_any_logistics_module::setting', 'update_inventory_delivery', 'configure_logistics_dispatch',
    ];
    CompanyControl::query()->create(['company_id' => $company->id, 'accept_new_activity' => true, 'revision' => 1]);
    $warehouse = InventoryHelper::warehouse();
    $orders = [];

    foreach ([['North shop', 'north'], ['South shop', 'south']] as [$name, $street]) {
        $partner = Partner::factory()->create(['name' => $name, 'street1' => $street.' road']);
        $product = SaleHelper::product();
        InventoryHelper::stockUp($product, $warehouse->lotStockLocation, 5);
        $order = SaleHelper::order([
            'warehouse_id'        => $warehouse->id,
            'partner_shipping_id' => $partner->id,
        ]);
        $line = SaleHelper::line($order, $product, 5, 100);
        $order = SaleHelper::confirm($order)->load('operations.moves', 'lines');
        $operation = SaleHelper::customerDelivery($order);
        expect($operation)->not->toBeNull();
        $orders[] = [$order, $line, $partner, $operation, $line->currentQuantityCommitment()->firstOrFail()];
    }

    $loader = SecurityHelper::authenticateWithPermissions($permissions);
    Auth::shouldUse('web');
    $verifier = InventoryHelper::actingAsAdmin();
    $verifier->givePermissionTo(Permission::query()->whereIn('name', $permissions)->where('guard_name', 'web')->get());
    $verifier->forceFill(['resource_permission' => PermissionType::GLOBAL])->saveQuietly();
    Auth::shouldUse('web');

    $workflow = app(OutboundTripWorkflow::class);
    $trip = $workflow->createTrip($loader, $company->id, 'trip-multistop-create-20261016-0001', 'multi_stop', (int) $warehouse->lot_stock_location_id, 'Truck 42');
    $loadItems = [];
    foreach ($orders as $index => [$order, $line, $partner, $operation, $commitment]) {
        $stop = $workflow->addStop(
            $loader,
            $trip->id,
            (int) $trip->fresh()->revision,
            'trip-multistop-stop-20261016-000'.($index + 1),
            $index + 1,
            (int) $partner->id,
            $commitment->data['shipping_address'] ?? [],
        );
        $move = $operation->moves()->firstOrFail();
        $allocation = $workflow->allocateDemand(
            $loader,
            $trip->id,
            (int) $trip->fresh()->revision,
            'trip-multistop-allocation-20261016-000'.($index + 1),
            $stop->id,
            $line->id,
            $commitment->id,
            (int) $move->source_location_id,
            '5.0000',
        );
        foreach ($move->lines()->get() as $moveLine) {
            $loadItems[] = [
                'allocation_id'  => $allocation->id,
                'move_id'        => $move->id,
                'move_line_id'   => $moveLine->id,
                'stock_quantity' => (string) $moveLine->qty,
            ];
        }
    }

    $load = $workflow->recordLoad($loader, $trip->id, (int) $trip->fresh()->revision, 'trip-multistop-load-20261016-0001', $loadItems);
    $approvals = app(ApprovalWorkflow::class);
    LogisticsHelper::recordDispatchChecks($loader, $trip, 'trip-multistop-safety-20261016-0001');
    $verifyApproval = $approvals->approve($loader, $verifier, $company->id, 'trip-multistop-approve-verify-20261016-0001', 'load.verify', 'load_revision', $load->id, $load->revision, ['quantity' => '10.0000'], Carbon::now()->addHour(), 'Verify the two-stop load');
    $trip = $workflow->verifyLoad($verifier, $trip->id, (int) $trip->fresh()->revision, 'trip-multistop-verify-20261016-0001', $verifyApproval->id);
    $releaseApproval = $approvals->approve($loader, $verifier, $company->id, 'trip-multistop-approve-release-20261016-0001', 'release.stock', 'trip', $trip->id, $trip->revision, ['quantity' => '10.0000'], Carbon::now()->addHour(), 'Release the two-stop load');
    $release = $workflow->release($verifier, $trip->id, $trip->revision, 'trip-multistop-release-20261016-0001', $releaseApproval->id);

    expect($trip->fresh()->state)->toBe('released')
        ->and($trip->fresh()->stops()->count())->toBe(2)
        ->and($release->operation_results)->toHaveCount(2)
        ->and(collect($release->operation_results)->pluck('state')->unique()->values()->all())->toBe([OperationState::DONE->value]);
});

<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Shafique\Logistics\Filament\Resources\EvidenceResource\Pages\ListEvidence;
use Shafique\Logistics\Filament\Resources\TripResource\Pages\ListTrips;
use Shafique\Logistics\Filament\Resources\TripResource\Pages\ViewTrip;
use Shafique\Logistics\LogisticsDatabaseSeeder;
use Shafique\Logistics\Models\CompanyControl;
use Shafique\Logistics\Models\DispatchPolicy;
use Shafique\Logistics\Models\ReceiptObservation;
use Shafique\Logistics\Models\Trip;
use Shafique\Logistics\Services\DispatchReadiness;
use Shafique\Logistics\Services\OutboundTripWorkflow;
use Webkul\Inventory\Enums\LocationType;
use Webkul\Inventory\Enums\OperationState;
use Webkul\Inventory\Models\Location;
use Webkul\Partner\Models\Partner;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Filament\Resources\RoleResource;
use Webkul\Security\Models\Permission;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../../webkul/support/tests/Helpers/FilamentHelper.php';
require_once __DIR__.'/../../../../webkul/inventories/tests/Helpers/InventoryHelper.php';
require_once __DIR__.'/../../../../webkul/sales/tests/Helpers/SaleHelper.php';
require_once __DIR__.'/../Helpers/LogisticsHelper.php';

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('logistics');
    URL::resolveMissingNamedRoutesUsing(fn () => '#');
});

it('denies the trip workspace without permission', function (): void {
    FilamentHelper::actingAs([]);
    Auth::shouldUse('web');
    Livewire::test(ListTrips::class)->assertForbidden();
});

it('creates a trip through the admin action without issuing stock', function (): void {
    $user = FilamentHelper::actingAs(['view_any_logistics_trip', 'create_logistics_trip']);
    Auth::shouldUse('web');
    CompanyControl::query()->create(['company_id' => $user->default_company_id, 'accept_new_activity' => true, 'revision' => 1]);
    $location = Location::factory()->create(['company_id' => $user->default_company_id, 'type' => LocationType::INTERNAL]);

    Livewire::test(ListTrips::class)->assertOk()
        ->callAction('createTrip', data: ['request_key' => (string) Str::uuid(), 'mode' => 'dedicated_vehicle', 'source_location_id' => $location->id, 'vehicle_reference' => 'Synthetic van'])
        ->assertHasNoActionErrors();
    expect(Trip::query()->sole()->state)->toBe('planning');
});

it('keeps evidence uploads blocked until the Company approves retention', function (): void {
    FilamentHelper::actingAs(['view_any_logistics_evidence', 'create_logistics_evidence', 'manage_logistics_evidence_retention']);
    Auth::shouldUse('web');
    Livewire::test(ListEvidence::class)->assertOk()->assertActionDisabled('uploadEvidence')
        ->callAction('approveRetentionPolicy', data: ['retention_days' => 2557, 'reason' => 'Synthetic Company policy approval'])
        ->assertHasNoActionErrors()->assertActionEnabled('uploadEvidence');
});

it('runs the admin journey with an independent verifier and records an unverified receipt', function (string $mode, int $stopCount): void {
    SaleHelper::actingAsAdmin();
    $companyId = SaleHelper::company()->id;
    $warehouse = InventoryHelper::warehouse();
    $orders = [];
    for ($index = 0; $index < $stopCount; $index++) {
        $partner = Partner::factory()->create(['name' => 'Synthetic destination '.$index]);
        $product = SaleHelper::product();
        InventoryHelper::stockUp($product, $warehouse->lotStockLocation, 5);
        $order = SaleHelper::order(['warehouse_id' => $warehouse->id, 'partner_shipping_id' => $partner->id]);
        $line = SaleHelper::line($order, $product, 5, 100);
        $order = SaleHelper::confirm($order);
        $orders[] = [$order, $line, $line->currentQuantityCommitment()->firstOrFail(), SaleHelper::customerDelivery($order)];
    }
    $permissions = ['view_any_logistics_trip', 'create_logistics_trip', 'manage_logistics_trip', 'allocate_logistics_demand', 'record_logistics_load', 'verify_logistics_load', 'release_logistics_trip', 'request_logistics_approval', 'approve_logistics_approval', 'consume_logistics_approval', 'record_logistics_receipt', 'configure_logistics_dispatch', 'update_inventory_delivery'];
    $loader = FilamentHelper::actingAs($permissions);
    Auth::shouldUse('web');
    CompanyControl::query()->create(['company_id' => $companyId, 'accept_new_activity' => true, 'revision' => 1]);
    $trip = app(OutboundTripWorkflow::class)->createTrip($loader, $companyId, (string) Str::uuid(), $mode, (int) $warehouse->lot_stock_location_id, 'Synthetic truck');
    $page = Livewire::test(ViewTrip::class, ['record' => $trip->id])->assertOk();
    $loadItems = [];
    foreach ($orders as $index => [$order, $line, $snapshot, $operation]) {
        $page->callAction('addStop', data: ['request_key' => (string) Str::uuid(), 'expected_revision' => $trip->fresh()->revision, 'sequence' => $index + 1, 'commitment_id' => $snapshot->id])->assertHasNoActionErrors();
        $stop = $trip->stops()->where('sequence', $index + 1)->firstOrFail();
        $page->callAction('allocateDemand', data: ['request_key' => (string) Str::uuid(), 'expected_revision' => $trip->fresh()->revision, 'stop_id' => $stop->id, 'commitment_id' => $snapshot->id, 'source_location_id' => $warehouse->lot_stock_location_id, 'stock_quantity' => '5.0000'])->assertHasNoActionErrors();
        $allocation = $trip->allocations()->where('order_line_id', $line->id)->firstOrFail();
        $move = $operation->moves()->firstOrFail();
        foreach ($move->lines as $moveLine) {
            $loadItems[] = ['allocation_id' => $allocation->id, 'move_id' => $move->id, 'move_line_id' => $moveLine->id, 'stock_quantity' => (string) $moveLine->qty];
        }
    }
    $page->callAction('recordLoad', data: ['request_key' => (string) Str::uuid(), 'expected_revision' => $trip->fresh()->revision, 'source_location_id' => $warehouse->lot_stock_location_id, 'items' => $loadItems])->assertHasNoActionErrors();
    app(DispatchReadiness::class)->approvePolicy($loader, $companyId, (string) Str::uuid(), [
        'source_location_ids'   => [$warehouse->lot_stock_location_id],
        'product_rules'         => collect($orders)->map(fn ($entry) => ['product_id' => $entry[1]->product_id, 'quality_required' => false, 'instructions' => 'Synthetic product handling rule.'])->all(),
        'require_load_evidence' => false, 'require_receipt_evidence' => false, 'reason' => 'Synthetic test configuration only.',
    ]);
    $badChecks = LogisticsHelper::checkData();
    $badChecks['loaded_gross_weight_kg'] = '1001';
    expect(fn () => app(DispatchReadiness::class)->recordChecks($loader, $trip->id, $trip->fresh()->revision, (string) Str::uuid(), $badChecks))->toThrow(ValidationException::class);
    $page->callAction('recordChecks', data: ['request_key' => (string) Str::uuid(), 'expected_revision' => $trip->fresh()->revision, ...LogisticsHelper::checkData()])->assertHasNoActionErrors();
    $ready = app(DispatchReadiness::class);
    $ready->assertReady($trip->fresh());
    $this->travel(3)->hours();
    expect(fn () => $ready->assertReady($trip->fresh()))->toThrow(ValidationException::class);
    $this->travelBack();
    $policyData = DispatchPolicy::query()->latest('id')->firstOrFail()->only(['source_location_ids', 'product_rules', 'require_load_evidence', 'require_receipt_evidence', 'reason']);
    $qualityPolicy = $policyData;
    $qualityPolicy['product_rules'][0]['quality_required'] = true;
    $ready->approvePolicy($loader, $companyId, (string) Str::uuid(), $qualityPolicy);
    $checks = LogisticsHelper::checkData();
    $checks['quality_release_reference'] = 'A reference alone cannot prove quality release';
    $ready->recordChecks($loader, $trip->id, $trip->fresh()->revision, (string) Str::uuid(), $checks);
    expect(fn () => $ready->assertReady($trip->fresh()))->toThrow(ValidationException::class);
    $ready->approvePolicy($loader, $companyId, (string) Str::uuid(), $policyData);
    $ready->recordChecks($loader, $trip->id, $trip->fresh()->revision, (string) Str::uuid(), LogisticsHelper::checkData());
    $page = Livewire::test(ViewTrip::class, ['record' => $trip->id])->assertOk();
    $page->callAction('verifyLoad', data: ['request_key' => (string) Str::uuid(), 'expected_revision' => $trip->fresh()->revision, 'valid_until' => now()->addHour()->toDateTimeString(), 'reason' => 'Attempted self-verification'])->assertHasActionErrors();
    expect($trip->fresh()->state)->toBe('loading');

    $verifier = InventoryHelper::actingAsAdmin();
    $verifier->givePermissionTo(Permission::query()->whereIn('name', $permissions)->where('guard_name', 'web')->get());
    $verifier->forceFill(['resource_permission' => PermissionType::GLOBAL])->saveQuietly();
    Auth::shouldUse('web');
    app()->forgetInstance(CompanyContext::class);
    FilamentHelper::bootAdminPanel();
    $page = Livewire::test(ViewTrip::class, ['record' => $trip->id])->assertOk();
    $page->callAction('verifyLoad', data: ['request_key' => (string) Str::uuid(), 'expected_revision' => $trip->fresh()->revision, 'valid_until' => now()->addHour()->toDateTimeString(), 'reason' => 'Synthetic independent load verification'])->assertHasNoActionErrors();
    $page->callAction('releaseStock', data: ['request_key' => (string) Str::uuid(), 'expected_revision' => $trip->fresh()->revision, 'valid_until' => now()->addHour()->toDateTimeString(), 'reason' => 'Synthetic independent stock issue approval'])->assertHasNoActionErrors();
    foreach ($orders as [$order, $line, $snapshot, $operation]) {
        expect($operation->fresh()->state)->toBe(OperationState::DONE);
    }
    expect(fn () => app(OutboundTripWorkflow::class)->cancelTrip($verifier, $trip->id, $trip->fresh()->revision, (string) Str::uuid(), 'Cannot cancel issued goods.'))->toThrow(ValidationException::class);
    expect(fn () => app(OutboundTripWorkflow::class)->unload($verifier, $trip->id, $trip->fresh()->revision, (string) Str::uuid(), 'Cannot undo issued stock.', true))->toThrow(ValidationException::class);

    $page->callAction('recordReceipt', data: ['request_key' => (string) Str::uuid(), 'expected_revision' => $trip->fresh()->revision, 'stop_id' => $trip->stops()->first()->id, 'reporter_name' => 'Synthetic receiver', 'occurred_at' => now()->subMinute()->toDateTimeString(), 'intent' => 'incremental', 'quantity' => '5', 'unit_id' => SaleHelper::unitsUom()->id, 'notes' => 'Synthetic report; acceptance has not been verified.'])->assertHasNoActionErrors();
    expect(ReceiptObservation::query()->sole()->reporter_name)->toBe('Synthetic receiver')
        ->and($trip->fresh()->state)->toBe('released');
})->with([['dedicated_vehicle', 1], ['multi_stop', 2]]);

it('exposes Logistics controls in configurable role permissions', function (): void {
    FilamentHelper::actingAs(['view_any_logistics_trip']);
    Auth::shouldUse('web');
    expect(config('filament-shield.shield_resource.tabs.custom_permissions'))->toBeTrue();
    $permissions = RoleResource::getAllFormPermissions();
    foreach (LogisticsDatabaseSeeder::PERMISSIONS as $permission) {
        expect($permissions)->toContain($permission);
    }
});

it('issues separate origin loads without rewriting the first issue or blocking its receipt reports', function (): void {
    SaleHelper::actingAsAdmin();
    $companyId = SaleHelper::company()->id;
    $warehouses = [InventoryHelper::warehouse(), InventoryHelper::warehouse()];
    $partner = Partner::factory()->create();
    $entries = [];
    foreach ($warehouses as $warehouse) {
        $product = SaleHelper::product();
        InventoryHelper::stockUp($product, $warehouse->lotStockLocation, 5);
        $order = SaleHelper::order(['warehouse_id' => $warehouse->id, 'partner_shipping_id' => $partner->id]);
        $line = SaleHelper::line($order, $product, 5, 100);
        $order = SaleHelper::confirm($order);
        $entries[] = [$line, $line->currentQuantityCommitment()->firstOrFail(), SaleHelper::customerDelivery($order)];
    }
    $permissions = LogisticsDatabaseSeeder::PERMISSIONS;
    $loader = FilamentHelper::actingAs($permissions);
    Auth::shouldUse('web');
    CompanyControl::query()->create(['company_id' => $companyId, 'accept_new_activity' => true, 'revision' => 1]);
    $workflow = app(OutboundTripWorkflow::class);
    $trip = $workflow->createTrip($loader, $companyId, (string) Str::uuid(), 'dedicated_vehicle', $warehouses[0]->lot_stock_location_id, 'Synthetic two-origin truck');
    $stop = $workflow->addStop($loader, $trip->id, 1, (string) Str::uuid(), 1, $partner->id, $entries[0][1]->data['shipping_address']);
    $allocations = [];
    foreach ($entries as $index => [$line, $snapshot, $operation]) {
        $allocations[] = $workflow->allocateDemand($loader, $trip->id, $trip->fresh()->revision, (string) Str::uuid(), $stop->id, $line->id, $snapshot->id, $warehouses[$index]->lot_stock_location_id, '5');
    }
    $verifier = InventoryHelper::actingAsAdmin();
    $verifier->givePermissionTo(Permission::query()->whereIn('name', [...$permissions, 'update_inventory_delivery'])->where('guard_name', 'web')->get());
    $verifier->forceFill(['resource_permission' => PermissionType::GLOBAL])->saveQuietly();
    Auth::shouldUse('web');
    foreach ($entries as $index => [$line, $snapshot, $operation]) {
        $move = $operation->moves()->with('lines')->firstOrFail();
        $workflow->recordLoad($loader, $trip->id, $trip->fresh()->revision, (string) Str::uuid(), [[
            'allocation_id' => $allocations[$index]->id, 'move_id' => $move->id,
            'move_line_id'  => $move->lines->first()->id, 'stock_quantity' => '5',
        ]], $warehouses[$index]->lot_stock_location_id);
        if ($index === 1) {
            $workflow->recordReceiptObservation($verifier, $trip->id, $stop->id, (string) Str::uuid(), 'incremental', '5', SaleHelper::unitsUom()->id, 'Report for the already-issued first load while second-origin loading is open.', null, 'Synthetic receiver', now()->subMinute()->toIso8601String());
        }
        LogisticsHelper::recordDispatchChecks($loader, $trip, 'two-origin-'.$index);
        app()->forgetInstance(CompanyContext::class);
        FilamentHelper::bootAdminPanel();
        $page = Livewire::test(ViewTrip::class, ['record' => $trip->id])->assertOk();
        $page->callAction('verifyLoad', data: ['request_key' => (string) Str::uuid(), 'expected_revision' => $trip->fresh()->revision, 'valid_until' => now()->addHour()->toDateTimeString(), 'reason' => 'Synthetic independent verification'])->assertHasNoActionErrors();
        $page->callAction('releaseStock', data: ['request_key' => (string) Str::uuid(), 'expected_revision' => $trip->fresh()->revision, 'valid_until' => now()->addHour()->toDateTimeString(), 'reason' => 'Synthetic independent stock issue'])->assertHasNoActionErrors();
        expect($operation->fresh()->state)->toBe(OperationState::DONE);
    }
    expect($trip->releaseBatches()->count())->toBe(2)
        ->and($trip->allocations()->sum('released_quantity'))->toBe('10.0000')
        ->and($trip->receipts()->count())->toBe(1);
});

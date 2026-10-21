<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Shafique\Logistics\Filament\Resources\TripResource\Pages\ViewTrip;
use Shafique\Logistics\LogisticsDatabaseSeeder;
use Shafique\Logistics\Models\CompanyControl;
use Shafique\Logistics\Models\LoadItem;
use Shafique\Logistics\Services\OutboundTripWorkflow;
use Webkul\Inventory\Enums\OperationState;
use Webkul\Inventory\Facades\Inventory as InventoryFacade;

require_once __DIR__.'/../../../../webkul/support/tests/Helpers/FilamentHelper.php';
require_once __DIR__.'/../../../../webkul/inventories/tests/Helpers/InventoryHelper.php';
require_once __DIR__.'/../../../../webkul/sales/tests/Helpers/SaleHelper.php';

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('logistics');
});

function tripRecoveryFixture(): array
{
    SaleHelper::actingAsAdmin();
    $warehouse = InventoryHelper::warehouse();
    $product = SaleHelper::product();
    $order = SaleHelper::confirmedOrder($warehouse, $product, 10);
    $line = $order->lines()->firstOrFail();
    $snapshot = $line->currentQuantityCommitment()->firstOrFail();
    $operation = SaleHelper::customerDelivery($order);
    $move = $operation->moves()->with('lines')->firstOrFail();
    $actor = FilamentHelper::actingAs(LogisticsDatabaseSeeder::PERMISSIONS);
    Auth::shouldUse('web');
    CompanyControl::query()->create(['company_id' => $order->company_id, 'accept_new_activity' => true, 'revision' => 1]);
    $workflow = app(OutboundTripWorkflow::class);
    $trip = $workflow->createTrip($actor, $order->company_id, (string) Str::uuid(), 'dedicated_vehicle', $warehouse->lot_stock_location_id, 'Synthetic recovery vehicle');
    $stop = $workflow->addStop($actor, $trip->id, 1, (string) Str::uuid(), 1, $snapshot->data['shipping_partner_id'], $snapshot->data['shipping_address']);
    $allocation = $workflow->allocateDemand($actor, $trip->id, 2, (string) Str::uuid(), $stop->id, $line->id, $snapshot->id, $warehouse->lot_stock_location_id, '10');
    $items = [['allocation_id' => $allocation->id, 'move_id' => $move->id, 'move_line_id' => $move->lines->first()->id, 'stock_quantity' => '10']];

    return compact('actor', 'trip', 'allocation', 'items', 'workflow', 'operation', 'order', 'snapshot', 'stop', 'line', 'warehouse');
}

it('releases an unused planning claim without cancelling its Sales delivery and replays safely during suspension', function (): void {
    extract(tripRecoveryFixture());
    CompanyControl::query()->where('company_id', $trip->company_id)->update(['accept_new_activity' => false]);
    $key = (string) Str::uuid();
    $workflow->releaseAllocation($actor, $trip->id, 3, $key, $allocation->id, 'Vehicle changed; keep the customer order open.');
    $workflow->releaseAllocation($actor, $trip->id, 3, $key, $allocation->id, 'Vehicle changed; keep the customer order open.');
    expect($allocation->fresh()->state)->toBe('claim_released')
        ->and($trip->fresh()->revision)->toBe(4)
        ->and($trip->recoveryEvents()->count())->toBe(1)
        ->and($operation->fresh()->state)->not->toBe(OperationState::CANCELED);
    expect(fn () => $workflow->releaseAllocation($actor, $trip->id, 3, $key, $allocation->id, 'Different intent'))->toThrow(ValidationException::class);
    foreach ($actor->permissions()->where('name', 'allocate_logistics_demand')->get() as $permission) {
        $actor->revokePermissionTo($permission);
    }
    expect(fn () => $workflow->releaseAllocation($actor, $trip->id, 3, $key, $allocation->id, 'Vehicle changed; keep the customer order open.'))->toThrow(AuthorizationException::class);
});

it('requires recorded unloading before cancelling a loaded trip and preserves immutable load details', function (): void {
    extract(tripRecoveryFixture());
    $load = $workflow->recordLoad($actor, $trip->id, 3, (string) Str::uuid(), $items);
    expect(fn () => $workflow->cancelTrip($actor, $trip->id, 4, (string) Str::uuid(), 'Vehicle unavailable.'))->toThrow(ValidationException::class);
    expect(fn () => $workflow->releaseAllocation($actor, $trip->id, 4, (string) Str::uuid(), $allocation->id, 'Cannot abandon counted goods.'))->toThrow(ValidationException::class);
    $workflow->unload($actor, $trip->id, 4, (string) Str::uuid(), 'All counted goods were physically unloaded.', true);
    $workflow->cancelTrip($actor, $trip->id, 5, (string) Str::uuid(), 'Vehicle unavailable.');
    expect($trip->fresh()->state)->toBe('cancelled')->and($trip->fresh()->load_revision_id)->toBeNull()
        ->and($allocation->fresh()->state)->toBe('claim_released')
        ->and($load->items()->sole()->stock_quantity)->toBe('10.0000')
        ->and($load->items()->sole()->native_line_snapshot['product_id'])->toBe($line->product_id)
        ->and($trip->recoveryEvents()->count())->toBe(2);
    $nativeActor = InventoryHelper::actingAsAdmin();
    InventoryFacade::cancelTransfer($operation->fresh());
    expect($operation->fresh()->state)->toBe(OperationState::CANCELED)
        ->and(LoadItem::query()->where('load_revision_id', $load->id)->sole()->native_line_snapshot['reserved_quantity'])->toBe('10.0000');
});

it('rejects stale recovery and unloading without a physical confirmation', function (): void {
    extract(tripRecoveryFixture());
    $workflow->recordLoad($actor, $trip->id, 3, (string) Str::uuid(), $items);
    expect(fn () => $workflow->unload($actor, $trip->id, 3, (string) Str::uuid(), 'Stale request.', true))->toThrow(ValidationException::class);
    expect(fn () => $workflow->unload($actor, $trip->id, 4, (string) Str::uuid(), 'No confirmation.', false))->toThrow(ValidationException::class);
    expect($trip->fresh()->state)->toBe('loading')->and($allocation->fresh()->loaded_quantity)->toBe('10.0000');
});

it('allows replanning after unloading but continues protecting the new current load', function (): void {
    extract(tripRecoveryFixture());
    $workflow->recordLoad($actor, $trip->id, 3, (string) Str::uuid(), $items);
    $workflow->unload($actor, $trip->id, 4, (string) Str::uuid(), 'Goods unloaded for a replacement trip.', true);
    $workflow->releaseAllocation($actor, $trip->id, 5, (string) Str::uuid(), $allocation->id, 'Move this order to the replacement vehicle.');
    $replacement = $workflow->createTrip($actor, $trip->company_id, (string) Str::uuid(), 'dedicated_vehicle', $warehouse->lot_stock_location_id, 'Replacement vehicle');
    $replacementStop = $workflow->addStop($actor, $replacement->id, 1, (string) Str::uuid(), 1, $snapshot->data['shipping_partner_id'], $snapshot->data['shipping_address']);
    $newAllocation = $workflow->allocateDemand($actor, $replacement->id, 2, (string) Str::uuid(), $replacementStop->id, $line->id, $snapshot->id, $warehouse->lot_stock_location_id, '10');
    $items[0]['allocation_id'] = $newAllocation->id;
    $workflow->recordLoad($actor, $replacement->id, 3, (string) Str::uuid(), $items);
    InventoryHelper::actingAsAdmin();
    expect(fn () => InventoryFacade::cancelTransfer($operation->fresh()))->toThrow(ValidationException::class);
});

it('runs unloading and cancellation through the admin actions during suspension', function (): void {
    extract(tripRecoveryFixture());
    URL::resolveMissingNamedRoutesUsing(fn () => '#');
    $workflow->recordLoad($actor, $trip->id, 3, (string) Str::uuid(), $items);
    CompanyControl::query()->where('company_id', $trip->company_id)->update(['accept_new_activity' => false]);
    $page = Livewire::test(ViewTrip::class, ['record' => $trip->id])->assertOk();
    $page->callAction('unload', data: ['request_key' => (string) Str::uuid(), 'expected_revision' => 4, 'physically_unloaded' => true, 'reason' => 'Goods unloaded after a vehicle breakdown.'])->assertHasNoActionErrors();
    $page->callAction('cancelTrip', data: ['request_key' => (string) Str::uuid(), 'expected_revision' => 5, 'reason' => 'Vehicle unavailable; keep customer demand open.'])->assertHasNoActionErrors();
    expect($trip->fresh()->state)->toBe('cancelled')->and($trip->recoveryEvents()->count())->toBe(2);
});

it('does not release another trip allocation or accept an empty recovery reason', function (): void {
    extract(tripRecoveryFixture());
    $otherTrip = $workflow->createTrip($actor, $trip->company_id, (string) Str::uuid(), 'dedicated_vehicle', $warehouse->lot_stock_location_id, 'Other trip');
    $otherAllocation = $allocation->replicate();
    $otherAllocation->trip_id = $otherTrip->id;
    $otherAllocation->save();
    expect(fn () => $workflow->releaseAllocation($actor, $trip->id, 3, (string) Str::uuid(), $otherAllocation->id, 'Wrong trip allocation.'))->toThrow(ModelNotFoundException::class);
    expect(fn () => $workflow->cancelTrip($actor, $trip->id, 3, (string) Str::uuid(), '   '))->toThrow(ValidationException::class);
    expect($allocation->fresh()->state)->toBe('active')->and($trip->fresh()->state)->toBe('planning');
});

it('refuses rollback that would discard accepted load snapshot hash inputs', function (): void {
    extract(tripRecoveryFixture());
    $load = $workflow->recordLoad($actor, $trip->id, 3, (string) Str::uuid(), $items);
    $acceptedSnapshot = $load->items()->sole()->native_line_snapshot;
    expect($trip->recoveryEvents()->exists())->toBeFalse();
    $migration = require base_path('plugins/shafique/logistics/database/migrations/2026_10_21_000100_add_trip_recovery_history.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward recovery');
    expect(Schema::hasColumn('logistics_load_items', 'native_line_snapshot'))->toBeTrue()
        ->and($load->items()->sole()->native_line_snapshot)->toBe($acceptedSnapshot);
});

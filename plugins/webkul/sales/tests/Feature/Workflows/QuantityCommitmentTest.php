<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Webkul\Inventory\Enums\DeliveryStep;
use Webkul\Inventory\Enums\LocationType;
use Webkul\Inventory\Enums\ReceptionStep;
use Webkul\Inventory\Models\Move as InventoryMove;
use Webkul\Inventory\Models\Rule;
use Webkul\Partner\Models\Partner;
use Webkul\Product\Enums\ProductType;
use Webkul\Product\Models\Packaging;
use Webkul\Sale\Enums\OrderState;
use Webkul\Sale\Events\OrderConfirmed;
use Webkul\Sale\Facades\SaleOrder;
use Webkul\Sale\Services\OrderWorkflow;
use Webkul\Security\Models\User;
use Webkul\Support\Enums\UOMType;
use Webkul\Support\Models\UOM;

require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../../../inventories/tests/Helpers/InventoryHelper.php';
require_once __DIR__.'/../../Helpers/SaleHelper.php';

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('inventories');
    TestBootstrapHelper::ensurePluginInstalled('sales');
    SaleHelper::actingAsAdmin();
    $this->warehouse = InventoryHelper::warehouse(ReceptionStep::ONE_STEP, DeliveryStep::ONE_STEP);
});

it('saves one historical quantity commitment when confirmation is retried', function (): void {
    $product = SaleHelper::product();
    $order = SaleHelper::order(['warehouse_id' => $this->warehouse->id]);
    $line = SaleHelper::line($order, $product, 72, 10);

    SaleHelper::confirm($order);
    SaleHelper::confirm($order);

    $snapshot = $line->quantityCommitments()->sole();
    expect($snapshot->revision)->toBe(1)
        ->and((float) $snapshot->data['commercial_quantity'])->toBe(72.0)
        ->and((float) $snapshot->data['stock_quantity'])->toBe(72.0)
        ->and($snapshot->data['stock_unit']['id'])->toBe($product->uom_id);
});

it('invoices the commercial quantity rather than counting the stock units as packs', function (): void {
    $product = SaleHelper::product(['invoice_policy' => 'order']);
    $unit = $product->uom;
    $dozen = UOM::factory()->create([
        'name' => 'Carton of twelve', 'category_id' => $unit->category_id,
        'type' => UOMType::BIGGER, 'factor' => $unit->factor / 12, 'rounding' => 1,
    ]);
    $order = SaleHelper::order(['warehouse_id' => $this->warehouse->id]);
    $line = SaleHelper::line($order, $product, 72, 100);
    $line->update(['product_qty' => 6, 'product_uom_id' => $dozen->id]);

    SaleHelper::confirm($order);
    $invoice = SaleHelper::createInvoice($order);

    expect((float) $invoice->lines()->where('product_id', $product->id)->sole()->quantity)->toBe(6.0)
        ->and((float) $order->refresh()->amount_untaxed)->toBe(600.0);
});

it('preserves the saved pack and unit conversion after master data changes and a quantity revision', function (): void {
    $product = SaleHelper::product();
    $stock = $product->uom;
    $carton = UOM::factory()->create([
        'category_id' => $stock->category_id, 'type' => UOMType::BIGGER,
        'factor'      => $stock->factor / 12, 'rounding' => 1,
    ]);
    $pack = Packaging::factory()->create(['product_id' => $product->id, 'company_id' => $product->company_id, 'qty' => 12]);
    $order = SaleHelper::order(['warehouse_id' => $this->warehouse->id]);
    $line = SaleHelper::line($order, $product, 72, 100);
    $line->update(['product_qty' => 6, 'product_uom_id' => $carton->id, 'product_packaging_id' => $pack->id, 'product_packaging_qty' => 6]);
    SaleHelper::confirm($order);
    $old = $line->quantityCommitments()->sole();
    $originalData = $old->data;

    // Simulate an external/imported master edit; native Inventory prevents in-use factor edits.
    DB::table('unit_of_measures')->where('id', $carton->id)->update(['factor' => $stock->factor / 24]);
    $pack->update(['qty' => 24, 'name' => 'Changed packaging']);
    $line->refresh()->update(['product_qty' => 7]);

    $current = $line->currentQuantityCommitment()->firstOrFail();
    expect($old->refresh()->data)->toBe($originalData)
        ->and($current->data['commercial_unit'])->toBe($originalData['commercial_unit'])
        ->and($current->data['packaging']['name'])->toBe($originalData['packaging']['name'])
        ->and($old->data['stock_quantity'])->toBe(72)
        ->and($current->revision)->toBe(2)
        ->and($current->data['stock_quantity'])->toBe(84)
        ->and($current->data['packaging']['stock_units_per_pack'])->toBe(12)
        ->and((float) $line->inventoryMoves()->where('state', '!=', 'canceled')->sum('product_uom_qty'))->toBe(84.0);
});

it('does not create a new quantity revision for a price-only change or recomputation', function (): void {
    $product = SaleHelper::product();
    $order = SaleHelper::order(['warehouse_id' => $this->warehouse->id]);
    $line = SaleHelper::line($order, $product, 10, 100);
    SaleHelper::confirm($order);
    $line->refresh()->update(['price_unit' => 120]);
    SaleHelper::compute($order);
    expect($line->quantityCommitments()->count())->toBe(1);
});

it('rejects a stale confirmed quantity edit without changing stock demand or its history', function (): void {
    $product = SaleHelper::product();
    $order = SaleHelper::order(['warehouse_id' => $this->warehouse->id]);
    $line = SaleHelper::line($order, $product, 10, 100);
    SaleHelper::confirm($order);
    $stale = $line->fresh();
    $line->refresh()->update(['product_qty' => 12, 'product_uom_qty' => 12]);
    expect(fn () => $stale->update(['product_qty' => 14, 'product_uom_qty' => 14]))->toThrow(ValidationException::class);
    expect((float) $line->fresh()->product_qty)->toBe(12.0)
        ->and($line->quantityCommitments()->count())->toBe(2);
});

it('records quiet quantity saves and model increments through the same revision boundary', function (): void {
    $product = SaleHelper::product();
    $order = SaleHelper::order(['warehouse_id' => $this->warehouse->id]);
    $line = SaleHelper::line($order, $product, 10, 100);
    SaleHelper::confirm($order);
    $inventorySaves = 0;
    Event::listen('eloquent.saving: '.InventoryMove::class, function () use (&$inventorySaves): void {
        $inventorySaves++;
    });
    $line->refresh()->product_qty = 11;
    $line->saveQuietly();
    expect($inventorySaves)->toBeGreaterThan(0);
    $line->increment('product_qty');
    expect($line->quantityCommitments()->count())->toBe(3)
        ->and((float) $line->fresh()->product_uom_qty)->toBe(12.0);
    $moves = $line->inventoryMoves()->where('state', '!=', 'canceled')->get();
    expect((float) $moves->sum('product_uom_qty'))->toBe(12.0)
        ->and($moves)->not->toBeEmpty();
    foreach ($moves as $move) {
        expect($move->source_location_id)->toBe($this->warehouse->lot_stock_location_id)
            ->and($move->destinationLocation->type)->toBe(LocationType::CUSTOMER)
            ->and($move->operation_id)->not->toBeNull()
            ->and($move->company_id)->toBe($order->company_id)
            ->and($move->uom_id)->toBe($product->uom_id);
    }
});

it('rejects changes and deletions of saved commitment records even without model events', function (): void {
    $order = SaleHelper::order(['warehouse_id' => $this->warehouse->id]);
    $line = SaleHelper::line($order, SaleHelper::product(), 10, 100);
    SaleHelper::confirm($order);
    $snapshot = $line->quantityCommitments()->sole();
    expect(fn () => DB::transaction(fn () => $snapshot->forceFill(['revision' => 2])->saveQuietly()))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => $snapshot->deleteQuietly()))->toThrow(QueryException::class);
    expect(fn () => $line->deleteQuietly())->toThrow(ValidationException::class);
    expect($line->quantityCommitments()->count())->toBe(1);
});

it('refuses to roll back confirmed quantity history without dropping its records or trigger', function (): void {
    $order = SaleHelper::order(['warehouse_id' => $this->warehouse->id]);
    $line = SaleHelper::line($order, SaleHelper::product(), 10, 100);
    SaleHelper::confirm($order);
    $commitment = $line->quantityCommitments()->sole();
    $migration = require __DIR__.'/../../../database/migrations/2026_10_15_000100_create_sales_quantity_commitments_table.php';

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward recovery');
    expect(DB::table('sales_quantity_commitments')->where('id', $commitment->id)->exists())->toBeTrue()
        ->and(DB::table('pg_trigger')->where('tgname', 'sales_quantity_commitments_immutable')->exists())->toBeTrue();
});

it('rejects unknown or incompatible units and preserves the draft order without deliveries', function (): void {
    $order = SaleHelper::order(['warehouse_id' => $this->warehouse->id]);
    $line = SaleHelper::line($order, SaleHelper::product(), 10, 100);
    $line->update(['product_uom_id' => UOM::factory()->create()->id]);
    expect(fn () => SaleHelper::confirm($order))->toThrow(ValidationException::class);
    expect($order->refresh()->state)->toBe(OrderState::DRAFT)
        ->and($order->operations()->count())->toBe(0)
        ->and($line->quantityCommitments()->count())->toBe(0);
});

it('rejects fractional sealed packs and quantities below the approved unit precision', function (): void {
    $product = SaleHelper::product();
    $pack = Packaging::factory()->create(['product_id' => $product->id, 'company_id' => $product->company_id, 'qty' => 12]);
    $order = SaleHelper::order(['warehouse_id' => $this->warehouse->id]);
    $line = SaleHelper::line($order, $product, 13, 100);
    $line->update(['product_packaging_id' => $pack->id, 'product_packaging_qty' => 13 / 12]);
    expect(fn () => SaleHelper::confirm($order))->toThrow(ValidationException::class);
    $line->refresh()->update(['product_packaging_id' => null, 'product_packaging_qty' => null, 'product_qty' => 0.0001]);
    expect(fn () => SaleHelper::confirm($order))->toThrow(ValidationException::class);
    expect($line->quantityCommitments()->count())->toBe(0);
});

it('rolls back every quantity snapshot when a late confirmation effect fails', function (): void {
    $order = SaleHelper::order(['warehouse_id' => $this->warehouse->id]);
    $line = SaleHelper::line($order, SaleHelper::product(), 10, 100);
    Event::listen(OrderConfirmed::class, fn () => throw new RuntimeException('Late confirmation failure'));
    expect(fn () => SaleHelper::confirm($order))->toThrow(RuntimeException::class, 'Late confirmation failure');
    expect($line->quantityCommitments()->count())->toBe(0)
        ->and($order->refresh()->state)->toBe(OrderState::DRAFT)
        ->and($order->operations()->count())->toBe(0);
});

it('keeps old revisions and records a new confirmation after cancellation', function (): void {
    $order = SaleHelper::order(['warehouse_id' => $this->warehouse->id]);
    $line = SaleHelper::line($order, SaleHelper::product(), 10, 100);
    SaleHelper::confirm($order);
    SaleOrder::cancelSaleOrder($order->refresh());
    SaleOrder::backToQuotation($order->refresh());
    SaleHelper::confirm($order);
    expect($line->quantityCommitments()->pluck('revision')->all())->toBe([1, 2]);
});

it('prevents locked or unauthorized confirmed quantity changes', function (): void {
    $order = SaleHelper::order(['warehouse_id' => $this->warehouse->id]);
    $line = SaleHelper::line($order, SaleHelper::product(), 10, 100);
    SaleHelper::confirm($order);
    $order->refresh()->update(['locked' => true]);
    expect(fn () => $line->refresh()->update(['product_qty' => 12]))->toThrow(ValidationException::class);
    $order->refresh()->update(['locked' => false]);
    auth()->login(User::factory()->create());
    expect(fn () => $line->refresh()->update(['product_qty' => 12]))->toThrow(AuthorizationException::class);
    SaleHelper::actingAsAdmin();
    expect($line->quantityCommitments()->count())->toBe(1);
});

it('holds a quantity revision when the product stock unit identity changed', function (): void {
    $product = SaleHelper::product();
    $order = SaleHelper::order(['warehouse_id' => $this->warehouse->id]);
    $line = SaleHelper::line($order, $product, 10, 100);
    SaleHelper::confirm($order);
    $newUnit = UOM::factory()->create(['category_id' => $product->uom->category_id]);
    // Simulate an imported legacy mapping change that bypassed the native Product observer.
    DB::table('products_products')->where('id', $product->id)->update(['uom_id' => $newUnit->id]);
    expect(fn () => $line->refresh()->update(['product_qty' => 12]))->toThrow(ValidationException::class);
    expect((float) $line->fresh()->product_qty)->toBe(10.0)
        ->and($line->quantityCommitments()->count())->toBe(1);
});

it('keeps historical addresses and prevents silently changing a live delivery destination', function (): void {
    $order = SaleHelper::order(['warehouse_id' => $this->warehouse->id]);
    $line = SaleHelper::line($order, SaleHelper::product(), 10, 100);
    SaleHelper::confirm($order);
    $old = $line->quantityCommitments()->sole()->data['shipping_address'];
    $order->partnerShipping->update(['street1' => 'A changed address']);
    $line->refresh()->update(['product_qty' => 12]);
    expect($line->currentQuantityCommitment()->firstOrFail()->data['shipping_address'])->toBe($old);
    $newPartner = Partner::factory()->create();
    expect(fn () => $order->refresh()->update(['partner_shipping_id' => $newPartner->id]))->toThrow(ValidationException::class);
});

it('preserves six-pack delivery and invoice quantities after a commercial unit factor changes', function (): void {
    $product = SaleHelper::product(['invoice_policy' => 'delivery']);
    $stock = $product->uom;
    $carton = UOM::factory()->create(['category_id' => $stock->category_id, 'type' => UOMType::BIGGER, 'factor' => $stock->factor / 12, 'rounding' => 1]);
    InventoryHelper::stockUp($product, $this->warehouse->lotStockLocation, 72);
    $order = SaleHelper::order(['warehouse_id' => $this->warehouse->id]);
    $line = SaleHelper::line($order, $product, 72, 100);
    $line->update(['product_qty' => 6, 'product_uom_id' => $carton->id]);
    SaleHelper::confirm($order);
    // Simulate an external/imported master edit; native Inventory prevents in-use factor edits.
    DB::table('unit_of_measures')->where('id', $carton->id)->update(['factor' => $stock->factor / 24]);
    SaleHelper::deliverChain($order);
    $invoice = SaleHelper::createInvoice($order);
    expect((float) $line->fresh()->qty_delivered)->toBe(6.0)
        ->and((float) $invoice->lines()->where('product_id', $product->id)->sole()->quantity)->toBe(6.0)
        ->and((float) $invoice->amount_untaxed)->toBe(600.0);
});

it('invoices only an additional commercial pack and preserves both invoice source links', function (): void {
    $product = SaleHelper::product(['invoice_policy' => 'order']);
    $stock = $product->uom;
    $carton = UOM::factory()->create(['category_id' => $stock->category_id, 'type' => UOMType::BIGGER, 'factor' => $stock->factor / 12, 'rounding' => 1]);
    $order = SaleHelper::order(['warehouse_id' => $this->warehouse->id]);
    $line = SaleHelper::line($order, $product, 72, 100);
    $line->update(['product_qty' => 6, 'product_uom_id' => $carton->id]);
    SaleHelper::confirm($order);
    $first = SaleHelper::createInvoice($order);
    $line->refresh()->update(['product_qty' => 7]);
    SaleHelper::compute($order);
    $second = SaleHelper::createInvoice($order);
    expect((float) $second->lines()->where('product_id', $product->id)->sole()->quantity)->toBe(1.0)
        ->and((float) $second->amount_untaxed)->toBe(100.0)
        ->and($line->accountMoveLines()->pluck('accounts_account_move_lines.move_id')->sort()->values()->all())->toBe(collect([$first->id, $second->id])->sort()->values()->all())
        ->and((float) $line->fresh()->qty_invoiced)->toBe(7.0);
});

it('does not re-convert already procured stock when its reference factor was externally edited', function (): void {
    $product = SaleHelper::product();
    $order = SaleHelper::order(['warehouse_id' => $this->warehouse->id]);
    $line = SaleHelper::line($order, $product, 10, 100);
    SaleHelper::confirm($order);
    DB::table('unit_of_measures')->where('id', $product->uom_id)->update(['factor' => 2]);
    $line->refresh()->update(['product_qty' => 12]);
    expect((float) $line->inventoryMoves()->where('state', '!=', 'canceled')->sum('product_uom_qty'))->toBe(12.0)
        ->and($line->currentQuantityCommitment()->firstOrFail()->data['stock_units_per_commercial_unit'])->toBe(1);
});

it('does not fabricate quantity history for an already confirmed legacy order', function (): void {
    $order = SaleHelper::order(['warehouse_id' => $this->warehouse->id]);
    $line = SaleHelper::line($order, SaleHelper::product(), 10, 100);
    DB::table('sales_orders')->where('id', $order->id)->update(['state' => 'sale']);
    DB::table('sales_order_lines')->where('id', $line->id)->update(['state' => 'sale']);
    SaleHelper::confirm($order);
    $line->refresh()->update(['price_unit' => 110]);
    expect($line->currentQuantityCommitment)->toBeNull();
});

it('keeps the confirmed loading warehouse when a route master changes before a price-only save', function (): void {
    $rule = Rule::query()
        ->where('warehouse_id', $this->warehouse->id)
        ->whereHas('destinationLocation', fn ($query) => $query->where('type', 'customer'))
        ->firstOrFail();
    $order = SaleHelper::order(['warehouse_id' => $this->warehouse->id]);
    $line = SaleHelper::line($order, SaleHelper::product(), 10, 100);
    $line->forceFill(['route_id' => $rule->route_id])->save();
    SaleHelper::confirm($order);
    $before = $line->fresh()->warehouse_id;
    $other = InventoryHelper::warehouse(ReceptionStep::ONE_STEP, DeliveryStep::ONE_STEP);
    $rule->update(['source_location_id' => $other->lot_stock_location_id]);
    $line->refresh()->update(['price_unit' => 120]);
    expect($line->fresh()->warehouse_id)->toBe($before)
        ->and($line->quantityCommitments()->count())->toBe(1)
        ->and($line->currentQuantityCommitment()->firstOrFail()->data['warehouse_id'])->toBe($before);
});

it('does not let a create-only user confirm an existing draft through the new-record command', function (): void {
    $order = SaleHelper::order(['warehouse_id' => $this->warehouse->id]);
    SaleHelper::line($order, SaleHelper::product(), 10, 100);
    $actor = User::factory()->create();
    Permission::findOrCreate('create_sale_order', 'web');
    $actor->givePermissionTo('create_sale_order');
    $order->update(['creator_id' => $actor->id]);
    auth()->login($actor);
    expect(fn () => app(OrderWorkflow::class)->confirmCreatedOrder($order->fresh()))
        ->toThrow(AuthorizationException::class);
    SaleHelper::actingAsAdmin();
    expect($order->refresh()->state)->toBe(OrderState::DRAFT)
        ->and($order->lines()->firstOrFail()->quantityCommitments()->count())->toBe(0);
});

it('does not let the new-record command confirm another actors newly created order', function (): void {
    $order = SaleHelper::order(['warehouse_id' => $this->warehouse->id]);
    SaleHelper::line($order, SaleHelper::product(), 10, 100);
    $actor = User::factory()->create();
    auth()->login($actor);
    expect(fn () => app(OrderWorkflow::class)->confirmCreatedOrder($order))
        ->toThrow(AuthorizationException::class);
    SaleHelper::actingAsAdmin();
    expect($order->refresh()->state)->toBe(OrderState::DRAFT);
});

it('rejects significant legacy quantity and header edits without inventing conversion history', function (): void {
    $order = SaleHelper::order(['warehouse_id' => $this->warehouse->id]);
    $line = SaleHelper::line($order, SaleHelper::product(), 10, 100);
    DB::table('sales_orders')->where('id', $order->id)->update(['state' => 'sale']);
    DB::table('sales_order_lines')->where('id', $line->id)->update(['state' => 'sale']);
    expect(fn () => $line->refresh()->update(['product_qty' => 12]))->toThrow(ValidationException::class);
    $other = Partner::factory()->create();
    expect(fn () => $order->refresh()->update(['partner_shipping_id' => $other->id]))->toThrow(ValidationException::class);
    $line->refresh()->update(['price_unit' => 110]);
    expect((float) $line->fresh()->product_qty)->toBe(10.0)
        ->and((float) $line->fresh()->price_unit)->toBe(110.0)
        ->and($order->fresh()->partner_shipping_id)->not->toBe($other->id)
        ->and($line->quantityCommitments()->count())->toBe(0)
        ->and($order->operations()->count())->toBe(0);
});

it('rejects nonnumeric source quantities rather than treating them as a zero remainder', function (mixed $value, bool $quiet): void {
    $order = SaleHelper::order(['warehouse_id' => $this->warehouse->id]);
    $line = SaleHelper::line($order, SaleHelper::product(), 10, 100);
    SaleHelper::confirm($order);
    $line->refresh()->product_qty = $value;
    expect(fn () => $quiet ? $line->saveQuietly() : $line->save())->toThrow(ValidationException::class);
    expect((float) $line->fresh()->product_qty)->toBe(10.0)
        ->and($line->quantityCommitments()->count())->toBe(1)
        ->and((float) $line->inventoryMoves()->sum('product_uom_qty'))->toBe(10.0);
})->with([
    'NaN'              => ['NaN', false],
    'Infinity quietly' => ['Infinity', true],
    'boolean quietly'  => [false, true],
]);

it('confirms excluded draft lines without creating quantity history or stock demand', function (array $excluded): void {
    $order = SaleHelper::order(['warehouse_id' => $this->warehouse->id]);
    $line = SaleHelper::line($order, SaleHelper::product(), 10, 100);
    $line->update($excluded);

    SaleHelper::confirm($order);

    expect($order->refresh()->state)->toBe(OrderState::SALE)
        ->and($line->quantityCommitments()->count())->toBe(0)
        ->and($line->inventoryMoves()->count())->toBe(0)
        ->and($order->operations()->count())->toBe(0);
    if ($excluded['display_type'] ?? false) {
        expect((float) $line->fresh()->untaxed_amount_to_invoice)->toBe(0.0);
    }
})->with([
    'note'            => [['display_type' => 'line_note']],
    'expense'         => [['is_expense' => true]],
    'down payment'    => [['is_downpayment' => true]],
    'no product note' => [['product_id' => null, 'display_type' => 'line_note']],
]);

it('rejects repurposing a recorded confirmed item without changing its source or stock demand', function (array $excluded, bool $service): void {
    $product = SaleHelper::product($service ? ['type' => ProductType::SERVICE, 'is_storable' => false] : []);
    $order = SaleHelper::order(['warehouse_id' => $this->warehouse->id]);
    $line = SaleHelper::line($order, $product, 10, 100);
    SaleHelper::confirm($order);
    $original = $line->fresh()->getRawOriginal();
    $history = $line->quantityCommitments()->sole()->data;
    $demand = (float) $line->inventoryMoves()->where('state', '!=', 'canceled')->sum('product_uom_qty');

    $line->refresh()->forceFill($excluded);
    expect(fn () => $line->saveQuietly())->toThrow(ValidationException::class);

    expect($line->fresh()->getRawOriginal())->toBe($original)
        ->and($line->quantityCommitments()->count())->toBe(1)
        ->and($line->quantityCommitments()->sole()->data)->toBe($history)
        ->and((float) $line->inventoryMoves()->where('state', '!=', 'canceled')->sum('product_uom_qty'))->toBe($demand);
})->with([
    'note'                    => [['display_type' => 'line_note'], false],
    'expense'                 => [['is_expense' => true], false],
    'down payment'            => [['is_downpayment' => true], false],
    'no product on a service' => [['product_id' => null], true],
]);

it('records service quantities without creating stock demand', function (): void {
    $product = SaleHelper::product(['type' => ProductType::SERVICE, 'is_storable' => false]);
    $order = SaleHelper::order(['warehouse_id' => $this->warehouse->id]);
    $line = SaleHelper::line($order, $product, 10, 100);

    SaleHelper::confirm($order);
    $line->refresh()->update(['product_qty' => 12]);

    expect($line->quantityCommitments()->count())->toBe(2)
        ->and($line->currentQuantityCommitment()->firstOrFail()->data['commercial_quantity'])->toBe(12)
        ->and($line->inventoryMoves()->count())->toBe(0)
        ->and($order->operations()->count())->toBe(0);
});

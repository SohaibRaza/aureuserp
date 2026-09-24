<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Webkul\Inventory\Enums\MoveState;
use Webkul\Inventory\Models\MoveLine;
use Webkul\Inventory\Models\ProductQuantity;

require_once __DIR__.'/../../Helpers/InventoryHelper.php';

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('inventories');
    InventoryHelper::actingAsAdmin();
    $this->warehouse = InventoryHelper::warehouse();
    $this->product = InventoryHelper::product();
    $this->stock = InventoryHelper::stockUp($this->product, $this->warehouse->lotStockLocation, 100);
});

it('rejects duplicate nullable balance identities at the database boundary', function (): void {
    expect(fn () => DB::transaction(fn () => $this->stock->replicate()->save()))->toThrow(QueryException::class);
    expect(ProductQuantity::where('product_id', $this->product->id)->count())->toBe(1);
});

it('refuses migration over duplicate identities without merging their quantities', function (): void {
    $migration = require __DIR__.'/../../../database/migrations/2026_09_24_010000_enforce_product_quantity_identity.php';
    $migration->down();
    $duplicate = $this->stock->replicate();
    $duplicate->save();

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Duplicate Inventory balance identities');
    expect(ProductQuantity::where('product_id', $this->product->id)->count())->toBe(2)
        ->and((float) ProductQuantity::where('product_id', $this->product->id)->sum('quantity'))->toBe(200.0);
});

it('does not leave a direct move line behind when its reservation fails', function (): void {
    $operation = InventoryHelper::delivery($this->warehouse, [[$this->product, 120]]);
    $move = $operation->moves->first();
    $move->update(['state' => MoveState::CONFIRMED]);
    $before = MoveLine::count();

    expect(fn () => $move->lines()->create($move->buildLineAttributes(quantity: 120)))
        ->toThrow(InvalidArgumentException::class);
    expect(MoveLine::count())->toBe($before)
        ->and((float) $this->stock->refresh()->reserved_quantity)->toBe(0.0);
});

it('rejects releasing more than the current reservation without changing stock', function (): void {
    expect(fn () => ProductQuantity::applyReservationDelta($this->product, $this->warehouse->lotStockLocation, -1))
        ->toThrow(InvalidArgumentException::class);
    expect((float) $this->stock->refresh()->reserved_quantity)->toBe(0.0);
});

it('does not overwrite a concurrent line quantity or reservation from a stale model', function (): void {
    $move = InventoryHelper::delivery($this->warehouse, [[$this->product, 100]])->moves->first();
    $move->update(['state' => MoveState::CONFIRMED]);
    $line = $move->lines()->create($move->buildLineAttributes(quantity: 40));
    $stale = $line->fresh();
    $line->update(['qty' => 60]);

    expect(fn () => $stale->update(['qty' => 70]))->toThrow(InvalidArgumentException::class, 'changed');
    expect((float) $line->fresh()->qty)->toBe(60.0)
        ->and((float) $this->stock->fresh()->reserved_quantity)->toBe(60.0);
});

it('rolls back direct line deletion and its released reservation after a listener failure', function (): void {
    $move = InventoryHelper::delivery($this->warehouse, [[$this->product, 100]])->moves->first();
    $move->update(['state' => MoveState::CONFIRMED]);
    $line = $move->lines()->create($move->buildLineAttributes(quantity: 40));
    MoveLine::deleted(function (): void {
        throw new RuntimeException('Delete listener failed');
    });

    expect(fn () => $line->delete())->toThrow(RuntimeException::class, 'Delete listener failed');
    expect(MoveLine::find($line->id))->not->toBeNull()
        ->and((float) $this->stock->fresh()->reserved_quantity)->toBe(40.0);
});

it('keeps a specified lot separate from the untracked balance during a delta', function (): void {
    $lot = InventoryHelper::lot($this->product, 'Identity lot');
    ProductQuantity::applyQuantityDelta($this->product, $this->warehouse->lotStockLocation, -10, lot: $lot);

    expect((float) $this->stock->fresh()->quantity)->toBe(100.0)
        ->and((float) ProductQuantity::where('product_id', $this->product->id)->where('lot_id', $lot->id)->value('quantity'))->toBe(-10.0);
});

it('does not purge another product balance as a side effect of a stock mutation', function (): void {
    $otherProduct = InventoryHelper::product();
    $otherStock = InventoryHelper::stockUp($otherProduct, $this->warehouse->lotStockLocation, 0);

    ProductQuantity::applyQuantityDelta($this->product, $this->warehouse->lotStockLocation, 1);

    expect($otherStock->fresh())->not->toBeNull();
    ProductQuantity::purgeEmpty([$otherProduct->id]);
    expect($otherStock->fresh())->toBeNull();
});

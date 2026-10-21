<?php

use Illuminate\Validation\ValidationException;
use Webkul\Inventory\Enums\ScrapState;
use Webkul\Inventory\Models\Lot;
use Webkul\Inventory\Models\Move;
use Webkul\Inventory\Models\MoveLine;
use Webkul\Inventory\Models\ProductQuantity;
use Webkul\Inventory\Models\Scrap;
use Webkul\Support\Enums\UOMType;
use Webkul\Support\Models\UOM;

require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../Helpers/InventoryHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('inventories');

    InventoryHelper::actingAsAdmin();

    $this->warehouse = InventoryHelper::warehouse();
    $this->product = InventoryHelper::product();
    $this->stock = $this->warehouse->lotStockLocation;
    $this->scrapLocation = InventoryHelper::scrapLocation();
});

it('allows draft scrap edits and deletion without changing stock', function (): void {
    InventoryHelper::stockUp($this->product, $this->stock, 10);
    $scrap = InventoryHelper::scrap($this->product, $this->stock, $this->scrapLocation, 3);

    expect($scrap->update(['qty' => 5, 'origin' => 'Draft correction']))->toBeTrue()
        ->and((float) $scrap->fresh()->qty)->toBe(5.0)
        ->and($scrap->fresh()->origin)->toBe('Draft correction')
        ->and($scrap->delete())->toBeTrue()
        ->and(Scrap::query()->whereKey($scrap->id)->exists())->toBeFalse()
        ->and(InventoryHelper::onHand($this->product, $this->stock))->toBe(10.0)
        ->and(Move::query()->where('scrap_id', $scrap->id)->exists())->toBeFalse();
});

it('allows an unchanged completed scrap to be saved without replaying stock', function (): void {
    InventoryHelper::stockUp($this->product, $this->stock, 10);
    $scrap = InventoryHelper::scrap($this->product, $this->stock, $this->scrapLocation, 3);
    expect($scrap->validate())->toBeTrue()
        ->and($scrap->save())->toBeTrue()
        ->and(InventoryHelper::onHand($this->product, $this->stock))->toBe(7.0)
        ->and(Move::query()->where('scrap_id', $scrap->id)->count())->toBe(1);
});

it('protects completed scrap stock and history from model edits and deletion', function (bool $stale, string $mutation): void {
    InventoryHelper::stockUp($this->product, $this->stock, 10);
    $scrap = InventoryHelper::scrap($this->product, $this->stock, $this->scrapLocation, 3);
    expect($scrap->fresh()->validate())->toBeTrue();
    if (! $stale) {
        $scrap->refresh();
    }
    $move = Move::query()->where('scrap_id', $scrap->id)->firstOrFail();
    $lineId = $move->lines()->firstOrFail()->id;

    expect(function () use ($scrap, $mutation): void {
        match ($mutation) {
            'quantity' => $scrap->update(['qty' => 5]),
            'reopen'   => $scrap->update(['state' => ScrapState::DRAFT, 'closed_at' => null, 'qty' => 5]),
            'quiet'    => $scrap->updateQuietly(['source_location_id' => $this->scrapLocation->id]),
            'delete'   => $scrap->delete(),
        };
    })->toThrow(ValidationException::class);

    expect($scrap->fresh()->state)->toBe(ScrapState::DONE)
        ->and((float) $scrap->fresh()->qty)->toBe(3.0)
        ->and($scrap->fresh()->closed_at)->not->toBeNull()
        ->and($scrap->fresh()->source_location_id)->toBe($this->stock->id)
        ->and(InventoryHelper::onHand($this->product, $this->stock))->toBe(7.0)
        ->and(InventoryHelper::onHand($this->product, $this->scrapLocation))->toBe(3.0)
        ->and($move->fresh()->scrap_id)->toBe($scrap->id)
        ->and($move->lines()->firstOrFail()->id)->toBe($lineId);
})->with([false, true])->with(['quantity', 'reopen', 'quiet', 'delete']);

it('moves the scrapped quantity out of stock into the scrap location on validate', function () {
    InventoryHelper::stockUp($this->product, $this->stock, 10);

    $scrap = InventoryHelper::scrap($this->product, $this->stock, $this->scrapLocation, 3);

    $scrap->validate();

    expect($scrap->refresh()->state)->toBe(ScrapState::DONE)
        ->and(InventoryHelper::onHand($this->product, $this->stock))->toBe(7.0)
        ->and(InventoryHelper::onHand($this->product, $this->scrapLocation))->toBe(3.0);
});

it('refuses to validate a scrap when the source has insufficient quantity', function () {
    InventoryHelper::stockUp($this->product, $this->stock, 2);

    $scrap = InventoryHelper::scrap($this->product, $this->stock, $this->scrapLocation, 5);

    expect($scrap->validate())->toBeFalse()
        ->and($scrap->refresh()->state)->toBe(ScrapState::DRAFT)
        ->and(InventoryHelper::onHand($this->product, $this->stock))->toBe(2.0);
});

it('records an inventory move for the scrap', function () {
    InventoryHelper::stockUp($this->product, $this->stock, 10);

    $scrap = InventoryHelper::scrap($this->product, $this->stock, $this->scrapLocation, 3);

    $scrap->validate();

    expect(Move::query()->where('scrap_id', $scrap->id)->exists())->toBeTrue();
});

it('does not consume stock twice when a scrap is validated again', function () {
    InventoryHelper::stockUp($this->product, $this->stock, 10);

    $scrap = InventoryHelper::scrap($this->product, $this->stock, $this->scrapLocation, 3);

    expect($scrap->validate())->toBeTrue()
        ->and($scrap->validate())->toBeFalse()
        ->and(InventoryHelper::onHand($this->product, $this->stock))->toBe(7.0)
        ->and(InventoryHelper::onHand($this->product, $this->scrapLocation))->toBe(3.0)
        ->and(Move::query()->where('scrap_id', $scrap->id)->count())->toBe(1);
});

it('rolls back both stock updates and scrap completion if move history fails', function () {
    InventoryHelper::stockUp($this->product, $this->stock, 10);

    $scrap = InventoryHelper::scrap($this->product, $this->stock, $this->scrapLocation, 3);

    $failHistory = true;

    MoveLine::creating(function () use (&$failHistory) {
        if ($failHistory) {
            throw new RuntimeException('History write failed');
        }
    });

    try {
        expect(fn () => $scrap->validate())->toThrow(RuntimeException::class, 'History write failed');
    } finally {
        $failHistory = false;
    }

    expect($scrap->refresh()->state)->toBe(ScrapState::DRAFT)
        ->and(InventoryHelper::onHand($this->product, $this->stock))->toBe(10.0)
        ->and(ProductQuantity::query()->where('product_id', $this->product->id)->where('location_id', $this->scrapLocation->id)->exists())->toBeFalse()
        ->and(Move::query()->where('scrap_id', $scrap->id)->exists())->toBeFalse();
});

it('does not scrap stock reserved for another operation', function () {
    $stock = InventoryHelper::stockUp($this->product, $this->stock, 10);
    $stock->update(['reserved_quantity' => 8]);

    $scrap = InventoryHelper::scrap($this->product, $this->stock, $this->scrapLocation, 3);

    expect($scrap->validate())->toBeFalse()
        ->and((float) $stock->fresh()->quantity)->toBe(10.0)
        ->and($scrap->fresh()->state)->toBe(ScrapState::DRAFT);
});

it('accepts a decimal scrap quantity equal to available stock at the product precision', function (): void {
    $productUom = UOM::factory()->reference()->create(['rounding' => 0.0001]);
    $countUom = UOM::factory()->create([
        'type'        => UOMType::SMALLER,
        'factor'      => 0.3,
        'rounding'    => 0.0001,
        'category_id' => $productUom->category_id,
    ]);
    $this->product->update(['uom_id' => $productUom->id, 'uom_po_id' => $productUom->id]);
    InventoryHelper::stockUp($this->product->fresh(), $this->stock, 0.3333);

    $scrap = InventoryHelper::scrap($this->product->fresh(), $this->stock, $this->scrapLocation, 0.1);
    $scrap->update(['uom_id' => $countUom->id]);

    expect($scrap->validate())->toBeTrue()
        ->and(InventoryHelper::onHand($this->product, $this->stock))->toBe(0.0);
});

it('keeps the lot identity on the destination balance and movement', function () {
    $lot = Lot::factory()->create(['product_id' => $this->product->id]);
    InventoryHelper::stockUp($this->product, $this->stock, 10, $lot->id);
    $scrap = InventoryHelper::scrap($this->product, $this->stock, $this->scrapLocation, 3, $lot->id);

    expect($scrap->validate())->toBeTrue();

    $move = Move::query()->where('scrap_id', $scrap->id)->firstOrFail();

    expect((float) ProductQuantity::query()->where('product_id', $this->product->id)->where('location_id', $this->scrapLocation->id)->where('lot_id', $lot->id)->value('quantity'))->toBe(3.0)
        ->and($move->lines()->firstOrFail()->lot_id)->toBe($lot->id);
});

it('uses the current scrap unit after an earlier instance cached the old unit', function (): void {
    $baseUnit = UOM::factory()->reference()->create(['rounding' => 0.0001]);
    $packUnit = UOM::factory()->create([
        'category_id' => $baseUnit->category_id,
        'factor'      => 0.5,
        'rounding'    => 0.0001,
    ]);
    $this->product->update(['uom_id' => $baseUnit->id, 'uom_po_id' => $baseUnit->id]);
    InventoryHelper::stockUp($this->product->fresh(), $this->stock, 10);
    $scrap = InventoryHelper::scrap($this->product->fresh(), $this->stock, $this->scrapLocation, 3);
    $scrap->load('uom');
    $scrap->fresh()->update(['uom_id' => $packUnit->id]);

    expect($scrap->validate())->toBeTrue()
        ->and(InventoryHelper::onHand($this->product, $this->stock))->toBe(4.0)
        ->and(InventoryHelper::onHand($this->product, $this->scrapLocation))->toBe(6.0);
    $move = Move::query()->where('scrap_id', $scrap->id)->firstOrFail();
    expect((float) $move->product_uom_qty)->toBe(6.0)
        ->and((float) $move->lines()->firstOrFail()->uom_qty)->toBe(6.0);
});

it('rejects validation through an instance whose product was changed', function (): void {
    InventoryHelper::stockUp($this->product, $this->stock, 10);
    $otherProduct = InventoryHelper::product();
    InventoryHelper::stockUp($otherProduct, $this->stock, 10);
    $scrap = InventoryHelper::scrap($this->product, $this->stock, $this->scrapLocation, 3);
    $scrap->load('product', 'uom');
    $scrap->fresh()->update(['product_id' => $otherProduct->id, 'uom_id' => $otherProduct->uom_id]);

    expect($scrap->validate())->toBeFalse()
        ->and($scrap->fresh()->state)->toBe(ScrapState::DRAFT)
        ->and(InventoryHelper::onHand($this->product, $this->stock))->toBe(10.0)
        ->and(InventoryHelper::onHand($otherProduct, $this->stock))->toBe(10.0)
        ->and(Move::query()->where('scrap_id', $scrap->id)->exists())->toBeFalse();
});

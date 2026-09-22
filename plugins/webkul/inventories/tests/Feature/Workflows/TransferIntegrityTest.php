<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\Inventory\Enums\DeliveryStep;
use Webkul\Inventory\Enums\OperationState;
use Webkul\Inventory\Enums\ReceptionStep;
use Webkul\Inventory\Events\OperationReturned;
use Webkul\Inventory\Facades\Inventory;
use Webkul\Inventory\Models\Move;
use Webkul\Inventory\Models\MoveLine;
use Webkul\Inventory\Models\Operation;
use Webkul\Inventory\Models\ProductQuantity;

require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../Helpers/InventoryHelper.php';

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('inventories');

    InventoryHelper::actingAsAdmin();
});

function completedPhase2aDelivery(float $quantity = 10): array
{
    $warehouse = InventoryHelper::warehouse();
    $product = InventoryHelper::product();

    InventoryHelper::stockUp($product, $warehouse->lotStockLocation, $quantity);

    $delivery = InventoryHelper::delivery($warehouse, [[$product, $quantity]]);

    Inventory::confirmTransfer($delivery);
    Inventory::completeTransfer($delivery->refresh());

    return [$delivery->refresh(), $product];
}

function phase2aReturnEffects(Operation $source): array
{
    return [
        'operations'         => Operation::query()->count(),
        'moves'              => Move::query()->count(),
        'move_values'        => Move::query()
            ->orderBy('id')
            ->get(['id', 'operation_id', 'state', 'quantity', 'origin_returned_move_id'])
            ->toArray(),
        'move_lines'         => MoveLine::query()->count(),
        'move_links'         => DB::table('inventories_move_destinations')
            ->orderBy('origin_move_id')
            ->orderBy('destination_move_id')
            ->get()
            ->map(static fn (stdClass $link): array => (array) $link)
            ->all(),
        'chatter_messages'   => DB::table('chatter_messages')->count(),
        'product_quantities' => ProductQuantity::query()
            ->orderBy('id')
            ->get(['id', 'quantity', 'reserved_quantity'])
            ->toArray(),
        'source_state'       => $source->fresh()->state,
    ];
}

it('does not leave effects when no return quantities are supplied', function (): void {
    [$delivery] = completedPhase2aDelivery();
    $before = phase2aReturnEffects($delivery);

    expect(fn () => Inventory::createReturn($delivery))
        ->toThrow(InvalidArgumentException::class, 'A return requires quantities for existing source moves.');

    expect(phase2aReturnEffects($delivery))->toBe($before);
});

it('rejects a move from another operation without effects', function (): void {
    [$delivery] = completedPhase2aDelivery();
    [$otherDelivery] = completedPhase2aDelivery();
    $before = phase2aReturnEffects($delivery);

    expect(fn () => Inventory::createReturn($delivery, [
        $otherDelivery->moves->first()->id => 1,
    ]))->toThrow(InvalidArgumentException::class, 'A return requires quantities for existing source moves.');

    expect(phase2aReturnEffects($delivery))->toBe($before);
});

it('rejects malformed or non-positive return quantities without effects', function (mixed $quantity): void {
    [$delivery] = completedPhase2aDelivery();
    $before = phase2aReturnEffects($delivery);

    expect(fn () => Inventory::createReturn($delivery, [
        $delivery->moves->first()->id => $quantity,
    ]))->toThrow(InvalidArgumentException::class, 'Return quantities must be positive.');

    expect(phase2aReturnEffects($delivery))->toBe($before);
})->with([
    'numeric string'         => '1',
    'missing array quantity' => [['to_refund' => true]],
    'zero'                   => 0,
    'negative'               => -1,
    'infinite'               => INF,
]);

it('creates an explicit partial return with native traceability', function (): void {
    [$delivery] = completedPhase2aDelivery();
    $sourceMove = $delivery->moves->first();

    $return = Inventory::createReturn($delivery, [$sourceMove->id => 4]);
    $returnMove = $return->refresh()->moves->sole();

    expect($return->return_id)->toBe($delivery->id)
        ->and($return->state)->not->toBe(OperationState::DONE)
        ->and($returnMove->origin_returned_move_id)->toBe($sourceMove->id)
        ->and((float) $returnMove->product_uom_qty)->toBe(4.0);
});

it('preserves the supported explicit full-return behavior', function (): void {
    [$delivery] = completedPhase2aDelivery();
    $sourceMove = $delivery->moves->first();

    $return = Inventory::createReturn($delivery, [
        $sourceMove->id => (float) $sourceMove->product_uom_qty,
    ]);

    expect((float) $return->refresh()->moves->sole()->product_uom_qty)
        ->toBe((float) $sourceMove->product_uom_qty);
});

it('rolls back all database effects when a late return listener fails', function (): void {
    $warehouse = InventoryHelper::warehouse(ReceptionStep::ONE_STEP, DeliveryStep::TWO_STEPS);
    $product = InventoryHelper::product();
    InventoryHelper::stockUp($product, $warehouse->lotStockLocation, 10);

    $delivery = InventoryHelper::delivery($warehouse, [[$product, 10]]);
    Inventory::confirmTransfer($delivery);
    Inventory::completeTransfer($delivery->refresh());
    $delivery->refresh();

    $before = phase2aReturnEffects($delivery);

    Event::listen(OperationReturned::class, static function (): void {
        throw new RuntimeException('Test late return failure.');
    });

    expect(fn () => Inventory::createReturn($delivery, [
        $delivery->moves->first()->id => 4,
    ]))->toThrow(RuntimeException::class, 'Test late return failure.');

    expect(phase2aReturnEffects($delivery))->toBe($before);
});

it('allows a valid retry after a failed return attempt', function (): void {
    [$delivery] = completedPhase2aDelivery();
    $shouldFail = true;

    Event::listen(OperationReturned::class, static function () use (&$shouldFail): void {
        if ($shouldFail) {
            $shouldFail = false;

            throw new RuntimeException('Test one-time return failure.');
        }
    });

    expect(fn () => Inventory::createReturn($delivery, [
        $delivery->moves->first()->id => 4,
    ]))->toThrow(RuntimeException::class, 'Test one-time return failure.');

    $return = Inventory::createReturn($delivery->refresh(), [
        $delivery->moves->first()->id => 4,
    ]);

    expect($return->return_id)->toBe($delivery->id)
        ->and(Operation::query()->where('return_id', $delivery->id)->count())->toBe(1);
});

it('participates in an ambient caller transaction', function (): void {
    [$delivery] = completedPhase2aDelivery();
    $before = phase2aReturnEffects($delivery);

    DB::beginTransaction();

    try {
        Inventory::createReturn($delivery, [$delivery->moves->first()->id => 4]);
    } finally {
        DB::rollBack();
    }

    expect(phase2aReturnEffects($delivery))->toBe($before);
});

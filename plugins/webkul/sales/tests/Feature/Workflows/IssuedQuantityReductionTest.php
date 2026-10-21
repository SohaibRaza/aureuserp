<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Webkul\Inventory\Enums\DeliveryStep;
use Webkul\Inventory\Enums\MoveState;
use Webkul\Inventory\Enums\ReceptionStep;
use Webkul\Inventory\Facades\Inventory;
use Webkul\Inventory\Models\Move;
use Webkul\Inventory\Models\ProductQuantity;
use Webkul\Product\Models\Packaging;
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

function issuedQuantityEffects(): array
{
    return [
        DB::table('sales_order_lines')->orderBy('id')->get()->toJson(),
        DB::table('sales_quantity_commitments')->orderBy('id')->get()->toJson(),
        DB::table('inventories_moves')->orderBy('id')->get()->toJson(),
        DB::table('inventories_move_lines')->orderBy('id')->get()->toJson(),
        ProductQuantity::query()->orderBy('id')->get()->toJson(),
    ];
}

it('cancels only the pending remainder when reducing to the completed issue', function (): void {
    $product = SaleHelper::product();
    $order = SaleHelper::confirmedOrder($this->warehouse, $product, 10);
    SaleHelper::partialDeliver($order, 6);
    $line = $order->lines()->sole();
    $completed = $line->inventoryMoves()->where('state', MoveState::DONE)->sole();
    $completedValues = $completed->getAttributes();
    $balances = ProductQuantity::query()->where('product_id', $product->id)->orderBy('id')->pluck('quantity', 'id')->all();

    $line->update(['product_qty' => 6]);

    expect($completed->fresh()->getAttributes())->toBe($completedValues)
        ->and($line->inventoryMoves()->whereNotIn('state', [MoveState::DONE, MoveState::CANCELED])->count())->toBe(0)
        ->and($line->inventoryMoves()->where('source_location_id', $completed->destination_location_id)->count())->toBe(0)
        ->and(ProductQuantity::query()->where('product_id', $product->id)->orderBy('id')->pluck('quantity', 'id')->all())->toBe($balances)
        ->and($line->quantityCommitments()->count())->toBe(2);
});

it('rejects a commercial reduction below goods already issued without any effects', function (bool $partial, bool $quiet): void {
    $order = SaleHelper::confirmedOrder($this->warehouse, SaleHelper::product(), 10);
    $partial ? SaleHelper::partialDeliver($order, 6) : SaleHelper::deliverChain($order);
    $line = $order->lines()->sole();
    $before = issuedQuantityEffects();
    $line->product_qty = $partial ? 5 : 9;

    expect(fn () => $quiet ? $line->saveQuietly() : $line->save())
        ->toThrow(ValidationException::class, __('sales::models/quantity-commitment.issued-quantity'));
    expect(issuedQuantityEffects())->toBe($before);
})->with([[true, false], [false, false], [true, true]]);

it('uses stock quantities when checking a carton reduction against completed goods', function (): void {
    $product = SaleHelper::product();
    InventoryHelper::stockUp($product, $this->warehouse->lotStockLocation, 120);
    $carton = UOM::factory()->create([
        'category_id' => $product->uom->category_id, 'type' => UOMType::BIGGER,
        'factor'      => $product->uom->factor / 12, 'rounding' => 1,
    ]);
    $order = SaleHelper::order(['warehouse_id' => $this->warehouse->id]);
    $line = SaleHelper::line($order, $product, 10, 100);
    $line->update(['product_uom_id' => $carton->id]);
    SaleHelper::confirm($order);
    SaleHelper::partialDeliver($order, 72);
    $before = issuedQuantityEffects();

    expect(fn () => $line->refresh()->update(['product_qty' => 5]))->toThrow(ValidationException::class);
    expect(issuedQuantityEffects())->toBe($before);
    $line->refresh()->update(['product_qty' => 6]);
    expect((float) $line->refresh()->product_uom_qty)->toBe(72.0)
        ->and($line->inventoryMoves()->whereNotIn('state', [MoveState::DONE, MoveState::CANCELED])->count())->toBe(0);
});

it('allows a reduction after an explicit source-linked return has completed', function (): void {
    $order = SaleHelper::confirmedOrder($this->warehouse, SaleHelper::product(), 10);
    SaleHelper::deliverChain($order);
    $line = $order->lines()->sole();
    $source = $line->inventoryMoves()->where('state', MoveState::DONE)->sole();
    $return = Inventory::createReturn($source->operation, [$source->id => 2]);
    Inventory::completeTransfer($return);
    $moveIds = Move::query()->orderBy('id')->pluck('id')->all();

    $line->refresh()->update(['product_qty' => 8]);

    expect(Move::query()->orderBy('id')->pluck('id')->all())->toBe($moveIds)
        ->and($line->quantityCommitments()->count())->toBe(2)
        ->and((float) $source->fresh()->quantity)->toBe(10.0);
});

it('does not count a pending return as goods already returned', function (): void {
    $order = SaleHelper::confirmedOrder($this->warehouse, SaleHelper::product(), 10);
    SaleHelper::deliverChain($order);
    $line = $order->lines()->sole();
    $source = $line->inventoryMoves()->where('state', MoveState::DONE)->sole();
    Inventory::createReturn($source->operation, [$source->id => 2]);
    $before = issuedQuantityEffects();

    expect(fn () => $line->refresh()->update(['product_qty' => 8]))->toThrow(ValidationException::class);
    expect(issuedQuantityEffects())->toBe($before);
});

it('protects completed warehouse issues before a multi-step delivery reaches the customer', function (): void {
    $warehouse = InventoryHelper::warehouse(ReceptionStep::ONE_STEP, DeliveryStep::TWO_STEPS);
    $order = SaleHelper::confirmedOrder($warehouse, SaleHelper::product(), 10);
    SaleHelper::deliverNextLeg($order);
    $line = $order->lines()->sole();
    $before = issuedQuantityEffects();

    expect(fn () => $line->update(['product_qty' => 9]))->toThrow(ValidationException::class);
    expect(issuedQuantityEffects())->toBe($before);
});

it('waits for an Inventory issue and then rejects a reduction below the committed issue', function (): void {
    expect(DB::connection()->getDriverName())->toBe('pgsql');
    $database = DB::connection()->getDatabaseName();
    expect($database)->toMatch('/^aureuserp_[A-Za-z0-9_]+_testing$/');
    $order = SaleHelper::confirmedOrder($this->warehouse, SaleHelper::product(), 10);
    $line = $order->lines()->sole();
    $move = $line->inventoryMoves()->sole();
    $move->update(['quantity' => 10, 'is_picked' => true]);
    $userId = auth()->id();
    while (DB::connection()->transactionLevel() > 0) {
        DB::connection()->commit();
    }
    $directory = sys_get_temp_dir().'/aureuserp-issued-floor-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $worker = <<<'PHP'
chdir($argv[1]);
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Auth::loginUsingId((int) $argv[3]);
$line = Webkul\Sale\Models\OrderLine::findOrFail((int) $argv[2]);
$directory = $argv[4];
file_put_contents($directory.'/ready', (string) Illuminate\Support\Facades\DB::selectOne('select pg_backend_pid() as pid')->pid);
$deadline = microtime(true) + 20;
while (! file_exists($directory.'/go') && microtime(true) < $deadline) { usleep(10000); }
if (! file_exists($directory.'/go')) { exit(2); }
try {
    $line->update(['product_qty' => 9]);
    echo json_encode(['saved' => true]);
} catch (Illuminate\Validation\ValidationException $error) {
    echo json_encode(['saved' => false, 'errors' => $error->errors()]);
}
PHP;
    $process = new Process([PHP_BINARY, '-d', 'memory_limit=512M', '-r', $worker, base_path(), (string) $line->id, (string) $userId, $directory], base_path(), [
        ...$_ENV, 'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql',
        'DB_DATABASE'       => $database, 'AUREUSERP_TEST_REUSE_DATABASE' => '1',
    ], timeout: 40);
    try {
        $process->start();
        $deadline = microtime(true) + 20;
        while (! file_exists($directory.'/ready') && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(file_exists($directory.'/ready'))->toBeTrue();
        $pid = (int) file_get_contents($directory.'/ready');
        DB::beginTransaction();
        Inventory::completeMoves(Move::query()->whereKey($move->id)->get());
        expect($move->fresh()->state)->toBe(MoveState::DONE);
        touch($directory.'/go');
        $waiting = false;
        $deadline = microtime(true) + 10;
        do {
            DB::statement('select pg_stat_clear_snapshot()');
            $activity = DB::selectOne('select wait_event_type, query from pg_stat_activity where pid = ?', [$pid]);
            $waiting = $activity?->wait_event_type === 'Lock';
            if (! $waiting) {
                usleep(10000);
            }
        } while (! $waiting && $process->isRunning() && microtime(true) < $deadline);
        expect($waiting)->toBeTrue()
            ->and($activity->query)->toContain('products')->toContain('for update');
        DB::commit();
        $process->wait();
        expect($process->isSuccessful())->toBeTrue();
        $outcome = json_decode($process->getOutput(), true);
        expect($outcome['saved'])->toBeFalse()
            ->and($outcome['errors']['products'])->toBe([__('sales::models/quantity-commitment.issued-quantity')])
            ->and((float) $line->fresh()->product_qty)->toBe(10.0)
            ->and($line->quantityCommitments()->count())->toBe(1)
            ->and($line->inventoryMoves()->count())->toBe(1)
            ->and((float) $move->fresh()->quantity)->toBe(10.0);
    } finally {
        while (DB::connection()->transactionLevel() > 0) {
            DB::rollBack();
        }
        if ($process->isRunning()) {
            $process->stop(1);
        }
        @unlink($directory.'/ready');
        @unlink($directory.'/go');
        @rmdir($directory);
    }
});

it('does not use an unlinked historical return to authorize a commercial reduction', function (): void {
    $order = SaleHelper::confirmedOrder($this->warehouse, SaleHelper::product(), 10);
    SaleHelper::deliverChain($order);
    $line = $order->lines()->sole();
    $source = $line->inventoryMoves()->where('state', MoveState::DONE)->sole();
    $return = Inventory::createReturn($source->operation, [$source->id => 2]);
    Inventory::completeTransfer($return);
    // An imported historical return without a source link cannot prove which issue it corrects.
    DB::table('inventories_moves')->where('operation_id', $return->id)->update(['origin_returned_move_id' => null]);
    $before = issuedQuantityEffects();

    expect(fn () => $line->refresh()->update(['product_qty' => 8]))->toThrow(ValidationException::class);
    expect(issuedQuantityEffects())->toBe($before);
});

it('does not invent a reverse movement when changed packaging prevents pending demand from merging', function (): void {
    $product = SaleHelper::product();
    $order = SaleHelper::confirmedOrder($this->warehouse, $product, 10);
    SaleHelper::partialDeliver($order, 6);
    $packaging = Packaging::factory()->create([
        'product_id' => $product->id, 'company_id' => $product->company_id, 'qty' => 1,
    ]);
    $line = $order->lines()->sole();
    $before = issuedQuantityEffects();

    expect(fn () => $line->update(['product_qty' => 6, 'product_packaging_id' => $packaging->id]))
        ->toThrow(ValidationException::class);
    expect(issuedQuantityEffects())->toBe($before);
});

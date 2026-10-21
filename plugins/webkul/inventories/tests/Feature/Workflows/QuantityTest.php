<?php

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Webkul\Inventory\Enums\MoveState;
use Webkul\Inventory\Models\Move;
use Webkul\Inventory\Models\ProductQuantity;

require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../Helpers/InventoryHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('inventories');

    InventoryHelper::actingAsAdmin();

    $this->warehouse = InventoryHelper::warehouse();
    $this->product = InventoryHelper::product();
    $this->stock = $this->warehouse->lotStockLocation;
    $this->adjustment = InventoryHelper::inventoryAdjustmentLocation();
});

function adjustmentMovesFor($product)
{
    return Move::query()
        ->where('is_inventory', true)
        ->where('product_id', $product->id)
        ->get();
}

it('records an inventory move from the adjustment location into stock on a positive adjustment', function () {
    $quant = InventoryHelper::stockUp($this->product, $this->stock, 0);

    InventoryHelper::applyInventoryAdjustment($quant, 10);

    $moves = adjustmentMovesFor($this->product);

    expect($moves)->toHaveCount(1);

    $move = $moves->first();

    expect($move->is_inventory)->toBeTrue()
        ->and($move->state)->toBe(MoveState::DONE)
        ->and($move->is_picked)->toBeTrue()
        ->and((float) $move->quantity)->toBe(10.0)
        ->and($move->source_location_id)->toBe($this->adjustment->id)
        ->and($move->destination_location_id)->toBe($this->stock->id)
        ->and($move->lines)->toHaveCount(1);
});

it('records an inventory move from stock into the adjustment location on a negative adjustment', function () {
    $quant = InventoryHelper::stockUp($this->product, $this->stock, 10);

    InventoryHelper::applyInventoryAdjustment($quant, 4);

    $move = adjustmentMovesFor($this->product)->sortByDesc('id')->first();

    expect((float) $move->quantity)->toBe(6.0)
        ->and($move->source_location_id)->toBe($this->stock->id)
        ->and($move->destination_location_id)->toBe($this->adjustment->id)
        ->and($move->is_inventory)->toBeTrue();
});

it('resets the inventory difference quantity to zero after applying', function () {
    $quant = InventoryHelper::stockUp($this->product, $this->stock, 4);

    InventoryHelper::applyInventoryAdjustment($quant, 10);

    expect((float) $quant->refresh()->inventory_diff_quantity)->toBe(0.0)
        ->and($quant->inventory_quantity_set)->toBeFalse();
});

it('creates no inventory move when the applied quantity matches the current quantity', function () {
    $quant = InventoryHelper::stockUp($this->product, $this->stock, 10);

    InventoryHelper::applyInventoryAdjustment($quant, 10);

    expect(adjustmentMovesFor($this->product))->toHaveCount(0);
});

it('does not create an inventory move when a quant is simply stocked up', function () {
    InventoryHelper::stockUp($this->product, $this->stock, 10);

    expect(adjustmentMovesFor($this->product))->toHaveCount(0);
});

it('leaves the counterpart quantity at the adjustment location', function () {
    $quant = InventoryHelper::stockUp($this->product, $this->stock, 4);

    InventoryHelper::applyInventoryAdjustment($quant, 10);

    expect(InventoryHelper::onHand($this->product, $this->stock))->toBe(10.0)
        ->and(InventoryHelper::onHand($this->product, $this->adjustment))->toBeLessThan(0.0);
});

it('records a history move when inventory is added from the product', function () {
    $quant = InventoryHelper::addProductQuantity($this->product, $this->stock, 10);

    $moves = adjustmentMovesFor($this->product);

    expect($moves)->toHaveCount(1);

    $move = $moves->first();

    expect((float) $move->quantity)->toBe(10.0)
        ->and($move->is_inventory)->toBeTrue()
        ->and($move->source_location_id)->toBe($this->adjustment->id)
        ->and($move->destination_location_id)->toBe($this->stock->id)
        ->and($move->lines)->toHaveCount(1);

    expect(InventoryHelper::onHand($this->product, $this->stock))->toBe(10.0)
        ->and((float) $quant->refresh()->inventory_diff_quantity)->toBe(0.0);
});

it('records an adjustment move when the on hand quantity is edited from the product', function () {
    $quant = InventoryHelper::addProductQuantity($this->product, $this->stock, 10);

    InventoryHelper::editProductQuantity($quant->refresh(), 15);

    $moves = adjustmentMovesFor($this->product)->sortBy('id')->values();

    expect($moves)->toHaveCount(2)
        ->and((float) $moves->last()->quantity)->toBe(5.0)
        ->and($moves->last()->source_location_id)->toBe($this->adjustment->id)
        ->and($moves->last()->destination_location_id)->toBe($this->stock->id);

    expect(InventoryHelper::onHand($this->product, $this->stock))->toBe(15.0);
});

it('records a reverse adjustment move when the on hand quantity is reduced from the product', function () {
    $quant = InventoryHelper::addProductQuantity($this->product, $this->stock, 10);

    InventoryHelper::editProductQuantity($quant->refresh(), 4);

    $move = adjustmentMovesFor($this->product)->sortByDesc('id')->first();

    expect((float) $move->quantity)->toBe(6.0)
        ->and($move->source_location_id)->toBe($this->stock->id)
        ->and($move->destination_location_id)->toBe($this->adjustment->id);

    expect(InventoryHelper::onHand($this->product, $this->stock))->toBe(4.0);
});

it('rejects a count made before another stock change', function () {
    $quant = InventoryHelper::stockUp($this->product, $this->stock, 10);

    $quant->update([
        'counted_quantity'        => 8,
        'inventory_diff_quantity' => -2,
        'inventory_quantity_set'  => true,
    ]);

    ProductQuantity::applyQuantityDelta($this->product, $this->stock, 3);

    expect(fn () => $quant->refresh()->update([
        'quantity'               => 8,
        'counted_quantity'       => 0,
        'inventory_quantity_set' => false,
    ]))->toThrow(InvalidArgumentException::class);

    expect(InventoryHelper::onHand($this->product, $this->stock))->toBe(13.0)
        ->and(adjustmentMovesFor($this->product))->toHaveCount(0)
        ->and($quant->refresh()->inventory_quantity_set)->toBeTrue();
});

it('rolls back a count when adjustment history cannot be recorded', function () {
    $quant = InventoryHelper::stockUp($this->product, $this->stock, 10);
    $quant->update([
        'counted_quantity'        => 8,
        'inventory_diff_quantity' => -2,
        'inventory_quantity_set'  => true,
    ]);

    $dispatcher = Move::getEventDispatcher();
    Move::setEventDispatcher(clone $dispatcher);
    $productId = $this->product->id;
    Move::creating(function (Move $move) use ($productId): void {
        if ($move->is_inventory && $move->product_id === $productId) {
            throw new RuntimeException('Injected adjustment history failure.');
        }
    });

    try {
        expect(fn () => $quant->update([
            'quantity'               => 8,
            'counted_quantity'       => 0,
            'inventory_quantity_set' => false,
        ]))->toThrow(RuntimeException::class, 'Injected adjustment history failure.');

        expect(InventoryHelper::onHand($this->product, $this->stock))->toBe(10.0)
            ->and(adjustmentMovesFor($this->product))->toHaveCount(0)
            ->and($quant->refresh()->inventory_quantity_set)->toBeTrue();
    } finally {
        Move::setEventDispatcher($dispatcher);
    }
});

it('records a zero physical count as a full stock reduction', function () {
    $quant = InventoryHelper::stockUp($this->product, $this->stock, 10);
    $quant->update([
        'counted_quantity'        => 0,
        'inventory_diff_quantity' => -10,
        'inventory_quantity_set'  => true,
    ]);

    $quant->update([
        'quantity'               => 0,
        'counted_quantity'       => 0,
        'inventory_quantity_set' => false,
    ]);

    expect(InventoryHelper::onHand($this->product, $this->stock))->toBe(0.0)
        ->and(adjustmentMovesFor($this->product))->toHaveCount(1)
        ->and((float) adjustmentMovesFor($this->product)->first()->quantity)->toBe(10.0);
});

it('rejects a physical count captured before another count commits', function (): void {
    if (! config('testing.run_reconciliation_concurrency') || DB::connection()->getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requires the opted-in disposable PostgreSQL runner.');
    }

    $quant = InventoryHelper::stockUp($this->product, $this->stock, 10);
    $database = DB::connection()->getDatabaseName();
    expect($database)->toMatch('/^aureuserp_[A-Za-z0-9_]+_testing$/');

    while (DB::connection()->transactionLevel() > 0) {
        DB::connection()->commit();
    }

    $directory = sys_get_temp_dir().'/count-race-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Auth::loginUsingId((int) $argv[5]);
$connection = Illuminate\Support\Facades\DB::connection();
$pid = $connection->selectOne('SELECT pg_backend_pid() AS id')->id;
$quant = Webkul\Inventory\Models\ProductQuantity::findOrFail((int) $argv[4]);
if ($argv[3] === '1') {
    $quant->update([
        'counted_quantity' => 8,
        'inventory_diff_quantity' => -2,
        'inventory_quantity_set' => true,
    ]);
    Webkul\Inventory\Models\Move::creating(function () use ($argv): void {
        touch($argv[2].'/holding');
        $deadline = microtime(true) + 20;
        while (! file_exists($argv[2].'/release')) {
            if (microtime(true) > $deadline) { throw new RuntimeException('Count lock release timed out.'); }
            usleep(10000);
        }
    });
}
file_put_contents($argv[2].'/ready-'.$argv[3], (string) $pid);
try {
    if ($argv[3] === '1') {
        $quant->update([
            'quantity' => 8,
            'counted_quantity' => 0,
            'inventory_quantity_set' => false,
        ]);
    } else {
        $quant->update([
            'counted_quantity' => 7,
            'inventory_diff_quantity' => -3,
            'inventory_quantity_set' => true,
        ]);
    }
    echo json_encode(['result' => 'accepted', 'pid' => $pid]);
} catch (Throwable $exception) {
    echo json_encode(['result' => 'rejected', 'error' => $exception->getMessage(), 'pid' => $pid]);
}
PHP;
    $waitFor = function (Closure $condition): void {
        $deadline = microtime(true) + 20;
        while (! $condition()) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Count worker synchronization timed out.');
            }

            usleep(10000);
        }
    };
    $processes = [];

    try {
        foreach ([1, 2] as $number) {
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=512M', '-r', $worker, base_path(), $directory, (string) $number, (string) $quant->id, (string) auth()->id()], base_path(), [...$_ENV, 'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => config('database.connections.pgsql.host'), 'DB_PORT' => (string) config('database.connections.pgsql.port'), 'DB_USERNAME' => config('database.connections.pgsql.username'), 'DB_PASSWORD' => config('database.connections.pgsql.password'), 'DB_DATABASE' => $database], timeout: 30);
            $processes[] = $process;
            $process->start();
            $waitFor(fn (): bool => file_exists($directory.'/ready-'.$number));

            if ($number === 1) {
                $waitFor(fn (): bool => file_exists($directory.'/holding'));
            }
        }

        $firstPid = (int) file_get_contents($directory.'/ready-1');
        $secondPid = (int) file_get_contents($directory.'/ready-2');
        $waitFor(fn (): bool => (bool) DB::selectOne('SELECT ? = ANY(pg_blocking_pids(?)) AS blocked', [$firstPid, $secondPid])->blocked);
        touch($directory.'/release');

        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
            $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }

        expect($results[0]['result'])->toBe('accepted')
            ->and($results[1]['result'])->toBe('rejected')
            ->and($results[1]['error'])->toContain('Stock changed after this count was entered.')
            ->and((float) $quant->fresh()->quantity)->toBe(8.0)
            ->and($quant->fresh()->inventory_quantity_set)->toBeFalse()
            ->and(adjustmentMovesFor($this->product))->toHaveCount(1);
    } finally {
        touch($directory.'/release');
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        foreach (glob($directory.'/*') as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
});

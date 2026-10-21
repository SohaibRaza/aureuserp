<?php

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Webkul\Inventory\Enums\MoveState;
use Webkul\Inventory\Enums\ScrapState;
use Webkul\Inventory\Facades\Inventory;
use Webkul\Inventory\Models\Move;
use Webkul\Inventory\Models\OperationResult;
use Webkul\Inventory\Models\ProductQuantity;

require_once __DIR__.'/../../Helpers/InventoryHelper.php';

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('inventories');
    InventoryHelper::actingAsAdmin();
    $this->warehouse = InventoryHelper::warehouse();
    $this->location = $this->warehouse->lotStockLocation;
    $this->product = InventoryHelper::product();
    $this->stock = InventoryHelper::stockUp($this->product, $this->location, 100);
});

it('rolls back a stock delta and callback writes when a callback fails', function (): void {
    $dispatcher = ProductQuantity::getEventDispatcher();
    ProductQuantity::setEventDispatcher(clone $dispatcher);
    $stockId = $this->stock->id;
    $location = $this->location;
    $originalName = $location->name;

    ProductQuantity::updated(function (ProductQuantity $stock) use ($stockId, $location): void {
        if ($stock->id === $stockId) {
            $location->updateQuietly(['name' => 'Callback mutation']);
            throw new RuntimeException('Injected callback failure');
        }
    });

    try {
        expect(fn () => ProductQuantity::applyQuantityDelta($this->product, $location, 10))
            ->toThrow(RuntimeException::class, 'Injected callback failure');
        expect((float) $this->stock->fresh()->quantity)->toBe(100.0)
            ->and($location->fresh()->name)->toBe($originalName);
    } finally {
        ProductQuantity::setEventDispatcher($dispatcher);
    }
});

it('protects stock identity, reservation bounds and scrap completion on independent contending database sessions', function (string $scenario): void {
    if (! config('testing.run_reconciliation_concurrency') || DB::connection()->getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requires the opted-in disposable PostgreSQL runner.');
    }
    $database = DB::connection()->getDatabaseName();
    expect($database)->toMatch('/^aureuserp_[A-Za-z0-9_]+_testing$/');
    $moves = collect();
    $scraps = collect();
    $scrapLocation = null;
    if (str_starts_with($scenario, 'scrap-')) {
        $scrapLocation = InventoryHelper::scrapLocation();
        $scrap = InventoryHelper::scrap($this->product, $this->location, $scrapLocation, 60);
        $scraps->push($scrap);
        $scraps->push($scenario === 'scrap-competing'
            ? InventoryHelper::scrap($this->product, $this->location, $scrapLocation, 60)
            : $scrap);
    }
    if ($scenario === 'planned-reservation') {
        foreach ([1, 2] as $number) {
            $move = InventoryHelper::delivery($this->warehouse, [[$this->product, 60]])->moves->first();
            $move->update(['state' => MoveState::CONFIRMED]);
            $moves->push($move);
        }
    }
    if ($scenario === 'count-before-controlled-issue') {
        $delivery = InventoryHelper::delivery($this->warehouse, [[$this->product, 100]]);
        Inventory::confirmTransfer($delivery);
        $moves->push($delivery->refresh()->moves->sole());
    }
    if ($scenario === 'missing') {
        $this->stock->delete();
    }
    while (DB::connection()->transactionLevel() > 0) {
        DB::connection()->commit();
    }
    $directory = sys_get_temp_dir().'/stock-delta-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Auth::loginUsingId((int) $argv[5]);
$connection = Illuminate\Support\Facades\DB::connection();
$pid = $connection->selectOne('SELECT pg_backend_pid() AS id')->id;
file_put_contents($argv[2].'/pid-'.$argv[3], (string) $pid);
$product = Webkul\Inventory\Models\Product::findOrFail((int) $argv[7]);
$location = Webkul\Inventory\Models\Location::findOrFail((int) $argv[8]);
if ($argv[3] === '1') {
    $mutationFirst = str_ends_with($argv[6], '-first');
    $model = $mutationFirst ? Webkul\Inventory\Models\Scrap::class : Webkul\Inventory\Models\ProductQuantity::class;
    $event = $mutationFirst && $argv[6] === 'scrap-delete-first' ? 'deleting' : ($argv[6] === 'missing' ? 'creating' : 'updating');
    $model::$event(function ($record) use ($argv): void {
        if ($argv[6] === 'count-before-controlled-issue' && $record->inventory_quantity_set) {
            return;
        }
        touch($argv[2].'/holding');
        $deadline = microtime(true) + 20;
        while (! file_exists($argv[2].'/release')) {
            if (microtime(true) > $deadline) { throw new RuntimeException('Release timeout'); }
            usleep(10000);
        }
    });
}
try {
    $ok = true;
    if (str_starts_with($argv[6], 'scrap-')) {
        $scrap = Webkul\Inventory\Models\Scrap::findOrFail((int) $argv[10 + (int) $argv[3]]);
        $mutation = in_array($argv[6], ['scrap-update', 'scrap-delete', 'scrap-update-first', 'scrap-delete-first', 'count-before-controlled-issue']);
        if ($mutation && (str_ends_with($argv[6], '-first') ? $argv[3] === '1' : $argv[3] === '2')) {
            $ok = str_contains($argv[6], 'delete') ? $scrap->delete() : $scrap->update(['qty' => 30]);
        } else {
            $ok = $scrap->validate();
        }
    } elseif ($argv[6] === 'count-before-controlled-issue') {
        if ($argv[3] === '1') {
            require_once $argv[1].'/plugins/webkul/inventories/tests/Helpers/InventoryHelper.php';
            InventoryHelper::applyInventoryAdjustment(Webkul\Inventory\Models\ProductQuantity::findOrFail((int) $argv[4]), 40);
        } else {
            Illuminate\Support\Facades\DB::transaction(function () use ($argv): void {
                $move = Webkul\Inventory\Models\Move::findOrFail((int) $argv[9]);
                app(Webkul\Inventory\Services\MoveCompleter::class)->assertReservedStockAvailable(collect([$move]));
                Webkul\Inventory\Facades\Inventory::completeTransfer($move->operation);
            });
        }
    } elseif ($argv[6] === 'planned-reservation') {
        $move = Webkul\Inventory\Models\Move::findOrFail((int) $argv[8 + (int) $argv[3]]);
        app(Webkul\Inventory\Services\MoveReserver::class)->reserve(collect([$move]));
    } elseif ($argv[6] === 'reservation') {
        Webkul\Inventory\Models\ProductQuantity::applyReservationDelta($product, $location, 60);
    } else {
        Webkul\Inventory\Models\ProductQuantity::applyQuantityDelta($product, $location, (int) $argv[3] * 10);
    }
} catch (InvalidArgumentException | Illuminate\Validation\ValidationException | Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
    $ok = false;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL.$exception->getTraceAsString());
    exit(1);
}
echo json_encode(['pid' => $pid, 'database' => $connection->getDatabaseName(), 'ok' => $ok]);
PHP;
    $processes = [];
    $waitFor = function (Closure $condition): void {
        $deadline = microtime(true) + 20;
        while (! $condition()) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Stock worker synchronization timed out');
            }
            usleep(10000);
        }
    };
    try {
        foreach ([1, 2] as $number) {
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=512M', '-r', $worker, base_path(), $directory, (string) $number, (string) $this->stock->id, (string) auth()->id(), $scenario, (string) $this->product->id, (string) $this->location->id, (string) $moves->get(0)?->id, (string) $moves->get(1)?->id, (string) $scraps->get(0)?->id, (string) $scraps->get(1)?->id], base_path(), [...$_ENV, 'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => config('database.connections.pgsql.host'), 'DB_PORT' => (string) config('database.connections.pgsql.port'), 'DB_USERNAME' => config('database.connections.pgsql.username'), 'DB_PASSWORD' => config('database.connections.pgsql.password'), 'DB_DATABASE' => $database, 'AUREUSERP_TEST_REUSE_DATABASE' => '1'], timeout: 30);
            $processes[] = $process;
            $process->start();
            $waitFor(fn () => file_exists($directory.'/pid-'.$number));
            if ($number === 1) {
                $waitFor(fn () => file_exists($directory.'/holding'));
            }
        }
        $secondPid = (int) file_get_contents($directory.'/pid-2');
        $contended = false;
        $waitFor(function () use ($secondPid, $processes, &$contended): bool {
            $contended = (bool) DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE pid = ? AND wait_event_type = 'Lock') AS blocked", [$secondPid])->blocked;

            return $contended || ! $processes[1]->isRunning();
        });
        touch($directory.'/release');
        $identities = [];
        $outcomes = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
            $identity = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            expect($identity['database'])->toBe($database);
            $identities[] = $identity['pid'];
            $outcomes[] = $identity['ok'];
        }
        $stocks = ProductQuantity::query()->where('product_id', $this->product->id)->where('location_id', $this->location->id)->get();
        expect(array_unique($identities))->toHaveCount(2)
            ->and($stocks)->toHaveCount(1)
            ->and((float) $stocks->sum('quantity'))->toBe(match ($scenario) {
                'missing'                                                                                          => 30.0, 'reservation', 'planned-reservation', 'scrap-delete-first' => 100.0, 'scrap-update-first' => 70.0,
                'scrap-replay', 'scrap-competing', 'scrap-update', 'scrap-delete', 'count-before-controlled-issue' => 40.0, default => 130.0
            })
            ->and((float) $stocks->sum('reserved_quantity'))->toBe(match ($scenario) {
                'reservation' => 60.0, 'planned-reservation', 'count-before-controlled-issue' => 100.0, default => 0.0
            })
            ->and($outcomes)->toBe(in_array($scenario, ['reservation', 'scrap-replay', 'scrap-competing', 'scrap-update', 'scrap-delete', 'scrap-delete-first', 'count-before-controlled-issue']) ? [true, false] : [true, true])
            ->and($contended)->toBeTrue();
        if ($scenario === 'count-before-controlled-issue') {
            expect($moves->first()->fresh()->state)->not->toBe(MoveState::DONE)
                ->and(OperationResult::query()->where('operation_id', $moves->first()->operation_id)->exists())->toBeFalse();
        }
        if ($scenario === 'planned-reservation') {
            expect($moves->map(fn ($move) => (float) $move->fresh()->quantity)->all())->toBe([60.0, 40.0]);
        }
        if ($scraps->isNotEmpty()) {
            $quantity = match ($scenario) {
                'scrap-update-first' => 30.0, 'scrap-delete-first' => 0.0, default => 60.0,
            };
            expect(InventoryHelper::onHand($this->product, $scrapLocation))->toBe($quantity)
                ->and(Move::query()->whereIn('scrap_id', $scraps->pluck('id'))->count())->toBe($scenario === 'scrap-delete-first' ? 0 : 1)
                ->and($scraps->map(fn ($scrap) => $scrap->fresh()?->state)->all())->toBe(match ($scenario) {
                    'scrap-competing'    => [ScrapState::DONE, ScrapState::DRAFT],
                    'scrap-delete-first' => [null, null],
                    default              => [ScrapState::DONE, ScrapState::DONE],
                });
            if ($scenario !== 'scrap-delete-first') {
                $move = Move::query()->where('scrap_id', $scraps->first()->id)->firstOrFail();
                expect((float) $scraps->first()->fresh()->qty)->toBe($quantity)
                    ->and((float) $move->quantity)->toBe($quantity)
                    ->and((float) $move->lines()->firstOrFail()->qty)->toBe($quantity);
            }
        }
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
})->with(['existing', 'missing', 'reservation', 'planned-reservation', 'scrap-replay', 'scrap-competing', 'scrap-update', 'scrap-delete', 'scrap-update-first', 'scrap-delete-first', 'count-before-controlled-issue']);

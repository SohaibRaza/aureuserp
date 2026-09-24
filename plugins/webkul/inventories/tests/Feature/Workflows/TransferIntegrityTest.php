<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Process\Process;
use Webkul\Inventory\Enums\DeliveryStep;
use Webkul\Inventory\Enums\MoveState;
use Webkul\Inventory\Enums\OperationState;
use Webkul\Inventory\Enums\ReceptionStep;
use Webkul\Inventory\Enums\ReservationMethod;
use Webkul\Inventory\Events\OperationCanceled;
use Webkul\Inventory\Events\OperationDone;
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

it('rolls back source reservation and issue when destination stock creation fails', function (): void {
    $warehouse = InventoryHelper::warehouse();
    $product = InventoryHelper::product();
    InventoryHelper::stockUp($product, $warehouse->lotStockLocation, 10);
    $delivery = InventoryHelper::delivery($warehouse, [[$product, 10]]);
    Inventory::confirmTransfer($delivery);
    $before = phase2aReturnEffects($delivery);
    $dispatcher = ProductQuantity::getEventDispatcher();
    ProductQuantity::setEventDispatcher(clone $dispatcher);
    ProductQuantity::creating(function (ProductQuantity $stock) use ($delivery): void {
        if ($stock->location_id === $delivery->destination_location_id) {
            throw new RuntimeException('Destination write failed.');
        }
    });

    try {
        expect(fn () => Inventory::completeTransfer($delivery->refresh()))
            ->toThrow(RuntimeException::class, 'Destination write failed.');
        expect(phase2aReturnEffects($delivery))->toBe($before);
    } finally {
        ProductQuantity::setEventDispatcher($dispatcher);
    }
});

it('rolls back partial completion and backorder when a completion listener fails', function (): void {
    $warehouse = InventoryHelper::warehouse();
    $product = InventoryHelper::product();
    InventoryHelper::stockUp($product, $warehouse->lotStockLocation, 6);
    $delivery = InventoryHelper::delivery($warehouse, [[$product, 10]]);
    Inventory::confirmTransfer($delivery);
    $before = phase2aReturnEffects($delivery);
    Event::listen(OperationDone::class, static function (): void {
        throw new RuntimeException('Completion listener failed.');
    });

    expect(fn () => Inventory::completeTransfer($delivery->refresh()))
        ->toThrow(RuntimeException::class, 'Completion listener failed.');
    expect(phase2aReturnEffects($delivery))->toBe($before)
        ->and($delivery->fresh()->closed_at)->toBeNull();
});

it('rolls back cancellation and reservations when a cancellation listener fails', function (): void {
    $warehouse = InventoryHelper::warehouse();
    $product = InventoryHelper::product();
    InventoryHelper::stockUp($product, $warehouse->lotStockLocation, 10);
    $delivery = InventoryHelper::delivery($warehouse, [[$product, 10]]);
    Inventory::confirmTransfer($delivery);
    $before = phase2aReturnEffects($delivery);
    Event::listen(OperationCanceled::class, static function (): void {
        throw new RuntimeException('Cancellation listener failed.');
    });

    expect(fn () => Inventory::cancelTransfer($delivery->refresh()))
        ->toThrow(RuntimeException::class, 'Cancellation listener failed.');
    expect(phase2aReturnEffects($delivery))->toBe($before);
});

it('does not issue stock twice when a stale transfer instance is completed again', function (): void {
    $warehouse = InventoryHelper::warehouse();
    $product = InventoryHelper::product();
    InventoryHelper::stockUp($product, $warehouse->lotStockLocation, 20);
    $delivery = InventoryHelper::delivery($warehouse, [[$product, 10]]);
    Inventory::confirmTransfer($delivery);
    $stale = $delivery->fresh()->load('moves.lines');
    Inventory::completeTransfer($delivery->refresh());
    $before = phase2aReturnEffects($delivery);

    try {
        Inventory::completeTransfer($stale);
    } catch (Exception) {
        // A terminal-state rejection is allowed, but it must not apply another stock effect.
    }

    expect(phase2aReturnEffects($delivery))->toBe($before);
});

it('rolls back an entire caller departure batch when its second transfer fails', function (): void {
    $warehouse = InventoryHelper::warehouse();
    $product = InventoryHelper::product();
    InventoryHelper::stockUp($product, $warehouse->lotStockLocation, 20);
    $first = InventoryHelper::delivery($warehouse, [[$product, 10]]);
    $second = InventoryHelper::delivery($warehouse, [[$product, 10]]);
    Inventory::confirmTransfer($first);
    Inventory::confirmTransfer($second);
    $before = phase2aReturnEffects($first);
    Event::listen(OperationDone::class, static function (OperationDone $event) use ($second): void {
        if ($event->operation->id === $second->id) {
            throw new RuntimeException('Second transfer failed.');
        }
    });

    expect(fn () => DB::transaction(function () use ($first, $second): void {
        Inventory::completeTransfer($first);
        Inventory::completeTransfer($second);
    }))->toThrow(RuntimeException::class, 'Second transfer failed.');
    expect(phase2aReturnEffects($first))->toBe($before)
        ->and($first->fresh()->closed_at)->toBeNull()
        ->and($second->fresh()->closed_at)->toBeNull();
});

it('serializes competing transfer completion and cancellation with one terminal result', function (string $winner): void {
    if (! config('testing.run_reconciliation_concurrency') || DB::connection()->getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requires the opted-in disposable PostgreSQL runner.');
    }

    $warehouse = InventoryHelper::warehouse();
    $product = InventoryHelper::product();
    InventoryHelper::stockUp($product, $warehouse->lotStockLocation, 10);
    $delivery = InventoryHelper::delivery($warehouse, [[$product, 10]]);
    Inventory::confirmTransfer($delivery);
    $database = DB::connection()->getDatabaseName();
    expect($database)->toMatch('/^aureuserp_[A-Za-z0-9_]+_testing$/');
    while (DB::connection()->transactionLevel() > 0) {
        DB::connection()->commit();
    }
    $directory = sys_get_temp_dir().'/transfer-race-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Auth::loginUsingId((int) $argv[5]);
$connection = Illuminate\Support\Facades\DB::connection();
$pid = $connection->selectOne('SELECT pg_backend_pid() AS id')->id;
$operation = Webkul\Inventory\Models\Operation::findOrFail((int) $argv[4])->load('moves.lines');
file_put_contents($argv[2].'/pid-'.$argv[3], (string) $pid);
if ($argv[3] === '1') {
    $event = $argv[6] === 'complete' ? Webkul\Inventory\Events\OperationDone::class : Webkul\Inventory\Events\OperationCanceled::class;
    Illuminate\Support\Facades\Event::listen($event, function () use ($argv): void {
        touch($argv[2].'/holding');
        $deadline = microtime(true) + 20;
        while (! file_exists($argv[2].'/release')) {
            if (microtime(true) > $deadline) { throw new RuntimeException('Release timeout'); }
            usleep(10000);
        }
    });
}
try {
    if ($argv[6] === 'complete') {
        Webkul\Inventory\Facades\Inventory::completeTransfer($operation);
    } else {
        Webkul\Inventory\Facades\Inventory::cancelTransfer($operation);
    }
    $result = 'accepted';
} catch (Throwable $exception) {
    $result = 'rejected';
    $error = $exception->getMessage();
}
echo json_encode(['pid' => $pid, 'database' => $connection->getDatabaseName(), 'result' => $result, 'error' => $error ?? null]);
PHP;
    $waitFor = function (Closure $condition): void {
        $deadline = microtime(true) + 20;
        while (! $condition()) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Transfer worker synchronization timed out.');
            }
            usleep(10000);
        }
    };
    $processes = [];
    try {
        foreach ([1 => $winner, 2 => $winner === 'complete' ? 'cancel' : 'complete'] as $number => $action) {
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=512M', '-r', $worker, base_path(), $directory, (string) $number, (string) $delivery->id, (string) auth()->id(), $action], base_path(), [...$_ENV, 'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => config('database.connections.pgsql.host'), 'DB_PORT' => (string) config('database.connections.pgsql.port'), 'DB_USERNAME' => config('database.connections.pgsql.username'), 'DB_PASSWORD' => config('database.connections.pgsql.password'), 'DB_DATABASE' => $database], timeout: 30);
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
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            expect($result['database'])->toBe($database);
            $results[] = $result;
        }
        expect($contended)->toBeTrue()
            ->and($results[0]['pid'])->not->toBe($results[1]['pid'])
            ->and($results[0]['result'])->toBe('accepted')
            ->and($results[1]['result'])->toBe('rejected')
            ->and($delivery->fresh()->state)->toBe($winner === 'complete' ? OperationState::DONE : OperationState::CANCELED)
            ->and(InventoryHelper::onHand($product, $warehouse->lotStockLocation))->toBe($winner === 'complete' ? 0.0 : 10.0)
            ->and(InventoryHelper::onHand($product, $delivery->destinationLocation))->toBe($winner === 'complete' ? 10.0 : 0.0)
            ->and(InventoryHelper::reserved($product, $warehouse->lotStockLocation))->toBe(0.0);
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
})->with(['completion wins' => 'complete', 'cancellation wins' => 'cancel']);

it('surfaces a downstream product lock deadlock without partial receipt effects', function (): void {
    if (! config('testing.run_reconciliation_concurrency') || DB::connection()->getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requires the opted-in disposable PostgreSQL runner.');
    }

    $warehouse = InventoryHelper::warehouse();
    InventoryHelper::setReservationMethod($warehouse->outType, ReservationMethod::AT_CONFIRM);
    $firstProduct = InventoryHelper::product();
    $secondProduct = InventoryHelper::product();
    InventoryHelper::stockUp($firstProduct, $warehouse->lotStockLocation, 5);
    $delivery = InventoryHelper::delivery($warehouse, [[$firstProduct, 5], [$secondProduct, 5]]);
    Inventory::confirmTransfer($delivery);
    $receipt = InventoryHelper::receipt($warehouse, [[$secondProduct, 5]]);
    Inventory::confirmTransfer($receipt);
    $before = phase2aReturnEffects($receipt);
    $receiptLinesBefore = $receipt->moves->sole()->lines()->orderBy('id')->get()->toArray();
    $database = DB::connection()->getDatabaseName();
    expect($database)->toMatch('/^aureuserp_[A-Za-z0-9_]+_testing$/')
        ->and($firstProduct->id)->toBeLessThan($secondProduct->id)
        ->and($delivery->fresh()->moves->firstWhere('product_id', $secondProduct->id)->state)->toBe(MoveState::CONFIRMED);
    while (DB::connection()->transactionLevel() > 0) {
        DB::connection()->commit();
    }
    $directory = sys_get_temp_dir().'/transfer-cycle-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Auth::loginUsingId((int) $argv[5]);
$connection = Illuminate\Support\Facades\DB::connection();
$connection->statement("SET statement_timeout = '15000ms'");
$pid = $connection->selectOne('SELECT pg_backend_pid() AS id')->id;
file_put_contents($argv[2].'/pid-'.$argv[3], (string) $pid);
$attempts = 0;
$stockWrites = 0;
$connection->listen(function ($query) use (&$stockWrites): void {
    if (preg_match('/^(insert into|update) "inventories_product_quantities"/i', $query->sql)) {
        $stockWrites++;
    }
});
try {
    $connection->transaction(function () use ($argv, &$attempts): void {
        $attempts++;
        // Reproduce the first product lock held by each command before its next lock acquisition.
        Webkul\Inventory\Models\Product::withTrashed()->whereKey((int) $argv[6])->lockForUpdate()->firstOrFail();
        touch($argv[2].'/holding-'.$argv[3]);
        if ($argv[3] === 'receipt') {
            $deadline = microtime(true) + 20;
            while (! file_exists($argv[2].'/release-receipt')) {
                if (microtime(true) > $deadline) { throw new RuntimeException('Release timeout'); }
                usleep(10000);
            }
        }
        $operation = Webkul\Inventory\Models\Operation::findOrFail((int) $argv[4]);
        if ($argv[3] === 'receipt') {
            Webkul\Inventory\Facades\Inventory::completeTransfer($operation);
        } else {
            Webkul\Inventory\Facades\Inventory::reserveTransfer($operation);
        }
    });
    $result = 'accepted';
} catch (Throwable $exception) {
    $result = 'rejected';
    $error = $exception->getMessage();
}
echo json_encode(['pid' => $pid, 'database' => $connection->getDatabaseName(), 'result' => $result, 'error' => $error ?? null, 'attempts' => $attempts, 'stock_writes' => $stockWrites, 'transaction_level' => $connection->transactionLevel()]);
PHP;
    $waitFor = function (Closure $condition): void {
        $deadline = microtime(true) + 20;
        while (! $condition()) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Downstream lock-cycle synchronization timed out.');
            }
            usleep(10000);
        }
    };
    $processes = [];
    try {
        foreach (['receipt' => [$receipt, $secondProduct], 'delivery' => [$delivery, $firstProduct]] as $name => [$operation, $product]) {
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=512M', '-r', $worker, base_path(), $directory, $name, (string) $operation->id, (string) auth()->id(), (string) $product->id], base_path(), [...$_ENV, 'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => config('database.connections.pgsql.host'), 'DB_PORT' => (string) config('database.connections.pgsql.port'), 'DB_USERNAME' => config('database.connections.pgsql.username'), 'DB_PASSWORD' => config('database.connections.pgsql.password'), 'DB_DATABASE' => $database], timeout: 35);
            $processes[$name] = $process;
            $process->start();
            $waitFor(fn () => file_exists($directory.'/holding-'.$name) || ! $process->isRunning());
            expect($process->isRunning())->toBeTrue($process->getErrorOutput().$process->getOutput());
        }
        $deliveryPid = (int) file_get_contents($directory.'/pid-delivery');
        $receiptPid = (int) file_get_contents($directory.'/pid-receipt');
        $waitFor(fn () => (bool) DB::selectOne('SELECT ? = ANY(pg_blocking_pids(?)) AS blocked', [$receiptPid, $deliveryPid])->blocked);
        touch($directory.'/release-receipt');
        $results = [];
        foreach ($processes as $name => $process) {
            $process->wait();
            expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
            $results[$name] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            expect($results[$name]['database'])->toBe($database)
                ->and($results[$name]['attempts'])->toBe(1)
                ->and($results[$name]['transaction_level'])->toBe(0);
        }
        $rejected = array_values(array_filter($results, fn (array $result): bool => $result['result'] === 'rejected'));
        expect($receiptPid)->not->toBe($deliveryPid)
            ->and($rejected)->toHaveCount(1)
            ->and($rejected[0]['error'])->toContain('deadlock detected')
            ->and($results['receipt']['stock_writes'])->toBeGreaterThan(0);
        $received = $results['receipt']['result'] === 'accepted';
        expect($receipt->fresh()->state)->toBe($received ? OperationState::DONE : OperationState::ASSIGNED)
            ->and($receipt->fresh()->closed_at !== null)->toBe($received)
            ->and($delivery->fresh()->state)->toBeIn([OperationState::CONFIRMED, OperationState::ASSIGNED])
            ->and($delivery->fresh()->closed_at)->toBeNull()
            ->and(InventoryHelper::onHand($firstProduct, $warehouse->lotStockLocation))->toBe(5.0)
            ->and(InventoryHelper::reserved($firstProduct, $warehouse->lotStockLocation))->toBe(5.0)
            ->and(InventoryHelper::onHand($secondProduct, $warehouse->lotStockLocation))->toBe($received ? 5.0 : 0.0)
            ->and(InventoryHelper::reserved($secondProduct, $warehouse->lotStockLocation))->toBe($received ? 5.0 : 0.0)
            ->and(InventoryHelper::onHand($secondProduct, $receipt->sourceLocation))->toBe($received ? -5.0 : 0.0)
            ->and(InventoryHelper::onHand($firstProduct, $delivery->destinationLocation))->toBe(0.0)
            ->and(InventoryHelper::onHand($secondProduct, $delivery->destinationLocation))->toBe(0.0)
            ->and((float) ProductQuantity::query()->where('product_id', $secondProduct->id)->sum('quantity'))->toBe(0.0)
            ->and(Operation::query()->count())->toBe($before['operations'])
            ->and(Move::query()->count())->toBe($before['moves'])
            ->and($delivery->fresh()->moves->firstWhere('product_id', $secondProduct->id)->state)->toBe($received ? MoveState::ASSIGNED : MoveState::CONFIRMED);
        if (! $received) {
            expect(phase2aReturnEffects($receipt))->toBe($before)
                ->and($receipt->moves->sole()->lines()->orderBy('id')->get()->toArray())->toBe($receiptLinesBefore);
        }
    } finally {
        touch($directory.'/release-receipt');
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

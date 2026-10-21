<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Process\Process;
use Webkul\Inventory\Enums\MoveState;
use Webkul\Inventory\Enums\OperationState;
use Webkul\Inventory\Events\OperationReturned;
use Webkul\Inventory\Facades\Inventory;
use Webkul\Inventory\Models\Move;
use Webkul\Inventory\Models\MoveLine;
use Webkul\Inventory\Models\Operation;
use Webkul\Inventory\Models\ProductQuantity;
use Webkul\Inventory\Services\MoveCompleter;
use Webkul\Inventory\Services\MoveMerger;
use Webkul\Inventory\Services\MoveReserver;
use Webkul\Inventory\Services\OperationAssembler;
use Webkul\Inventory\Services\PushRuleRunner;
use Webkul\Inventory\Services\TransferWorkflow;
use Webkul\Inventory\Support\StockMutationGuard;
use Webkul\Inventory\Support\StockMutationPrecondition;

require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../Helpers/InventoryHelper.php';

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('inventories');
    InventoryHelper::actingAsAdmin();
});

function guardedDelivery(float $quantity = 10): array
{
    $warehouse = InventoryHelper::warehouse();
    $product = InventoryHelper::product();
    InventoryHelper::stockUp($product, $warehouse->lotStockLocation, $quantity);
    $operation = InventoryHelper::delivery($warehouse, [[$product, $quantity]]);
    Inventory::confirmTransfer($operation);

    return [$operation->fresh(), $product];
}

function denyStockMutation(string $deniedAction, int $operationId): object
{
    $guard = new class($deniedAction, $operationId) implements StockMutationPrecondition
    {
        public bool $seen = false;

        public function __construct(private string $deniedAction, private int $operationId) {}

        public function assertAllowed(string $action, Collection $operations, Collection $moves, array $changes): void
        {
            if ($action !== $this->deniedAction || ! $operations->contains('id', $this->operationId)) {
                return;
            }

            $this->seen = true;

            throw new RuntimeException('Denied stock mutation');
        }
    };

    app()->instance('test.stock-mutation-precondition', $guard);
    app()->tag(['test.stock-mutation-precondition'], StockMutationGuard::TAG);

    return $guard;
}

function stockMutationSnapshot(): array
{
    return [
        'operations' => Operation::query()->orderBy('id')->get(['id', 'origin', 'state', 'closed_at', 'destination_location_id'])->toArray(),
        'moves'      => Move::query()->orderBy('id')->get(['id', 'operation_id', 'state', 'procure_method', 'quantity', 'product_uom_qty', 'is_picked', 'destination_location_id'])->toArray(),
        'lines'      => MoveLine::query()->orderBy('id')->get(['id', 'move_id', 'operation_id', 'state', 'qty', 'is_picked', 'destination_location_id'])->toArray(),
        'stock'      => ProductQuantity::query()->orderBy('id')->get(['id', 'quantity', 'reserved_quantity'])->toArray(),
        'links'      => DB::table('inventories_move_destinations')->orderBy('origin_move_id')->orderBy('destination_move_id')->get()->map(fn (object $link): array => (array) $link)->toArray(),
        'chatter'    => DB::table('chatter_messages')->count(),
    ];
}

it('rolls back completion when a registered precondition denies the locked operation', function (): void {
    [$operation] = guardedDelivery();
    $before = stockMutationSnapshot();
    $guard = denyStockMutation('complete', $operation->id);

    expect(fn () => Inventory::completeTransfer($operation->fresh()))
        ->toThrow(RuntimeException::class, 'Denied stock mutation');

    expect($guard->seen)->toBeTrue()
        ->and(stockMutationSnapshot())->toBe($before)
        ->and($operation->fresh()->state)->not->toBe(OperationState::DONE);
});

it('rejects reserve and cancel before changing native stock or state', function (string $action): void {
    [$operation] = guardedDelivery();
    $before = stockMutationSnapshot();
    $guard = denyStockMutation($action, $operation->id);

    expect(fn () => $action === 'reserve'
        ? Inventory::reserveTransfer($operation)
        : Inventory::cancelTransfer($operation))
        ->toThrow(RuntimeException::class, 'Denied stock mutation');

    expect($guard->seen)->toBeTrue()
        ->and(stockMutationSnapshot())->toBe($before);
})->with(['reserve', 'cancel']);

it('rejects direct move and move-line edits and insertion into an existing operation', function (string $kind): void {
    [$operation] = guardedDelivery();
    $move = $operation->moves()->firstOrFail();
    $line = $move->lines()->firstOrFail();
    $action = match ($kind) {
        'move'        => 'move.update',
        'line'        => 'move_line.update',
        'insert'      => 'move.create',
        'move quiet'  => 'move.update',
        'line quiet'  => 'move_line.update',
        'line delete' => 'move_line.delete',
    };
    $before = stockMutationSnapshot();
    $guard = denyStockMutation($action, $operation->id);

    expect(fn () => match ($kind) {
        'move'        => $move->update(['product_uom_qty' => 4]),
        'line'        => $line->update(['qty' => 4]),
        'insert'      => $move->replicate()->fill(['quantity' => 0, 'state' => MoveState::DRAFT])->save(),
        'move quiet'  => $move->fill(['product_uom_qty' => 4])->saveQuietly(),
        'line quiet'  => $line->fill(['qty' => 4])->saveQuietly(),
        'line delete' => $line->delete(),
    })->toThrow(RuntimeException::class, 'Denied stock mutation');

    expect($guard->seen)->toBeTrue()
        ->and(stockMutationSnapshot())->toBe($before);
})->with(['move', 'line', 'insert', 'move quiet', 'line quiet', 'line delete']);

it('checks both old and new operation roots when a move or line is reparented', function (string $kind): void {
    [$source] = guardedDelivery();
    [$target] = guardedDelivery();
    $sourceMove = $source->moves()->firstOrFail();
    $targetMove = $target->moves()->firstOrFail();
    $sourceLine = $sourceMove->lines()->firstOrFail();
    $before = stockMutationSnapshot();
    $guard = denyStockMutation($kind === 'move' ? 'move.update' : 'move_line.update', $target->id);

    expect(fn () => $kind === 'move'
        ? $sourceMove->update(['operation_id' => $target->id])
        : $sourceLine->update([
            'move_id'      => $targetMove->id,
            'operation_id' => $target->id,
            'product_id'   => $targetMove->product_id,
        ]))
        ->toThrow(RuntimeException::class, 'Denied stock mutation');

    expect($guard->seen)->toBeTrue()
        ->and(stockMutationSnapshot())->toBe($before);
})->with(['move', 'line']);

it('rejects direct operation changes before cascading to moves', function (): void {
    [$operation] = guardedDelivery();
    $before = stockMutationSnapshot();
    $guard = denyStockMutation('operation.update', $operation->id);

    expect(fn () => $operation->update(['origin' => 'Changed origin']))
        ->toThrow(RuntimeException::class, 'Denied stock mutation');

    expect($guard->seen)->toBeTrue()
        ->and(stockMutationSnapshot())->toBe($before);
});

it('rejects direct backorder creation before moving source records', function (): void {
    [$operation] = guardedDelivery();
    $before = stockMutationSnapshot();
    $guard = denyStockMutation('backorder.create', $operation->id);

    expect(fn () => Inventory::createBackorder($operation))
        ->toThrow(RuntimeException::class, 'Denied stock mutation');

    expect($guard->seen)->toBeTrue()
        ->and(stockMutationSnapshot())->toBe($before);
});

it('rejects a direct split or line completion before modifying stock', function (string $kind): void {
    [$operation] = guardedDelivery();
    $move = $operation->moves()->firstOrFail();
    if ($kind === 'split') {
        $move->update(['quantity' => 5]);
    }
    $before = stockMutationSnapshot();
    $guard = denyStockMutation($kind === 'split' ? 'backorder.split' : 'complete.lines', $operation->id);

    expect(fn () => $kind === 'split'
        ? Inventory::splitShortMoves(collect([$move]))
        : app(MoveCompleter::class)->completeLines($move->lines()->get()))
        ->toThrow(RuntimeException::class, 'Denied stock mutation');

    expect($guard->seen)->toBeTrue()
        ->and(stockMutationSnapshot())->toBe($before);
})->with(['split', 'lines']);

it('rolls back a canceled source move when its downstream move is denied', function (): void {
    [$source, $product] = guardedDelivery();
    $warehouse = $source->operationType->warehouse;
    $target = InventoryHelper::delivery($warehouse, [[$product, 10]]);
    Inventory::confirmTransfer($target);
    $sourceMove = $source->moves()->firstOrFail();
    $targetMove = $target->moves()->firstOrFail();
    $sourceMove->moveDestinations()->attach($targetMove->id);
    $before = stockMutationSnapshot();
    $guard = denyStockMutation('move.update', $target->id);

    expect(fn () => Inventory::cancelMoves(collect([$sourceMove])))
        ->toThrow(RuntimeException::class, 'Denied stock mutation');

    expect($guard->seen)->toBeTrue()
        ->and(stockMutationSnapshot())->toBe($before);
});

it('rolls back the first operation when a later guard rejects the second', function (): void {
    [$first] = guardedDelivery();
    [$second] = guardedDelivery();
    $before = stockMutationSnapshot();
    $guard = denyStockMutation('complete', $second->id);

    expect(fn () => DB::transaction(function () use ($first, $second): void {
        Inventory::completeTransfer($first);
        Inventory::completeTransfer($second);
    }))->toThrow(RuntimeException::class, 'Denied stock mutation');

    expect($guard->seen)->toBeTrue()
        ->and(stockMutationSnapshot())->toBe($before);
});

it('keeps ordinary native completion working without a denying precondition', function (): void {
    [$operation, $product] = guardedDelivery();

    Inventory::completeTransfer($operation);

    expect($operation->fresh()->state)->toBe(OperationState::DONE)
        ->and(InventoryHelper::onHand($product, $operation->sourceLocation))->toBe(0.0)
        ->and(InventoryHelper::onHand($product, $operation->destinationLocation))->toBe(10.0);
});

it('keeps a caller pending demand change through confirmation', function (): void {
    $warehouse = InventoryHelper::warehouse();
    $product = InventoryHelper::product();
    InventoryHelper::stockUp($product, $warehouse->lotStockLocation, 10);
    $operation = InventoryHelper::delivery($warehouse, [[$product, 10]]);
    $move = $operation->moves()->firstOrFail();
    $move->product_uom_qty = 5;

    Inventory::confirmMoves(collect([$move]), merge: false);

    expect((float) $move->fresh()->product_uom_qty)->toBe(5.0);
});

it('passes locked current roots and proposed service changes separately', function (): void {
    $warehouse = InventoryHelper::warehouse();
    $product = InventoryHelper::product();
    $operation = InventoryHelper::delivery($warehouse, [[$product, 10]]);
    $move = $operation->moves()->firstOrFail();
    $move->product_uom_qty = 5;
    $guard = new class($move->id) implements StockMutationPrecondition
    {
        public bool $seen = false;

        public function __construct(private int $moveId) {}

        public function assertAllowed(string $action, Collection $operations, Collection $moves, array $changes): void
        {
            if ($action !== 'confirm') {
                return;
            }

            expect((float) $moves->firstWhere('id', $this->moveId)->product_uom_qty)->toBe(10.0)
                ->and((float) $changes['proposed_moves'][$this->moveId]['product_uom_qty'])->toBe(5.0);
            $this->seen = true;

            throw new RuntimeException('Denied stock mutation');
        }
    };
    app()->instance('test.stock-mutation-precondition', $guard);
    app()->tag(['test.stock-mutation-precondition'], StockMutationGuard::TAG);

    expect(fn () => Inventory::confirmMoves(collect([$move]), merge: false))
        ->toThrow(RuntimeException::class, 'Denied stock mutation');

    expect($guard->seen)->toBeTrue()
        ->and((float) $move->fresh()->product_uom_qty)->toBe(10.0);
});

it('retains pending move edits after denial and applies them on retry', function (string $entryPoint): void {
    $warehouse = InventoryHelper::warehouse();
    $product = InventoryHelper::product();
    $operation = InventoryHelper::delivery($warehouse, [[$product, 10]]);
    $move = $operation->moves()->firstOrFail();
    $move->product_uom_qty = 5;
    $attributes = $move->getAttributes();
    $original = $move->getRawOriginal();
    $guard = denyStockMutation($entryPoint === 'save' ? 'move.update' : 'confirm', $operation->id);

    $save = fn () => $entryPoint === 'save'
        ? $move->save()
        : Inventory::confirmMoves(collect([$move]), merge: false);

    expect($save)->toThrow(RuntimeException::class, 'Denied stock mutation');
    expect($guard->seen)->toBeTrue()
        ->and($move->getAttributes())->toBe($attributes)
        ->and($move->getRawOriginal())->toBe($original)
        ->and((float) $move->fresh()->product_uom_qty)->toBe(10.0);

    app()->instance('test.stock-mutation-precondition', new class implements StockMutationPrecondition
    {
        public function assertAllowed(string $action, Collection $operations, Collection $moves, array $changes): void {}
    });
    $save();

    expect((float) $move->fresh()->product_uom_qty)->toBe(5.0);
})->with(['save', 'confirm']);

it('rejects direct public mutations before changing records', function (string $action): void {
    [$operation] = guardedDelivery();
    Inventory::reserveTransfer($operation);
    $moves = $operation->moves()->get();
    $before = stockMutationSnapshot();
    $guard = denyStockMutation($action, $operation->id);

    expect(fn () => match ($action) {
        'move.delete'       => $moves->first()->delete(),
        'operation.delete'  => $operation->delete(),
        'release'           => app(MoveReserver::class)->release($moves),
        'merge'             => app(MoveMerger::class)->merge($moves),
        'assign_operations' => app(OperationAssembler::class)->assignMovesToOperations($moves),
        'push_rules'        => app(PushRuleRunner::class)->run($moves),
    })->toThrow(RuntimeException::class, 'Denied stock mutation');

    expect($guard->seen)->toBeTrue()
        ->and(stockMutationSnapshot())->toBe($before);
})->with(['move.delete', 'operation.delete', 'release', 'merge', 'assign_operations', 'push_rules']);

it('rejects a return before creating records or dispatching the return event', function (): void {
    [$operation] = guardedDelivery();
    Inventory::reserveTransfer($operation);
    Inventory::completeTransfer($operation);
    $move = $operation->moves()->firstOrFail();
    $before = stockMutationSnapshot();
    Event::fake([OperationReturned::class]);
    $guard = denyStockMutation('return', $operation->id);

    expect(fn () => app(TransferWorkflow::class)->createReturn($operation, [$move->id => 1]))
        ->toThrow(RuntimeException::class, 'Denied stock mutation');

    expect($guard->seen)->toBeTrue()
        ->and(stockMutationSnapshot())->toBe($before);
    Event::assertNotDispatched(OperationReturned::class);
});

it('avoids added model lock scans without consumers and enforces a later registration', function (): void {
    [$operation] = guardedDelivery();
    $move = $operation->moves()->firstOrFail();
    DB::connection()->enableQueryLog();

    try {
        DB::connection()->flushQueryLog();
        $operation->update(['origin' => 'Normal edit']);
        $move->update(['origin' => 'Normal edit']);
        $queries = collect(DB::connection()->getQueryLog())->pluck('query');
        expect($queries->contains(fn (string $query): bool => str_contains(strtolower($query), 'for update')))->toBeFalse()
            ->and($queries->contains(fn (string $query): bool => str_starts_with(strtolower($query), 'select "product_id" from "inventories_moves"')))->toBeFalse();
    } finally {
        DB::connection()->disableQueryLog();
    }

    denyStockMutation('move.update', $operation->id);
    expect(fn () => $move->update(['origin' => 'Blocked edit']))->toThrow(RuntimeException::class, 'Denied stock mutation');
    expect($move->fresh()->origin)->toBe('Normal edit');
});

it('rechecks a changed operation after waiting for another stock mutation transaction', function (): void {
    if (! config('testing.run_reconciliation_concurrency') || DB::connection()->getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requires the opted-in disposable PostgreSQL runner.');
    }

    [$operation, $product] = guardedDelivery();
    $database = DB::connection()->getDatabaseName();
    expect($database)->toMatch('/^aureuserp_[A-Za-z0-9_]+_testing$/');

    while (DB::connection()->transactionLevel() > 0) {
        DB::connection()->commit();
    }

    $directory = sys_get_temp_dir().'/stock-guard-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Auth::loginUsingId((int) $argv[5]);
$db = Illuminate\Support\Facades\DB::connection();
$operation = Webkul\Inventory\Models\Operation::findOrFail((int) $argv[3]);
file_put_contents($argv[2].'/pid-'.$argv[4], (string) $db->selectOne('SELECT pg_backend_pid() AS pid')->pid);
if ($argv[4] === 'hold') {
    $db->transaction(function () use ($operation, $argv): void {
        Webkul\Inventory\Models\Move::lockForStockMutation($operation->moves()->get(), additionalOperationIds: [$operation->id]);
        $operation->update(['origin' => 'claimed']);
        touch($argv[2].'/holding');
        $deadline = microtime(true) + 20;
        while (! file_exists($argv[2].'/release')) {
            if (microtime(true) > $deadline) { throw new RuntimeException('Release timeout'); }
            usleep(10000);
        }
    });
    echo json_encode(['updated' => true]);
} else {
    $guard = new class implements Webkul\Inventory\Support\StockMutationPrecondition {
        public function assertAllowed(string $action, Illuminate\Support\Collection $operations, Illuminate\Support\Collection $moves, array $changes): void {
            if ($action === 'complete' && $operations->contains(fn ($operation) => $operation->origin === 'claimed')) {
                throw new RuntimeException('Claimed operation denied');
            }
        }
    };
    app()->instance('test.concurrent-stock-guard', $guard);
    app()->tag(['test.concurrent-stock-guard'], Webkul\Inventory\Support\StockMutationGuard::TAG);
    try {
        app(Webkul\Inventory\Services\TransferWorkflow::class)->complete($operation);
        echo json_encode(['denied' => false]);
    } catch (RuntimeException $exception) {
        echo json_encode(['denied' => $exception->getMessage() === 'Claimed operation denied']);
    }
}
PHP;
    $environment = [...$_ENV,
        'APP_ENV'                       => 'testing',
        'DB_CONNECTION'                 => 'pgsql',
        'DB_HOST'                       => config('database.connections.pgsql.host'),
        'DB_PORT'                       => (string) config('database.connections.pgsql.port'),
        'DB_USERNAME'                   => config('database.connections.pgsql.username'),
        'DB_PASSWORD'                   => config('database.connections.pgsql.password'),
        'DB_DATABASE'                   => $database,
        'AUREUSERP_TEST_REUSE_DATABASE' => '1',
    ];
    $workers = [];
    $waitFor = function (callable $ready): void {
        $deadline = microtime(true) + 20;
        while (! $ready()) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Concurrent stock guard worker timed out.');
            }
            usleep(10000);
        }
    };

    try {
        foreach (['hold', 'complete'] as $mode) {
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=512M', '-r', $worker, base_path(), $directory, (string) $operation->id, $mode, (string) auth()->id()], base_path(), $environment, timeout: 30);
            $workers[$mode] = $process;
            $process->start();
            $waitFor(fn (): bool => file_exists($directory.'/pid-'.$mode));
            if ($mode === 'hold') {
                $waitFor(fn (): bool => file_exists($directory.'/holding'));
            }
        }

        $completePid = (int) file_get_contents($directory.'/pid-complete');
        $waitFor(fn (): bool => (int) DB::selectOne('SELECT cardinality(pg_blocking_pids(?)) AS count', [$completePid])->count > 0);
        touch($directory.'/release');

        foreach ($workers as $workerProcess) {
            $workerProcess->wait();
            expect($workerProcess->isSuccessful())->toBeTrue($workerProcess->getErrorOutput());
        }

        expect(json_decode($workers['complete']->getOutput(), true))->toBe(['denied' => true])
            ->and($operation->fresh()->origin)->toBe('claimed')
            ->and($operation->fresh()->state)->not->toBe(OperationState::DONE)
            ->and(InventoryHelper::onHand($product, $operation->sourceLocation))->toBe(10.0)
            ->and(InventoryHelper::onHand($product, $operation->destinationLocation))->toBe(0.0);
    } finally {
        touch($directory.'/release');
        foreach ($workers as $workerProcess) {
            if ($workerProcess->isRunning()) {
                $workerProcess->stop();
            }
        }
        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
});

it('rejects changed operation membership before mutation and guards the fresh retry', function (string $action): void {
    if (! config('testing.run_reconciliation_concurrency') || DB::connection()->getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requires the opted-in disposable PostgreSQL runner.');
    }

    [$operation] = guardedDelivery();
    $otherProduct = InventoryHelper::product();
    $database = DB::connection()->getDatabaseName();
    expect($database)->toMatch('/^aureuserp_[A-Za-z0-9_]+_testing$/');
    while (DB::connection()->transactionLevel() > 0) {
        DB::connection()->commit();
    }

    $directory = sys_get_temp_dir().'/stock-membership-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection();
$operation = Webkul\Inventory\Models\Operation::findOrFail((int) $argv[3]);
$productId = (int) $argv[5];
file_put_contents($argv[2].'/pid-'.$argv[4], (string) $db->selectOne('SELECT pg_backend_pid() AS pid')->pid);
if ($argv[4] === 'insert') {
    $db->transaction(function () use ($operation, $productId, $argv): void {
        Webkul\Inventory\Models\Move::lockForStockMutation(collect(), [$productId], [$operation->id]);
        $move = $operation->moves()->firstOrFail()->replicate();
        $move->product_id = $productId;
        $move->unsetRelations();
        $move->save();
        touch($argv[2].'/holding');
        $deadline = microtime(true) + 20;
        while (! file_exists($argv[2].'/release')) {
            if (microtime(true) > $deadline) { throw new RuntimeException('Release timeout'); }
            usleep(10000);
        }
    });
    echo json_encode(['inserted' => true]);
} else {
    $guard = new class($productId) implements Webkul\Inventory\Support\StockMutationPrecondition {
        public function __construct(private int $productId) {}
        public function assertAllowed(string $action, Illuminate\Support\Collection $operations, Illuminate\Support\Collection $moves, array $changes): void {
            if (in_array($action, ['operation.delete', 'operation.update', 'backorder.create']) && $moves->contains('product_id', $this->productId)) {
                throw new RuntimeException('New product requires review');
            }
        }
    };
    app()->instance('test.membership-stock-guard', $guard);
    app()->tag(['test.membership-stock-guard'], Webkul\Inventory\Support\StockMutationGuard::TAG);
    $mutate = fn () => match ($argv[6]) {
        'delete' => $operation->delete(),
        'save' => $operation->update(['origin' => 'Changed operation']),
        'backorder' => app(Webkul\Inventory\Services\BackorderCreator::class)->createFor($operation),
    };
    $results = [];
    for ($attempt = 0; $attempt < 2; $attempt++) {
        try {
            $mutate();
            $results[] = 'allowed';
        } catch (RuntimeException $exception) {
            $results[] = $exception->getMessage();
        }
    }
    echo json_encode($results);
}
PHP;
    $environment = [...$_ENV,
        'APP_ENV'                       => 'testing',
        'DB_CONNECTION'                 => 'pgsql',
        'DB_HOST'                       => config('database.connections.pgsql.host'),
        'DB_PORT'                       => (string) config('database.connections.pgsql.port'),
        'DB_USERNAME'                   => config('database.connections.pgsql.username'),
        'DB_PASSWORD'                   => config('database.connections.pgsql.password'),
        'DB_DATABASE'                   => $database,
        'AUREUSERP_TEST_REUSE_DATABASE' => '1',
    ];
    $workers = [];
    $waitFor = function (callable $ready): void {
        $deadline = microtime(true) + 20;
        while (! $ready()) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Concurrent operation membership worker timed out.');
            }
            usleep(10000);
        }
    };

    try {
        foreach (['insert', 'mutate'] as $mode) {
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=512M', '-r', $worker, base_path(), $directory, (string) $operation->id, $mode, (string) $otherProduct->id, $action], base_path(), $environment, timeout: 30);
            $workers[$mode] = $process;
            $process->start();
            $waitFor(fn (): bool => file_exists($directory.'/pid-'.$mode));
            if ($mode === 'insert') {
                $waitFor(fn (): bool => file_exists($directory.'/holding'));
            }
        }

        $pid = (int) file_get_contents($directory.'/pid-mutate');
        $waitFor(fn (): bool => (int) DB::selectOne('SELECT cardinality(pg_blocking_pids(?)) AS count', [$pid])->count > 0);
        touch($directory.'/release');
        foreach ($workers as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
        }

        expect(json_decode($workers['mutate']->getOutput(), true))->toBe([
            __('inventories::system.inventory-manager.stale-operation'),
            'New product requires review',
        ])
            ->and(Operation::query()->whereKey($operation->id)->exists())->toBeTrue()
            ->and($operation->fresh()->origin)->toBe($operation->origin)
            ->and($operation->moves()->count())->toBe(2)
            ->and($operation->moves()->where('product_id', $otherProduct->id)->exists())->toBeTrue()
            ->and(Operation::query()->where('back_order_id', $operation->id)->exists())->toBeFalse();
    } finally {
        touch($directory.'/release');
        foreach ($workers as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
})->with(['delete', 'save', 'backorder']);

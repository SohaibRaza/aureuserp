<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Webkul\Inventory\Enums\MoveState;
use Webkul\Inventory\Enums\OperationState;
use Webkul\Inventory\Events\OperationCanceled;
use Webkul\Inventory\Events\OperationDone;
use Webkul\Inventory\Facades\Inventory;
use Webkul\Inventory\Models\Move;
use Webkul\Inventory\Models\MoveLine;
use Webkul\Inventory\Models\Operation;
use Webkul\Inventory\Models\OperationResult;
use Webkul\Inventory\Models\ProductQuantity;
use Webkul\Inventory\Services\MoveCanceller;
use Webkul\Inventory\Services\MoveCompleter;
use Webkul\Inventory\Services\TransferWorkflow;
use Webkul\Inventory\Support\StockMutationGuard;
use Webkul\Inventory\Support\StockMutationPrecondition;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Security\PermissionRegistrar;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../Helpers/InventoryHelper.php';
require_once __DIR__.'/../../../../support/tests/Helpers/SecurityHelper.php';

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('inventories');
    InventoryHelper::actingAsAdmin();
});

function replayDelivery(): array
{
    $warehouse = InventoryHelper::warehouse();
    $product = InventoryHelper::product();
    InventoryHelper::stockUp($product, $warehouse->lotStockLocation, 10);
    $operation = InventoryHelper::delivery($warehouse, [[$product, 10]]);
    Inventory::confirmTransfer($operation);

    return [$operation->refresh(), $product];
}

it('returns one saved completion result without changing stock or emitting a second event', function (): void {
    [$operation, $product] = replayDelivery();
    $events = 0;
    Event::listen(OperationDone::class, function () use (&$events): void {
        $events++;
    });
    Inventory::completeTransfer($operation);
    $result = $operation->terminalResult;
    expect($result)->not->toBeNull();
    $closed = $operation->closed_at;
    $this->travel(5)->seconds();
    $replayed = Inventory::completeTransfer($operation->fresh());
    expect($replayed->terminalResult->id)->toBe($result->id)
        ->and($replayed->terminalResult->result)->toBe($result->result)
        ->and($replayed->closed_at->equalTo($closed))->toBeTrue()
        ->and($events)->toBe(1)
        ->and(InventoryHelper::onHand($product, $operation->sourceLocation))->toBe(0.0);
});

it('does not settle the same lines twice before their parent is completed', function (): void {
    [$operation, $product] = replayDelivery();
    $lines = $operation->moves->flatMap->lines;
    app(MoveCompleter::class)->completeLines($lines);
    expect($lines->first()->fresh()->state)->toBe(MoveState::DONE);
    app(MoveCompleter::class)->completeLines($lines->map->fresh());
    expect(InventoryHelper::onHand($product, $operation->sourceLocation))->toBe(0.0)
        ->and(InventoryHelper::onHand($product, $operation->destinationLocation))->toBe(10.0);
});

it('rejects a rounding change to a unit already used by stock', function (): void {
    [$operation] = replayDelivery();
    $uom = $operation->moves->first()->product->uom;
    expect(fn () => $uom->update(['rounding' => 0.001]))
        ->toThrow(ValidationException::class);
});

it('returns the same cancellation result without another cancellation event', function (): void {
    [$operation, $product] = replayDelivery();
    $events = 0;
    Event::listen(OperationCanceled::class, function () use (&$events): void {
        $events++;
    });
    Inventory::cancelTransfer($operation);
    $result = $operation->terminalResult->result;
    $closed = $operation->closed_at;
    $this->travel(5)->seconds();
    Inventory::cancelTransfer($operation);
    expect($operation->terminalResult->result)->toBe($result)
        ->and($operation->closed_at->equalTo($closed))->toBeTrue()
        ->and($events)->toBe(1)
        ->and(InventoryHelper::onHand($product, $operation->sourceLocation))->toBe(10.0);
});

it('rejects a different terminal action or changed backorder instruction', function (string $action): void {
    [$operation] = replayDelivery();
    Inventory::completeTransfer($operation);
    expect(fn () => $action === 'cancel' ? Inventory::cancelTransfer($operation) : Inventory::completeTransfer($operation, true))
        ->toThrow(ValidationException::class, __('inventories::system.inventory-manager.replay-conflict'));
})->with(['cancel', 'changed backorder']);

it('preserves the original counted request across a partial backorder and rejects changed counts', function (): void {
    [$operation, $product] = replayDelivery();
    $lineId = $operation->moves->first()->lines->first()->id;
    $first = Inventory::completeTransfer($operation, false, [$lineId => 4.0]);
    $result = $first->terminalResult->result;
    $backorderIds = $result['backorder_ids'];
    expect($backorderIds)->toHaveCount(1);
    Inventory::completeTransfer($operation->fresh(), false, [$lineId => 4.0]);
    expect($operation->fresh()->terminalResult->result)->toBe($result)
        ->and(InventoryHelper::onHand($product, $operation->sourceLocation))->toBe(6.0)
        ->and(Operation::where('back_order_id', $operation->id)->count())->toBe(1)
        ->and(Operation::find($backorderIds[0])->terminalResult)->toBeNull();
    expect(fn () => Inventory::completeTransfer($operation->fresh(), false, [$lineId => 5.0]))
        ->toThrow(ValidationException::class, __('inventories::system.inventory-manager.replay-conflict'));
});

it('rejects terminal legacy operations without inventing a result', function (): void {
    [$operation] = replayDelivery();
    DB::table('inventories_operations')->where('id', $operation->id)->update(['state' => 'done']);
    expect(fn () => Inventory::completeTransfer($operation->fresh()))
        ->toThrow(ValidationException::class, __('inventories::system.inventory-manager.legacy-terminal'));
    expect($operation->fresh()->terminalResult)->toBeNull();
});

it('reauthorizes terminal result access after the actor loses permission', function (): void {
    [$operation] = replayDelivery();
    Inventory::completeTransfer($operation);
    $user = User::factory()->create(['default_company_id' => $operation->company_id]);
    auth()->login($user);
    app()->forgetInstance(CompanyContext::class);
    expect(fn () => Inventory::completeTransfer($operation->fresh()))
        ->toThrow(AuthorizationException::class);
});

it('does not reveal a result when the owning company is inactive', function (): void {
    [$operation] = replayDelivery();
    Inventory::completeTransfer($operation);
    $other = Company::factory()->create();
    session([CompanyContext::SESSION_KEY => [$other->id]]);
    app()->forgetInstance(CompanyContext::class);
    expect(fn () => Inventory::completeTransfer($operation->fresh()))
        ->toThrow(ModelNotFoundException::class);
});

it('rejects a saved-result retry after a warmed role loses its execution permission', function (string $method): void {
    [$operation, $product] = replayDelivery();
    $user = SecurityHelper::authenticateWithPermissions([]);
    auth()->shouldUse('web');
    $user->forceFill(['resource_permission' => PermissionType::GLOBAL])->saveQuietly();
    $user->allowedCompanies()->sync([$operation->company_id]);
    $role = Role::create(['name' => 'delivery_executor_'.Str::random(12), 'guard_name' => 'web']);
    $role->syncPermissionsByNames(['update_inventory_delivery', 'view_inventory_delivery']);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect($user->fresh()->can('update_inventory_delivery'))->toBeTrue();

    $events = 0;
    Event::listen(OperationDone::class, function () use (&$events): void {
        $events++;
    });
    Inventory::completeTransfer($operation);
    $savedOperation = $operation->fresh()->getRawOriginal();
    $savedResult = $operation->fresh()->terminalResult->getRawOriginal();
    $savedStock = ProductQuantity::query()->where('product_id', $product->id)->orderBy('id')->get()->toArray();

    match ($method) {
        'empty sync'    => $role->syncPermissionsByNames([]),
        'nonempty sync' => $role->syncPermissionsByNames(['view_inventory_delivery']),
        'revoke'        => $role->revokePermissionTo('update_inventory_delivery'),
    };

    $this->travel(5)->seconds();
    expect(fn () => Inventory::completeTransfer($operation->fresh()))->toThrow(AuthorizationException::class);
    expect($user->fresh()->can('update_inventory_delivery'))->toBeFalse()
        ->and($operation->fresh()->getRawOriginal())->toBe($savedOperation)
        ->and($operation->fresh()->terminalResult->getRawOriginal())->toBe($savedResult)
        ->and(ProductQuantity::query()->where('product_id', $product->id)->orderBy('id')->get()->toArray())->toBe($savedStock)
        ->and($events)->toBe(1);

    if ($method === 'revoke') {
        $role->givePermissionTo('update_inventory_delivery');
    } else {
        $role->syncPermissionsByNames(['update_inventory_delivery', 'view_inventory_delivery']);
    }

    expect($user->fresh()->can('update_inventory_delivery'))->toBeTrue();
    Inventory::completeTransfer($operation->fresh());
    expect($operation->fresh()->getRawOriginal())->toBe($savedOperation)
        ->and($operation->fresh()->terminalResult->getRawOriginal())->toBe($savedResult)
        ->and(ProductQuantity::query()->where('product_id', $product->id)->orderBy('id')->get()->toArray())->toBe($savedStock)
        ->and($events)->toBe(1);
})->with(['empty sync', 'nonempty sync', 'revoke']);

it('does not run new stock mutation checks when returning an already saved result', function (): void {
    [$operation] = replayDelivery();
    Inventory::completeTransfer($operation);
    app()->bind('test.replay.deny', fn () => new class implements StockMutationPrecondition
    {
        public function assertAllowed(string $action, Collection $operations, Collection $moves, array $changes = []): void
        {
            throw new RuntimeException('New movement blocked.');
        }
    });
    app()->tag('test.replay.deny', StockMutationGuard::TAG);
    expect(Inventory::completeTransfer($operation->fresh())->terminalResult->id)->toBe($operation->terminalResult->id);
});

it('rolls stock and terminal state back when saving the result fails', function (): void {
    [$operation, $product] = replayDelivery();
    OperationResult::creating(function (): void {
        throw new RuntimeException('Result write failed.');
    });
    expect(fn () => Inventory::completeTransfer($operation))->toThrow(RuntimeException::class, 'Result write failed.');
    expect($operation->fresh()->state)->not->toBe(OperationState::DONE)
        ->and($operation->fresh()->terminalResult)->toBeNull()
        ->and($operation->fresh()->closed_at)->toBeNull()
        ->and(InventoryHelper::onHand($product, $operation->sourceLocation))->toBe(10.0);
});

it('prevents both model and direct SQL changes to saved results', function (): void {
    [$operation] = replayDelivery();
    Inventory::completeTransfer($operation);
    $result = $operation->terminalResult;
    expect(fn () => $result->updateQuietly(['action' => 'cancel']))->toThrow(ValidationException::class);
    expect(fn () => DB::transaction(fn () => DB::table('inventories_operation_results')->where('id', $result->id)->update(['action' => 'cancel'])))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('inventories_operation_results')->where('id', $result->id)->delete()))
        ->toThrow(QueryException::class);
});

it('refuses to roll back a saved operation result without dropping its history table', function (): void {
    [$operation] = replayDelivery();
    Inventory::completeTransfer($operation);
    $result = $operation->terminalResult;
    $migration = require __DIR__.'/../../../database/migrations/2026_10_15_220000_create_operation_results_table.php';

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward recovery');
    expect(DB::table('inventories_operation_results')->where('id', $result->id)->exists())->toBeTrue()
        ->and(DB::getDriverName() === 'pgsql'
            ? DB::table('pg_trigger')->where('tgname', 'inventories_operation_results_immutable')->exists()
            : DB::table('information_schema.TRIGGERS')->whereRaw('TRIGGER_SCHEMA = DATABASE()')->where('EVENT_OBJECT_TABLE', 'inventories_operation_results')->count() === 2)->toBeTrue();
});

it('prevents quiet changes and deletion of settled lines and moves', function (): void {
    [$operation] = replayDelivery();
    Inventory::completeTransfer($operation);
    $move = $operation->moves()->first();
    $line = $move->lines()->first();
    expect(fn () => $line->updateQuietly(['qty' => 3]))->toThrow(ValidationException::class);
    expect(fn () => $line->fresh()->deleteQuietly())->toThrow(ValidationException::class);
    expect(fn () => $move->updateQuietly(['quantity' => 3]))->toThrow(ValidationException::class);
    expect(fn () => $move->fresh()->deleteQuietly())->toThrow(ValidationException::class);
});

it('rejects completing canceled movements and their lines without stock effects', function (): void {
    [$operation, $product] = replayDelivery();
    $lines = $operation->moves->flatMap->lines;
    Inventory::cancelTransfer($operation);
    expect(fn () => Inventory::completeMoves($operation->moves()->get()))->toThrow(ValidationException::class);
    // Cancellation removes unissued lines, so no stale line identity may be executed.
    expect(InventoryHelper::onHand($product, $operation->sourceLocation))->toBe(10.0);
});

it('returns the saved result through the native delivery API on retry', function (): void {
    $user = SecurityHelper::authenticateWithPermissions(['update_inventory_delivery']);
    $user->forceFill(['resource_permission' => PermissionType::GLOBAL])->saveQuietly();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    [$operation] = replayDelivery();
    $url = route('admin.api.v1.inventories.deliveries.validate', $operation->id);
    $first = $this->postJson($url)->assertOk();
    $saved = $first->json('data.execution_result');
    expect($saved)->not->toBeNull();
    $this->postJson($url)->assertOk()->assertJsonPath('data.execution_result', $saved);
});

it('replays the Barcode counted command without changing the original partial result', function (): void {
    TestBootstrapHelper::ensurePluginInstalled('barcode');
    InventoryHelper::actingAsAdmin();
    app()->forgetInstance(CompanyContext::class);
    [$operation, $product] = replayDelivery();
    $lineId = $operation->moves->first()->lines->first()->id;
    if (! Route::has('barcode.dashboard')) {
        require base_path('plugins/webkul/barcode/routes/web.php');
    }
    $component = Livewire\Livewire::test(Webkul\Barcode\Livewire\Operation::class, ['operationType' => $operation->operationType, 'operation' => $operation])
        ->set('countedMoveLineIds', [$lineId => true])
        ->set('countedMoveLineQuantities', [$lineId => 4.0])
        ->call('executeAction', 'validate');
    $saved = $operation->fresh()->terminalResult;
    expect($saved)->not->toBeNull();
    $component->call('executeAction', 'validate');
    expect($operation->fresh()->terminalResult->id)->toBe($saved->id)
        ->and(InventoryHelper::onHand($product, $operation->sourceLocation))->toBe(6.0);
    $component->set('countedMoveLineQuantities', [$lineId => 5.0])->call('executeAction', 'validate')
        ->assertSet('notice', __('inventories::system.inventory-manager.replay-conflict'));
});

it('keeps a native return independent from its source execution result', function (): void {
    [$operation] = replayDelivery();
    Inventory::completeTransfer($operation);
    $return = Inventory::createReturn($operation, [$operation->moves()->first()->id => 2]);
    expect($return->terminalResult)->toBeNull();
    Inventory::completeTransfer($return);
    expect($return->terminalResult->id)->not->toBe($operation->terminalResult->id);
});

it('rolls direct line settlement back if the destination write fails', function (): void {
    [$operation, $product] = replayDelivery();
    $destinationId = $operation->destination_location_id;
    ProductQuantity::creating(function ($quantity) use ($destinationId): void {
        if ($quantity->location_id === $destinationId) {
            throw new RuntimeException('Destination failed.');
        }
    });
    expect(fn () => app(MoveCompleter::class)->completeLines($operation->moves->flatMap->lines))
        ->toThrow(RuntimeException::class, 'Destination failed.');
    expect($operation->moves->first()->lines->first()->fresh()->state)->not->toBe(MoveState::DONE)
        ->and(InventoryHelper::onHand($product, $operation->sourceLocation))->toBe(10.0);
});

it('rejects overcounted input before changing quantities or stock', function (): void {
    [$operation, $product] = replayDelivery();
    $line = $operation->moves->first()->lines->first();
    expect(fn () => Inventory::completeTransfer($operation, false, [$line->id => 11.0]))
        ->toThrow(ValidationException::class, __('inventories::system.inventory-manager.invalid-counts'));
    expect((float) $line->fresh()->qty)->toBe(10.0)
        ->and(InventoryHelper::onHand($product, $operation->sourceLocation))->toBe(10.0)
        ->and($operation->fresh()->terminalResult)->toBeNull();
});

it('returns the saved result during a matching synchronous completion listener retry', function (): void {
    [$operation] = replayDelivery();
    $events = 0;
    $replayedId = null;
    Event::listen(OperationDone::class, function ($event) use (&$events, &$replayedId): void {
        $events++;
        $replayedId = Inventory::completeTransfer($event->operation)->terminalResult->id;
    });
    Inventory::completeTransfer($operation);
    expect($events)->toBe(1)->and($replayedId)->toBe($operation->terminalResult->id);
});

it('rolls the entire completion back when a listener requests conflicting cancellation', function (): void {
    [$operation, $product] = replayDelivery();
    Event::listen(OperationDone::class, fn ($event) => Inventory::cancelTransfer($event->operation));
    expect(fn () => Inventory::completeTransfer($operation))->toThrow(ValidationException::class);
    expect($operation->fresh()->terminalResult)->toBeNull()
        ->and($operation->fresh()->closed_at)->toBeNull()
        ->and(InventoryHelper::onHand($product, $operation->sourceLocation))->toBe(10.0);
});

it('rejects positive new or reparented children in a completed operation', function (): void {
    [$operation] = replayDelivery();
    Inventory::completeTransfer($operation);
    $move = $operation->moves()->first();
    $line = $move->lines()->first();
    $counts = [Move::count(), MoveLine::count()];
    $newMove = $move->replicate();
    $newMove->quantity = 1;
    expect(fn () => $newMove->saveQuietly())->toThrow(ValidationException::class);
    $newLine = $line->replicate();
    $newLine->qty = 1;
    expect(fn () => $newLine->saveQuietly())->toThrow(ValidationException::class);
    [$other] = replayDelivery();
    $otherMove = $other->moves()->first();
    $otherLine = $otherMove->lines()->first();
    expect(fn () => $otherMove->updateQuietly(['operation_id' => $operation->id]))->toThrow(ValidationException::class);
    expect(fn () => $otherLine->updateQuietly(['move_id' => $move->id, 'operation_id' => $operation->id]))->toThrow(ValidationException::class);
    expect($operation->moves()->count())->toBe(1)->and($move->lines()->count())->toBe(1);
});

it('does not equate omitted native counts with an explicit empty count request', function (): void {
    [$operation] = replayDelivery();
    Inventory::completeTransfer($operation);
    $saved = $operation->terminalResult->result;
    expect(fn () => Inventory::completeTransfer($operation->fresh(), false, []))
        ->toThrow(ValidationException::class, __('inventories::system.inventory-manager.replay-conflict'));
    expect($operation->fresh()->terminalResult->result)->toBe($saved);
});

it('does not equate an accepted counted request with omitted native counts', function (): void {
    [$operation] = replayDelivery();
    $id = $operation->moves->first()->lines->first()->id;
    Inventory::completeTransfer($operation, false, [$id => 10.0]);
    expect(fn () => Inventory::completeTransfer($operation->fresh()))
        ->toThrow(ValidationException::class, __('inventories::system.inventory-manager.replay-conflict'));
});

it('rolls an empty first count request back before accepting later native completion', function (): void {
    [$operation, $product] = replayDelivery();
    expect(fn () => Inventory::completeTransfer($operation, false, []))->toThrow(Exception::class);
    expect($operation->fresh()->terminalResult)->toBeNull()
        ->and(InventoryHelper::onHand($product, $operation->sourceLocation))->toBe(10.0);
    Inventory::completeTransfer($operation->fresh());
    expect($operation->fresh()->terminalResult)->not->toBeNull();
});

it('rejects a newly inactive executor for new commands and saved-result retries', function (bool $completeFirst): void {
    [$operation, $product] = replayDelivery();
    if ($completeFirst) {
        Inventory::completeTransfer($operation);
    }
    $actor = auth()->user();
    DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
    expect((bool) $actor->is_active)->toBeTrue();
    expect(fn () => Inventory::completeTransfer($operation))
        ->toThrow(HttpException::class);
    expect(InventoryHelper::onHand($product, $operation->sourceLocation))->toBe($completeFirst ? 0.0 : 10.0);
})->with(['new command' => false, 'result replay' => true]);

it('rejects company access revoked after the request context has already loaded', function (): void {
    $user = SecurityHelper::authenticateWithPermissions(['update_inventory_delivery']);
    $user->forceFill(['resource_permission' => PermissionType::GLOBAL])->saveQuietly();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    [$operation, $product] = replayDelivery();
    Inventory::completeTransfer($operation);
    $context = app(CompanyContext::class);
    expect($context->activeIds())->toContain($operation->company_id);
    $user->allowedCompanies()->detach($operation->company_id);
    expect($context->activeIds())->toContain($operation->company_id);
    expect(fn () => Inventory::completeTransfer($operation))
        ->toThrow(HttpException::class);
    expect(InventoryHelper::onHand($product, $operation->sourceLocation))->toBe(0.0);
});

it('does not let an unsaved changed line quantity masquerade as a matching direct retry', function (): void {
    [$operation, $product] = replayDelivery();
    $lines = $operation->moves->flatMap->lines;
    app(MoveCompleter::class)->completeLines($lines);
    $lines->first()->qty = 3;
    expect(fn () => app(MoveCompleter::class)->completeLines($lines))
        ->toThrow(ValidationException::class);
    expect(InventoryHelper::onHand($product, $operation->sourceLocation))->toBe(0.0);
});

it('preserves a recorded close time when a quiet update tries to clear it', function (): void {
    [$operation] = replayDelivery();
    Inventory::completeTransfer($operation);
    expect(fn () => $operation->updateQuietly(['closed_at' => null]))
        ->toThrow(ValidationException::class);
    expect($operation->fresh()->closed_at)->not->toBeNull();
});

it('preserves canceled move history once its operation has a saved cancellation result', function (): void {
    [$operation] = replayDelivery();
    Inventory::cancelTransfer($operation);
    $move = $operation->moves()->first();
    expect(fn () => $move->deleteQuietly())->toThrow(ValidationException::class);
    expect($operation->fresh()->terminalResult)->not->toBeNull()
        ->and($operation->moves()->count())->toBe(1);
});

it('keeps terminal snapshot queries bounded as movement count grows', function (): void {
    $workflow = new class extends TransferWorkflow
    {
        public function inspectSnapshot(Operation $operation): array
        {
            return $this->snapshot($operation);
        }
    };
    $queryCounts = [];
    foreach ([1, 5] as $size) {
        $warehouse = InventoryHelper::warehouse();
        $items = [];
        for ($index = 0; $index < $size; $index++) {
            $product = InventoryHelper::product();
            InventoryHelper::stockUp($product, $warehouse->lotStockLocation, 10);
            $items[] = [$product, 10];
        }
        $operation = InventoryHelper::delivery($warehouse, $items);
        Inventory::confirmTransfer($operation);
        $expectedMoves = $operation->moves()->orderBy('id')->get();
        $expectedLines = $expectedMoves->mapWithKeys(fn (Move $move): array => [$move->id => $move->lines()->orderBy('id')->pluck('id')->all()]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $snapshot = $workflow->inspectSnapshot($operation);
            $queryCounts[] = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        expect(array_column($snapshot['moves'], 'id'))->toBe($expectedMoves->modelKeys());
        foreach ($snapshot['moves'] as $move) {
            expect(array_column($move['lines'], 'id'))->toBe($expectedLines[$move['id']])
                ->and((float) $move['product_uom_qty'])->toBe(10.0)
                ->and($move['stock_uom_id'])->toBe($items[0][0]->uom_id)
                ->and((float) $move['stock_unit_factor'])->toBe(1.0);
        }
    }
    expect($queryCounts[1])->toBeLessThanOrEqual($queryCounts[0]);
});

it('keeps stock guard operation queries bounded as movement count grows', function (string $action): void {
    $batches = [];
    foreach ([1, 5] as $size) {
        $warehouse = InventoryHelper::warehouse();
        $items = [];
        for ($index = 0; $index < $size; $index++) {
            $product = InventoryHelper::product();
            InventoryHelper::stockUp($product, $warehouse->lotStockLocation, 10);
            $items[] = [$product, 10];
        }
        $operation = InventoryHelper::delivery($warehouse, $items);
        Inventory::confirmTransfer($operation);
        $batches[] = $operation->moves()->orderBy('id')->get();
    }
    $precondition = new class implements StockMutationPrecondition
    {
        public array $operationIds = [];

        public array $moveIds = [];

        public function assertAllowed(string $action, Collection $operations, Collection $moves, array $changes = []): void
        {
            $this->operationIds = $operations->pluck('id')->all();
            $this->moveIds = $moves->pluck('id')->all();
            throw new RuntimeException('Stopped at stock guard.');
        }
    };
    app()->instance('test.replay.query-boundary', $precondition);
    app()->tag('test.replay.query-boundary', StockMutationGuard::TAG);
    $queryCounts = [];
    foreach ($batches as $moves) {
        $lines = $moves->flatMap->lines;
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            expect(fn () => match ($action) {
                'complete'       => app(MoveCompleter::class)->complete($moves),
                'cancel'         => app(MoveCanceller::class)->cancel($moves),
                'complete.lines' => app(MoveCompleter::class)->completeLines($lines),
            })->toThrow(RuntimeException::class, 'Stopped at stock guard.');
            $queryCounts[] = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        expect($precondition->operationIds)->toBe($moves->pluck('operation_id')->unique()->values()->all())
            ->and(collect($precondition->moveIds)->sort()->values()->all())->toBe($moves->modelKeys())
            ->and($moves->map(fn (Move $move): float => InventoryHelper::onHand($move->product, $move->sourceLocation))->all())->toBe(array_fill(0, $moves->count(), 10.0));
    }
    expect($queryCounts[1])->toBeLessThanOrEqual($queryCounts[0]);
})->with(['complete', 'cancel', 'complete.lines']);

it('checks a controlled issue in product units when its movement uses dozens', function (): void {
    $warehouse = InventoryHelper::warehouse();
    $product = InventoryHelper::product();
    $stock = InventoryHelper::stockUp($product, $warehouse->lotStockLocation, 24);
    $operation = InventoryHelper::delivery($warehouse, [[$product, 2, InventoryHelper::dozensUom()]]);
    Inventory::confirmTransfer($operation);
    $line = $operation->refresh()->moves->sole()->lines->sole();
    expect((float) $line->qty)->toBe(2.0)->and((float) $line->uom_qty)->toBe(24.0);
    InventoryHelper::applyInventoryAdjustment($stock->fresh(), 11);
    expect(fn () => DB::transaction(fn () => app(MoveCompleter::class)->assertReservedStockAvailable($operation->moves, [$line->id => 1.0])))
        ->toThrow(ValidationException::class);
    InventoryHelper::applyInventoryAdjustment($stock->fresh(), 13);
    DB::transaction(function () use ($operation, $line): void {
        app(MoveCompleter::class)->assertReservedStockAvailable($operation->moves, [$line->id => 1.0]);
        Inventory::completeTransfer($operation, false, [$line->id => 1.0]);
    });
    expect(InventoryHelper::onHand($product, $warehouse->lotStockLocation))->toBe(1.0);
});

it('does not consume a competing reservation when a count makes the shared stock short', function (): void {
    [$operation, $product] = replayDelivery();
    $stock = ProductQuantity::query()->where('product_id', $product->id)->where('location_id', $operation->source_location_id)->sole();
    InventoryHelper::applyInventoryAdjustment($stock->fresh(), 20);
    $other = InventoryHelper::delivery($operation->operationType->warehouse, [[$product, 10]]);
    Inventory::confirmTransfer($other);
    InventoryHelper::applyInventoryAdjustment($stock->fresh(), 15);
    expect(fn () => DB::transaction(fn () => app(MoveCompleter::class)->assertReservedStockAvailable($operation->moves)))
        ->toThrow(ValidationException::class);
    $line = $operation->moves->sole()->lines->sole();
    DB::transaction(function () use ($operation, $line): void {
        app(MoveCompleter::class)->assertReservedStockAvailable($operation->moves, [$line->id => 5.0]);
        Inventory::completeTransfer($operation, false, [$line->id => 5.0]);
    });
    expect((float) $other->fresh()->moves->sole()->quantity)->toBe(10.0)
        ->and((float) $stock->fresh()->quantity)->toBe(10.0)
        ->and((float) $stock->fresh()->reserved_quantity)->toBe(10.0);
});

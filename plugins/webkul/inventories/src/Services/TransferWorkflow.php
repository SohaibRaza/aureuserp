<?php

namespace Webkul\Inventory\Services;

use Carbon\Carbon;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Webkul\Inventory\Enums\MoveState;
use Webkul\Inventory\Enums\OperationState;
use Webkul\Inventory\Enums\OperationType;
use Webkul\Inventory\Enums\ProcureMethod;
use Webkul\Inventory\Events\OperationAssigned;
use Webkul\Inventory\Events\OperationCanceled;
use Webkul\Inventory\Events\OperationConfirmed;
use Webkul\Inventory\Events\OperationDone;
use Webkul\Inventory\Events\OperationReturned;
use Webkul\Inventory\Filament\Clusters\Operations\Resources\OperationResource;
use Webkul\Inventory\Models\Delivery;
use Webkul\Inventory\Models\Dropship;
use Webkul\Inventory\Models\InternalTransfer;
use Webkul\Inventory\Models\Move;
use Webkul\Inventory\Models\MoveLine;
use Webkul\Inventory\Models\Operation;
use Webkul\Inventory\Models\OperationResult;
use Webkul\Inventory\Models\ProductQuantity;
use Webkul\Inventory\Models\Receipt;
use Webkul\Inventory\Support\StockMutationGuard;
use Webkul\PluginManager\Package;
use Webkul\Support\Services\CompanyContext;

class TransferWorkflow
{
    public function confirm(Operation $operation): Operation
    {
        return $this->withLockedOperation($operation, 'confirm', function () use ($operation): Operation {
            app(MoveConfirmer::class)->confirm(
                $operation->moves->filter(fn (Move $move) => $move->state === MoveState::DRAFT)
            );

            $this->refreshState($operation);

            OperationConfirmed::dispatch($operation);

            return $operation;
        });
    }

    public function reserve(Operation $operation): Operation
    {
        return $this->withLockedOperation($operation, 'reserve', function () use ($operation): Operation {
            if ($operation->state === OperationState::DRAFT) {
                $operation = $this->confirm($operation);
            }

            $moves = $operation->moves
                ->filter(fn (Move $move) => ! in_array($move->state, [MoveState::DRAFT, MoveState::CANCELED, MoveState::DONE]))
                ->sortBy([
                    fn (Move $move) => ! (bool) $move->deadline,
                    fn (Move $move) => $move->deadline,
                    fn (Move $move) => $move->scheduled_at,
                    fn (Move $move) => $move->id,
                ]);

            if ($moves->isEmpty()) {
                throw new \Exception(__('inventories::system.inventory-manager.check-availability.no-moves'));
            }

            app(MoveReserver::class)->reserve($moves);

            $this->refreshState($operation);

            OperationAssigned::dispatch($operation);

            return $operation;
        });
    }

    public function complete(Operation $operation, bool $cancelBackorder = false, ?array $countedLineQuantities = null): Operation
    {
        $countedLineQuantities = $this->normalizeCounts($countedLineQuantities);

        return $this->withLockedOperation($operation, 'complete', function () use ($operation, $cancelBackorder, $countedLineQuantities): Operation {
            $this->applyCounts($operation, $countedLineQuantities);

            app(TransferValidator::class)->assertReadyToComplete($operation);

            $pending = $operation->moves->filter(fn (Move $move) => in_array($move->state, [
                MoveState::DRAFT,
                MoveState::WAITING,
                MoveState::PARTIALLY_ASSIGNED,
                MoveState::ASSIGNED,
                MoveState::CONFIRMED,
            ]));

            $this->markEverythingPickedIfNeeded($operation);

            app(MoveCompleter::class)->complete($pending, $cancelBackorder);

            $operation->refresh();

            $operation->computeState();

            $operation->closed_at = Carbon::now();

            $operation->save();

            ProductQuantity::purgeEmpty($pending->pluck('product_id')->unique()->all());

            return $operation;
        }, ['cancel_backorder' => $cancelBackorder, 'counted_line_quantities' => $countedLineQuantities]);
    }

    public function cancel(Operation $operation): Operation
    {
        return $this->withLockedOperation($operation, 'cancel', function () use ($operation): Operation {
            app(MoveCanceller::class)->cancel($operation->moves);

            $operation->computeState();

            $operation->save();

            $operation->closed_at = now();
            $operation->save();

            return $operation;
        });
    }

    public function createReturn(Operation $operation, array $moveQuantities = []): Operation
    {
        return $this->withLockedOperation($operation, 'return', function () use ($operation, $moveQuantities): Operation {
            $sourceMoves = $operation->moves
                ->whereIn('id', array_keys($moveQuantities));

            $this->assertReturnRequest($operation, $moveQuantities, $sourceMoves);

            foreach ($sourceMoves as $move) {
                app(MoveReserver::class)->release(
                    $move->moveDestinations->filter(
                        fn (Move $destination) => ! in_array($destination->state, [MoveState::DONE, MoveState::CANCELED])
                    )
                );
            }

            $return = $operation->replicate(['back_order_id', 'closed_at', 'is_printed'])
                ->fill($this->buildReturnOperationAttributes($operation));
            $return->setConnection($operation->getConnectionName());

            $return->save();

            foreach ($sourceMoves as $move) {
                $this->createReturnMove($return, $move, $moveQuantities[$move->id]);
            }

            $return->refresh();

            $return = $this->reserve($return);

            if (Package::isPluginInstalled('purchases')) {
                $return->purchaseOrders()->attach($operation->purchaseOrders->pluck('id'));
            }

            OperationReturned::dispatch($operation);

            $this->crossLink($operation, $return);

            return $return;
        }, ['move_quantities' => $moveQuantities]);
    }

    /**
     * @param  array<int|string, mixed>  $moveQuantities
     * @param  Collection<int, Move>  $sourceMoves
     */
    protected function assertReturnRequest(Operation $operation, array $moveQuantities, Collection $sourceMoves): void
    {
        if ($operation->state !== OperationState::DONE) {
            throw new \InvalidArgumentException(__('inventories::system.inventory-manager.return.completed-only'));
        }

        if ($moveQuantities === [] || $sourceMoves->count() !== count($moveQuantities)) {
            throw new \InvalidArgumentException(__('inventories::system.inventory-manager.return.missing-source-moves'));
        }

        foreach ($sourceMoves as $move) {
            $entry = $moveQuantities[$move->id];
            $quantity = is_array($entry) ? ($entry['quantity'] ?? 0) : $entry;

            if ((! is_int($quantity) && ! is_float($quantity))
                || ! is_finite((float) $quantity)
                || float_compare((float) $quantity, 0, precisionRounding: $move->uom->rounding) <= 0
            ) {
                throw new \InvalidArgumentException(__('inventories::system.inventory-manager.return.positive-quantity'));
            }

            $requested = $move->uom->computeQuantity($quantity, $move->product->uom, roundingMethod: 'HALF-UP');
            $completed = $move->uom->computeQuantity($move->quantity, $move->product->uom, roundingMethod: 'HALF-UP');
            $alreadyReturned = $move->returnedMoves()
                ->where('state', '!=', MoveState::CANCELED->value)
                ->sum('product_qty');

            if ($move->state !== MoveState::DONE
                || float_compare((float) $requested + (float) $alreadyReturned, (float) $completed, precisionRounding: $move->product->uom->rounding) > 0
            ) {
                throw new \InvalidArgumentException(__('inventories::system.inventory-manager.return.quantity-exceeded'));
            }
        }
    }

    protected function createReturnMove(Operation $return, Move $move, mixed $entry): void
    {
        $quantity = is_array($entry) ? ($entry['quantity'] ?? 0) : $entry;

        $isRefund = is_array($entry) ? (bool) ($entry['to_refund'] ?? true) : true;

        $returnMove = $move->replicate()->fill($this->buildReturnMoveAttributes($return, $move, $quantity, $isRefund));
        $returnMove->setConnection($return->getConnectionName());

        // Replication retains source relations; resolve defaults using the return's changed foreign keys.
        $returnMove->unsetRelations();

        $returnMove->save();

        $origins = $move->moveDestinations->flatMap->returnedMoves
            ->merge(collect([$move]))
            ->merge(
                $move->moveDestinations
                    ->filter(fn (Move $destination) => $destination->state !== MoveState::CANCELED)
                    ->flatMap->moveOrigins
                    ->filter(fn (Move $origin) => $origin->state !== MoveState::CANCELED)
            );

        $destinations = $move->moveOrigins->flatMap->returnedMoves
            ->merge(
                $move->moveOrigins->flatMap->returnedMoves
                    ->flatMap->moveOrigins
                    ->filter(fn (Move $origin) => $origin->state !== MoveState::CANCELED)
                    ->flatMap->moveDestinations
                    ->filter(fn (Move $destination) => $destination->state !== MoveState::CANCELED)
            );

        $returnMove->moveOrigins()->syncWithoutDetaching($origins->pluck('id')->all());

        $returnMove->moveDestinations()->syncWithoutDetaching($destinations->pluck('id')->all());
    }

    public function buildReturnOperationAttributes(Operation $operation): array
    {
        $returnType = $operation->operationType->returnOperationType;

        $destination = $returnType?->type === OperationType::INCOMING
            ? $returnType->destinationLocation
            : $operation->sourceLocation;

        return [
            'state'                   => OperationState::DRAFT,
            'origin'                  => __('inventories::system.inventory-manager.return.origin', ['operation_name' => $operation->name]),
            'operation_type_id'       => $returnType?->id ?? $operation->operation_type_id,
            'source_location_id'      => $operation->destinationLocation->id,
            'destination_location_id' => $destination->id,
            'return_id'               => $operation->id,
            'user_id'                 => null,
        ];
    }

    public function buildReturnMoveAttributes(Operation $return, Move $move, mixed $quantity, bool $isRefund = true): array
    {
        $attributes = [
            'name'                    => $return->name,
            'product_id'              => $move->product_id,
            'product_uom_qty'         => $move->uom->computeQuantity($quantity, $move->product->uom, roundingMethod: 'HALF-UP'),
            'product_qty'             => $move->uom->computeQuantity($quantity, $move->product->uom, roundingMethod: 'HALF-UP'),
            'quantity'                => 0,
            'is_picked'               => false,
            'is_refund'               => $isRefund,
            'uom_id'                  => $move->product->uom_id,
            'operation_id'            => $return->id,
            'state'                   => MoveState::DRAFT,
            'scheduled_at'            => now(),
            'deadline'                => null,
            'price_unit'              => 0,
            'source_location_id'      => $return->source_location_id ?? $move->destination_location_id,
            'destination_location_id' => $return->destination_location_id ?? $move->source_location_id,
            'final_location_id'       => null,
            'operation_type_id'       => $return->operation_type_id,
            'warehouse_id'            => $return->operationType->warehouse_id,
            'origin_returned_move_id' => $move->id,
            'procure_method'          => ProcureMethod::MAKE_TO_STOCK,
            'procurement_group_id'    => $return->procurement_group_id,
        ];

        if ($return->operationType->type === OperationType::OUTGOING) {
            $attributes['partner_id'] = $return->partner_id;
        }

        return $attributes;
    }

    protected function withLockedOperation(Operation $operation, string $action, Closure $callback, array $changes = []): Operation
    {
        $connection = $operation->getConnection();

        if ($connection !== (new Move)->getConnection()) {
            throw new \InvalidArgumentException('Transfer execution requires the Inventory database connection.');
        }

        return $connection->transaction(function () use ($operation, $action, $callback, $changes): Operation {
            $moves = Move::lockForStockMutation(
                $operation->moves()->get(),
                additionalOperationIds: [$operation->id],
                requireCompleteOperations: true,
                validateMutation: false,
            );
            $locked = $operation->newQuery()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            $operation->setRawAttributes($locked->getAttributes(), true);
            $operation->unsetRelations();
            $operation->setRelation('moves', $moves);

            $terminal = in_array($action, ['complete', 'cancel'], true);
            $intent = null;
            if ($terminal) {
                $this->authorizeExecution($operation);
                $saved = $operation->terminalResult()->first();
                if ($saved) {
                    if ($saved->action !== $action || ! $this->matchesOptions($action, $saved->intent['options'] ?? [], $changes)) {
                        $this->reject('replay-conflict');
                    }
                    $operation->setRelation('terminalResult', $saved);

                    return $operation;
                }
                if (in_array($operation->state, [OperationState::DONE, OperationState::CANCELED], true)) {
                    $this->reject('legacy-terminal');
                }
                $intent = ['options' => $changes, 'before' => $this->snapshot($operation)];
            }

            app(StockMutationGuard::class)->assertAllowed($action, collect([$operation]), $moves, $changes);
            $result = $callback();
            if ($terminal) {
                $saved = OperationResult::create([
                    'operation_id' => $operation->id,
                    'company_id'   => $operation->company_id,
                    'actor_id'     => auth()->id(),
                    'action'       => $action,
                    'version'      => 1,
                    'intent'       => $intent,
                    'result'       => $this->terminalSnapshot($operation, $intent['before']['backorder_ids']),
                    'created_at'   => now(),
                ]);
                $result->setRelation('terminalResult', $saved->refresh());
                if ($action === 'complete') {
                    OperationDone::dispatch($result);
                } else {
                    OperationCanceled::dispatch($result);
                }
            }

            return $result;
        });
    }

    protected function authorizeExecution(Operation $operation): void
    {
        $user = auth()->user()?->fresh();
        abort_unless($user && $user->is_active && in_array((int) $operation->company_id, app(CompanyContext::class)->activeIds(), true), 403);
        abort_unless(
            Gate::forUser($user)->allows('bypass_company_scope')
                || $user->allowedCompanies()->whereKey($operation->company_id)->exists(),
            403,
        );
        $model = match ($operation->operationType?->type) {
            OperationType::OUTGOING => Delivery::class,
            OperationType::INCOMING => Receipt::class,
            OperationType::INTERNAL => InternalTransfer::class,
            OperationType::DROPSHIP => Dropship::class,
            default                 => null,
        };
        abort_unless($model !== null, 403);
        Gate::forUser($user)->authorize('update', $model::query()->findOrFail($operation->id));
    }

    protected function matchesOptions(string $action, array $saved, array $requested): bool
    {
        if ($action === 'cancel') {
            return $saved === [] && $requested === [];
        }

        return ($saved['cancel_backorder'] ?? null) === $requested['cancel_backorder']
            && $this->normalizeCounts($saved['counted_line_quantities'] ?? null) === $requested['counted_line_quantities'];
    }

    protected function normalizeCounts(?array $counts): ?array
    {
        if ($counts === null) {
            return null;
        }
        $normalized = [];
        foreach ($counts as $id => $quantity) {
            if (! ctype_digit((string) $id) || (int) $id < 1
                || (! is_int($quantity) && ! is_float($quantity))
                || ! is_finite((float) $quantity) || $quantity < 0) {
                $this->reject('invalid-counts');
            }
            $normalized[(int) $id] = (float) $quantity;
        }
        ksort($normalized);

        return $normalized;
    }

    protected function applyCounts(Operation $operation, ?array $counts): void
    {
        if ($counts === null) {
            return;
        }
        $lines = $operation->moveLines()->orderBy('id')->lockForUpdate()->get();
        if (array_diff(array_keys($counts), $lines->modelKeys()) !== []) {
            $this->reject('invalid-counts');
        }
        foreach ($lines as $line) {
            if (($counts[$line->id] ?? 0.0) > (float) $line->qty) {
                $this->reject('invalid-counts');
            }
        }
        foreach ($lines as $line) {
            $quantity = $counts[$line->id] ?? 0.0;
            if ((float) $line->qty !== $quantity) {
                $line->update(['qty' => $quantity]);
            }
        }
        $operation->load('moves');
    }

    /** @return array<string, mixed> */
    protected function snapshot(Operation $operation): array
    {
        $operation->refresh();

        return [
            'operation' => $operation->only(['id', 'name', 'origin', 'move_type', 'company_id', 'operation_type_id', 'partner_id', 'owner_id', 'source_location_id', 'destination_location_id', 'state', 'closed_at']),
            'moves'     => $operation->moves()->with(['product.uom', 'uom', 'lines' => fn ($query) => $query->orderBy('id')])->orderBy('id')->get()->map(fn (Move $move): array => [
                'stock_uom_id'        => $move->product->uom_id,
                'unit_factor'         => $move->uom->factor,
                'unit_rounding'       => $move->uom->rounding,
                'stock_unit_factor'   => $move->product->uom->factor,
                'stock_unit_rounding' => $move->product->uom->rounding,
                ...$move->only(['id', 'company_id', 'product_id', 'uom_id', 'quantity', 'product_qty', 'product_uom_qty', 'state', 'source_location_id', 'destination_location_id', 'partner_id']),
                'lines' => $move->lines->map(fn (MoveLine $line): array => $line->only(['id', 'product_id', 'uom_id', 'qty', 'uom_qty', 'lot_id', 'package_id', 'result_package_id', 'partner_id', 'state', 'source_location_id', 'destination_location_id']))->all(),
            ])->all(),
            'backorder_ids' => Operation::query()->where('back_order_id', $operation->id)->orderBy('id')->pluck('id')->all(),
        ];
    }

    protected function terminalSnapshot(Operation $operation, array $previousBackorderIds): array
    {
        $result = $this->snapshot($operation);
        $result['created_backorder_ids'] = array_values(array_diff($result['backorder_ids'], $previousBackorderIds));

        return $result;
    }

    protected function reject(string $key): never
    {
        throw ValidationException::withMessages(['operation' => __('inventories::system.inventory-manager.'.$key)]);
    }

    protected function markEverythingPickedIfNeeded(Operation $operation): void
    {
        $hasQuantity = false;

        $hasPick = false;

        foreach ($operation->moves as $move) {
            if ($move->quantity) {
                $hasQuantity = true;
            }

            if ($move->is_scraped) {
                continue;
            }

            if ($move->is_picked) {
                $hasPick = true;
            }

            if ($hasQuantity && $hasPick) {
                break;
            }
        }

        if ($hasQuantity && ! $hasPick) {
            $operation->moves->each->update(['is_picked' => true]);
        }
    }

    protected function refreshState(Operation $operation): void
    {
        $operation->refresh();

        $operation->computeState();

        $operation->save();
    }

    protected function crossLink(Operation $operation, Operation $return): void
    {
        $sourceUrl = OperationResource::getUrl('view', ['record' => $operation]);

        $return->addMessage([
            'body' => "This transfer has been created from <a href=\"{$sourceUrl}\" target=\"_blank\" class=\"text-primary-600 dark:text-primary-400\">{$operation->name}</a>.",
            'type' => 'comment',
        ]);

        $returnUrl = OperationResource::getUrl('view', ['record' => $return]);

        $operation->addMessage([
            'body' => "The return <a href=\"{$returnUrl}\" target=\"_blank\" class=\"text-primary-600 dark:text-primary-400\">{$return->name}</a> has been created.",
            'type' => 'comment',
        ]);
    }
}

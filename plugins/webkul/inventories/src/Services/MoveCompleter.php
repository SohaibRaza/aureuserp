<?php

namespace Webkul\Inventory\Services;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Webkul\Inventory\Enums\MoveState;
use Webkul\Inventory\Enums\OperationType;
use Webkul\Inventory\Enums\ProcureMethod;
use Webkul\Inventory\Enums\ProductTracking;
use Webkul\Inventory\Enums\ReservationMethod;
use Webkul\Inventory\Models\Lot;
use Webkul\Inventory\Models\Move;
use Webkul\Inventory\Models\MoveLine;
use Webkul\Inventory\Models\Operation;
use Webkul\Inventory\Models\ProductQuantity;
use Webkul\Inventory\Support\StockMutationGuard;

class MoveCompleter
{
    public function complete(Collection $moves, bool $cancelBackorder = false): Collection
    {
        return (new Move)->getConnection()->transaction(function () use ($moves, $cancelBackorder): Collection {
            $moves = Move::lockForStockMutation($moves, validateMutation: false);

            if ($moves->contains(fn (Move $move) => $move->state === MoveState::CANCELED)) {
                throw ValidationException::withMessages(['operation' => __('inventories::system.inventory-manager.canceled-completion')]);
            }

            $alreadyCompleted = $moves->where('state', MoveState::DONE);
            $moves = $moves->reject(fn (Move $move) => $move->state === MoveState::DONE);
            if ($moves->isEmpty()) {
                return $alreadyCompleted;
            }
            $operations = Operation::query()->whereKey($moves->pluck('operation_id')->filter()->unique())->orderBy('id')->get();
            app(StockMutationGuard::class)->assertAllowed('complete', $operations, $moves, ['cancel_backorder' => $cancelBackorder]);

            $reconfirmed = app(MoveConfirmer::class)->confirm(
                $moves->filter(fn (Move $move) => $move->state === MoveState::DRAFT
                    || float_is_zero($move->product_uom_qty, precisionRounding: $move->uom->rounding)),
                merge: false
            );

            $moves = $moves->merge($reconfirmed)
                ->filter(fn (Move $move) => ! in_array($move->state, [MoveState::DONE, MoveState::CANCELED]));

            $this->dropUnpickedLines($moves, $cancelBackorder);

            $completable = $moves->filter(fn (Move $move) => ! (
                $move->state === MoveState::CANCELED
                || ($move->quantity <= 0 && ! $move->is_inventory)
                || ! $move->is_picked
            ));

            if (! $cancelBackorder) {
                app(BackorderCreator::class)->splitShortMoves($completable);
            }

            // Transparent rules must choose the actual destination before stock is settled.
            $pushable = $completable->filter(fn (Move $move) => ! $move->skipsPushRules());

            if ($pushable->isNotEmpty()) {
                app(PushRuleRunner::class)->run($pushable);
            }

            $this->completeLines($completable->flatMap->lines->sortBy('id'));

            $this->assertPackagesStayWhole($completable);

            if ($completable->flatMap->lines->some(fn (MoveLine $line) => $line->package_id && $line->package_id === $line->result_package_id)) {
                ProductQuantity::purgeEmpty($completable->pluck('product_id')->unique()->all());
            }

            $operation = $completable->pluck('operation')->unique()->filter()->first();

            $completable->each->update(['state' => MoveState::DONE, 'scheduled_at' => now()]);

            $this->reserveDownstream($completable);

            $this->reserveWaitingOnRestock($completable);

            if ($operation && ! $cancelBackorder) {
                $backorder = app(BackorderCreator::class)->createFor($operation);

                if ($backorder?->moves->some(fn (Move $move) => $move->state === MoveState::ASSIGNED)) {
                    app(PackageLevelSynchronizer::class)->syncFor($backorder);
                }
            }

            $completable->each(fn (Move $move) => $move->assertSerialUniqueness());

            return $completable->merge($alreadyCompleted);
        });
    }

    /**
     * Validate reserved physical stock for a controlled issue. Native workflows may
     * still use their existing negative-stock policy when this check is not requested.
     *
     * @param  Collection<int, Move>  $moves
     * @param  array<int, float>|null  $countedLineQuantities  Quantities in each movement line's unit.
     */
    public function assertReservedStockAvailable(Collection $moves, ?array $countedLineQuantities = null): void
    {
        $moves = Move::lockForStockMutation($moves, validateMutation: false);
        $lines = MoveLine::query()->whereIn('move_id', $moves->pluck('id')->all())
            ->whereNotIn('state', [MoveState::DONE, MoveState::CANCELED])
            ->with(['product.uom', 'uom', 'move'])->orderBy('id')->lockForUpdate()->get()
            ->filter(fn (MoveLine $line): bool => $line->product->is_storable);
        $groups = $lines->groupBy(fn (MoveLine $line): string => json_encode([
            $line->company_id, $line->product_id, $line->source_location_id,
            $line->lot_id, $line->package_id, $line->move->restrict_partner_id,
        ], JSON_THROW_ON_ERROR));

        foreach ($groups as $group) {
            $line = $group->first();
            $reservedByLines = (float) $group->sum('uom_qty');
            $required = $countedLineQuantities === null ? $reservedByLines : $group->sum(
                fn (MoveLine $item): float => (float) $item->uom->computeQuantity(
                    $countedLineQuantities[$item->id] ?? 0.0, $item->product->uom, roundingMethod: 'HALF-UP',
                ),
            );
            if ($required <= 0) {
                continue;
            }
            $stock = ProductQuantity::query()->where('company_id', $line->company_id)
                ->where('product_id', $line->product_id)->where('location_id', $line->source_location_id)
                ->where('lot_id', $line->lot_id)->where('package_id', $line->package_id)
                ->whereNull('partner_id')->lockForUpdate()->first();
            $reservedAfterCounting = (float) ($stock?->reserved_quantity ?? 0) - $reservedByLines + $required;

            // Unit rounding must not hide a shortage in the four-decimal stock balance.
            // Native settlement currently supports Company-owned balances only.
            if ($line->move->restrict_partner_id !== null || ! $stock
                || float_compare((float) $stock->reserved_quantity, $reservedByLines, precisionDigits: 4) < 0
                || float_compare((float) $stock->quantity, $required, precisionDigits: 4) < 0
                || float_compare((float) $stock->quantity, $reservedAfterCounting, precisionDigits: 4) < 0) {
                throw ValidationException::withMessages([
                    'operation' => __('inventories::system.inventory-manager.stock-not-ready'),
                ]);
            }
        }
    }

    public function completeLines(Collection $lines): void
    {
        if ($lines->isEmpty()) {
            return;
        }

        (new Move)->getConnection()->transaction(function () use ($lines): void {
            $ids = $lines->pluck('id')->unique();
            $moves = Move::query()->whereKey($lines->pluck('move_id')->unique())->get();
            $lockedMoves = Move::lockForStockMutation(
                $moves,
                $lines->pluck('product_id')->filter()->unique()->all(),
                validateMutation: false,
            );
            $lockedLines = MoveLine::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();

            if ($lockedLines->count() !== $ids->count()
                || $lockedLines->contains(fn (MoveLine $line) => ! $lockedMoves->contains('id', $line->move_id))) {
                throw new \RuntimeException(__('inventories::system.inventory-manager.stale-operation'));
            }

            if ($lockedLines->contains(fn (MoveLine $line) => $line->state === MoveState::CANCELED)) {
                throw ValidationException::withMessages(['operation' => __('inventories::system.inventory-manager.canceled-completion')]);
            }

            foreach ($lines as $submitted) {
                if ($lockedLines->firstWhere('id', $submitted->id)?->state === MoveState::DONE
                    && $submitted->isDirty(['qty', 'uom_qty', 'uom_id', 'product_id', 'move_id', 'operation_id', 'source_location_id', 'destination_location_id', 'lot_id', 'package_id', 'result_package_id', 'partner_id', 'company_id'])) {
                    throw ValidationException::withMessages(['operation' => __('inventories::system.inventory-manager.terminal-change')]);
                }
            }
            $pending = $lockedLines->reject(fn (MoveLine $line) => $line->state === MoveState::DONE);
            if ($pending->isNotEmpty()) {
                $pendingMoves = $lockedMoves->whereIn('id', $pending->pluck('move_id'));
                $operations = Operation::query()->whereKey($pendingMoves->pluck('operation_id')->filter()->unique())->orderBy('id')->get();
                app(StockMutationGuard::class)->assertAllowed('complete.lines', $operations, $pendingMoves);
                $this->completeLockedLines($pending);
            }
        });
    }

    protected function completeLockedLines(Collection $lines): void
    {
        $missingLotIds = collect();

        $emptyLineIds = collect();

        $needingNewLot = collect();

        $creatableLotLines = [];

        foreach ($lines as $line) {
            $this->assertRoundingPrecision($line);

            $comparison = float_compare($line->qty, 0, precisionRounding: $line->uom->rounding);

            if ($comparison < 0) {
                throw new \Exception(__('inventories::system.inventory-manager.validate.no-negative-quantities'));
            }

            if ($comparison === 0) {
                if (! $line->is_inventory) {
                    $emptyLineIds->push($line->id);
                }

                continue;
            }

            if ($line->product->tracking === ProductTracking::QTY) {
                continue;
            }

            $operationType = $line->move->operationType;

            if (! $operationType && ! $line->is_inventory && ! $line->lot_id) {
                $missingLotIds->push($line->id);

                continue;
            }

            if (! $operationType || $line->lot_id || (! $operationType->use_create_lots && ! $operationType->use_existing_lots)) {
                continue;
            }

            if ($operationType->use_create_lots) {
                $creatableLotLines[$line->product_id.'_'.$line->company_id][] = $line->id;
            } else {
                $missingLotIds->push($line->id);
            }
        }

        $this->matchExistingLots($creatableLotLines, $missingLotIds, $needingNewLot);

        if ($missingLotIds->isNotEmpty()) {
            throw new \Exception(__('inventories::system.inventory-manager.validate.missing-lot-serial-number', [
                'products' => MoveLine::whereIn('id', $missingLotIds)->get()
                    ->pluck('product.name')
                    ->map(fn ($name) => "- $name")
                    ->implode("\n"),
            ]));
        }

        if ($needingNewLot->isNotEmpty()) {
            MoveLine::whereIn('id', $needingNewLot)->orderBy('id')->get()->each->assignLotFromName();
        }

        MoveLine::whereIn('id', $emptyLineIds)->get()->each(fn (MoveLine $line) => $line->delete());

        $this->settleStock($lines->reject(fn (MoveLine $line) => $emptyLineIds->contains($line->id)));
    }

    protected function assertRoundingPrecision(MoveLine $line): void
    {
        $roundedToUom = float_round($line->qty, precisionRounding: $line->uom->rounding, roundingMethod: 'HALF-UP');

        $roundedToDigits = float_round($line->qty, precisionDigits: 2, roundingMethod: 'HALF-UP');

        if (float_compare($roundedToUom, $roundedToDigits, precisionDigits: 2) !== 0) {
            throw new \Exception(__('inventories::system.inventory-manager.validate.quantity-rounding-mismatch', [
                'product' => $line->product->name,
                'unit'    => $line->uom->name,
            ]));
        }
    }

    protected function matchExistingLots(array $creatableLotLines, Collection $missingLotIds, Collection $needingNewLot): void
    {
        foreach ($creatableLotLines as $key => $lineIds) {
            [$productId, $companyId] = explode('_', $key);

            $lines = MoveLine::whereIn('id', $lineIds)->orderBy('id')->get();

            $lots = Lot::query()
                ->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $companyId))
                ->where('product_id', $productId)
                ->whereIn('name', $lines->pluck('lot_name')->filter()->all())
                ->get()
                ->keyBy('name');

            foreach ($lines as $line) {
                $lot = $lots->get($line->lot_name);

                if ($lot) {
                    $line->update(['lot_id' => $lot->id]);
                } elseif ($line->lot_name) {
                    $needingNewLot->push($line->id);
                } else {
                    $missingLotIds->push($line->id);
                }
            }
        }
    }

    protected function settleStock(Collection $lines): void
    {
        $settledIds = collect();

        foreach ($lines as $line) {
            $line->refresh();

            $line->applyQuantityChange(
                -$line->uom_qty,
                $line->sourceLocation,
                reserved: true,
                lot: $line->lot,
                package: $line->package,
            );

            [$availableQty, $incomingDate] = $line->applyQuantityChange(
                -$line->uom_qty,
                $line->sourceLocation,
                lot: $line->lot,
                package: $line->package,
            );

            $line->applyQuantityChange(
                $line->uom_qty,
                $line->destinationLocation,
                incomingDate: $incomingDate,
                lot: $line->lot,
                package: $line->resultPackage,
            );

            if ($availableQty < 0) {
                $line->releaseCompetingReservations(
                    product: $line->product,
                    location: $line->sourceLocation,
                    quantity: abs($availableQty),
                    lot: $line->lot,
                    package: $line->package,
                    excludedLineIds: $settledIds,
                );
            }

            $settledIds->push($line->id);
        }

        $lines->each->update(['scheduled_at' => now(), 'state' => MoveState::DONE]);
    }

    protected function dropUnpickedLines(Collection $moves, bool $cancelBackorder): void
    {
        $lineIds = collect();

        foreach ($moves as $move) {
            if ($move->is_picked) {
                $lineIds = $lineIds->merge(
                    $move->lines->reject(fn (MoveLine $line) => $line->is_picked)->pluck('id')
                );
            }

            $unfulfilled = ($move->quantity <= 0 || ! $move->is_picked) && ! $move->is_inventory;

            $abandoned = float_is_zero($move->product_uom_qty, precisionRounding: $move->uom->rounding) || $cancelBackorder;

            if ($unfulfilled && $abandoned) {
                app(MoveCanceller::class)->cancel(collect([$move]));
            }
        }

        MoveLine::whereIn('id', $lineIds)->get()->each(fn (MoveLine $line) => $line->delete());
    }

    protected function assertPackagesStayWhole(Collection $moves): void
    {
        $packages = $moves->flatMap->lines
            ->filter(fn (MoveLine $line) => $line->is_picked)
            ->pluck('resultPackage')
            ->filter()
            ->unique('id')
            ->filter(fn ($package) => $package->quantities->count() > 1);

        foreach ($packages as $package) {
            $locationCount = $package->quantities
                ->filter(fn (ProductQuantity $stock) => float_compare($stock->quantity, 0.0, precisionRounding: $stock->uom->rounding) > 0)
                ->pluck('location_id')
                ->unique()
                ->count();

            if ($locationCount > 1) {
                throw new \Exception(__('inventories::filament/clusters/operations/actions/validate.notification.warning.partial-package.body'));
            }
        }
    }

    protected function reserveDownstream(Collection $moves): void
    {
        $moves->load('moveDestinations');

        $moves->flatMap->moveDestinations
            ->groupBy('company_id')
            ->each(fn (Collection $group) => app(MoveReserver::class)->reserve($group));
    }

    protected function reserveWaitingOnRestock(Collection $moves): void
    {
        $restocked = $moves->filter(fn (Move $move) => in_array(
            $move->operationType?->type,
            [OperationType::INCOMING, OperationType::INTERNAL],
            true
        ));

        if ($restocked->isEmpty()) {
            return;
        }

        $pairs = $restocked
            ->map(fn (Move $move) => [$move->product_id, $move->destination_location_id])
            ->unique(fn (array $pair) => $pair[0].'-'.$pair[1]);

        $waiting = Move::query()
            ->whereIn('state', [MoveState::CONFIRMED, MoveState::PARTIALLY_ASSIGNED])
            ->where('procure_method', ProcureMethod::MAKE_TO_STOCK)
            ->where(function ($query) use ($pairs) {
                foreach ($pairs as [$productId, $locationId]) {
                    $query->orWhere(fn ($nested) => $nested
                        ->where('product_id', $productId)
                        ->where('source_location_id', $locationId));
                }
            })
            ->where(function ($query) {
                $query->whereDate('reservation_date', '<=', now())
                    ->orWhereHas('operationType', fn ($type) => $type->where('reservation_method', ReservationMethod::AT_CONFIRM));
            })
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get();

        if ($waiting->isNotEmpty()) {
            app(MoveReserver::class)->reserve($waiting);
        }
    }
}

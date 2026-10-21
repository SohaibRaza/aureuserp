<?php

namespace Webkul\Sale\Services;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Webkul\Inventory\Enums as InventoryEnums;
use Webkul\Inventory\Facades\Inventory as InventoryFacade;
use Webkul\Inventory\Models\Location;
use Webkul\Inventory\Models\Packaging as InventoryPackaging;
use Webkul\Inventory\Models\Product as InventoryProduct;
use Webkul\Inventory\Support\ProcurementOptions;
use Webkul\Inventory\Support\ProcurementRequest;
use Webkul\PluginManager\Package;
use Webkul\Product\Enums as ProductEnums;
use Webkul\Sale\Enums\OrderState;
use Webkul\Sale\Models\Order;
use Webkul\Sale\Models\OrderLine;
use Webkul\Support\Models\UOM;

class ProcurementRequester
{
    public function requestForLines($lines, $previousProductUOMQty = false): void
    {
        if (! Package::isPluginInstalled('inventories')) {
            return;
        }

        $lines = collect($lines);
        (new Order)->getConnection()->transaction(function () use ($lines): void {
            Order::query()->whereKey($lines->pluck('order_id')->unique())->orderBy('id')->lockForUpdate()->get();
            $lockedLines = OrderLine::query()->whereKey($lines->pluck('id'))->orderBy('id')->lockForUpdate()->get();
            InventoryProduct::withTrashed()->whereKey($lockedLines->pluck('product_id')->filter()->unique())
                ->orderBy('id')->lockForUpdate()->get();

            $this->requestForLockedLines($lockedLines);
        });
    }

    protected function requestForLockedLines(Collection $lines): void
    {
        $requests = collect();

        foreach ($lines as $line) {
            $line->refresh();

            if (! $this->needsProcurement($line)) {
                continue;
            }

            $snapshot = $line->currentQuantityCommitment?->data;
            if (! $snapshot) {
                throw ValidationException::withMessages(['products' => __('sales::models/quantity-commitment.unverified')]);
            }
            if ($snapshot['stock_unit']['id'] !== $line->product->uom_id) {
                throw ValidationException::withMessages(['products' => __('sales::models/quantity-commitment.stock-unit')]);
            }
            $procurementUom = UOM::findOrFail($snapshot['stock_unit']['id']);
            $procurementUom->factor = $snapshot['stock_unit']['factor'];
            $procurementUom->rounding = $snapshot['stock_unit']['rounding'];
            [$outgoing, $incoming] = $this->outgoingAndIncomingMoves($line, strict: false);
            $this->assertIssuedQuantity($line, $outgoing, $incoming, $procurementUom, (float) $snapshot['stock_quantity']);
            $quantityIn = function ($move) use ($procurementUom): float {
                return $move->uom->computeQuantity(
                    $move->state === InventoryEnums\MoveState::DONE ? $move->quantity : $move->product_uom_qty,
                    $procurementUom,
                    round: false,
                );
            };
            $alreadyProcured = $outgoing->sum($quantityIn) - $incoming->sum($quantityIn);

            if (abs($alreadyProcured - $snapshot['stock_quantity']) < 0.0000001) {
                continue;
            }

            $group = $this->syncProcurementGroup($line);

            $productQty = $snapshot['stock_quantity'] - $alreadyProcured;

            $requests->push($this->buildProcurementRequest($line, $productQty, $procurementUom, $this->originFor($line), $group));
        }

        InventoryFacade::runProcurements($requests);
    }

    protected function assertIssuedQuantity(OrderLine $line, Collection $outgoing, Collection $incoming, UOM $stockUnit, float $proposedQuantity): void
    {
        $netIssued = 0.0;
        foreach ($outgoing->where('state', InventoryEnums\MoveState::DONE) as $source) {
            if ((int) $source->company_id !== (int) $line->company_id
                || $source->uom->category_id !== $stockUnit->category_id
                || ! is_numeric($source->quantity) || ! is_finite((float) $source->quantity)
                || $source->quantity < 0 || ! is_finite((float) $source->uom->factor) || $source->uom->factor <= 0) {
                throw ValidationException::withMessages(['products' => __('sales::models/quantity-commitment.issued-quantity')]);
            }
            $issued = $source->uom->computeQuantity($source->quantity, $stockUnit, round: false);
            $returned = 0.0;
            foreach ($incoming->where('state', InventoryEnums\MoveState::DONE) as $return) {
                if ((int) $return->origin_returned_move_id !== (int) $source->id
                    || (int) $return->company_id !== (int) $line->company_id
                    || (int) $source->company_id !== (int) $line->company_id
                    || (int) $return->product_id !== (int) $source->product_id
                    || (int) $return->source_location_id !== (int) $source->destination_location_id
                    || $return->destinationLocation->type !== InventoryEnums\LocationType::INTERNAL
                    || $return->uom->category_id !== $source->uom->category_id
                    || ! is_finite((float) $return->uom->factor) || $return->uom->factor <= 0) {
                    continue;
                }
                $quantity = $return->uom->computeQuantity($return->quantity, $stockUnit, round: false);
                if (is_finite($quantity) && $quantity > 0) {
                    $returned += $quantity;
                }
            }
            // Ambiguous or excessive historical returns do not authorize a commercial reduction.
            $netIssued += $returned <= $issued + 0.0000001 ? max(0, $issued - $returned) : $issued;
        }
        if ($proposedQuantity + 0.0000001 < $netIssued) {
            throw ValidationException::withMessages(['products' => __('sales::models/quantity-commitment.issued-quantity')]);
        }
    }

    protected function needsProcurement(OrderLine $line): bool
    {
        return app(QuantityCommitmentRecorder::class)->applicable($line)
            && $line->state === OrderState::SALE
            && ! $line->order->locked
            && $line->product?->type === ProductEnums\ProductType::GOODS;
    }

    protected function originFor(OrderLine $line): string
    {
        return $line->order->client_order_ref
            ? "{$line->order->name} - {$line->order->client_order_ref}"
            : $line->order->name;
    }

    protected function syncProcurementGroup(OrderLine $line)
    {
        $group = $line->order->procurementGroup;

        if (! $group) {
            $group = $line->order->procurementGroup()->create([
                'name'          => $line->order->name,
                'move_type'     => $line->order->picking_policy,
                'partner_id'    => $line->order->partner_shipping_id,
                'sale_order_id' => $line->order->id,
            ]);

            $line->order->procurement_group_id = $group->id;

            $line->order->save();

            return $group;
        }

        if ($group->partner_id !== $line->order->partner_shipping_id) {
            $group->update(['partner_id' => $line->order->partner_shipping_id]);
        }

        if ($group->move_type !== $line->order->picking_policy) {
            $group->update(['move_type' => $line->order->picking_policy]);
        }

        return $group;
    }

    public function outgoingAndIncomingMoves(OrderLine $orderLine, bool $strict = true): array
    {
        $moves = $orderLine->inventoryMoves->filter(
            fn ($move) => $move->state != InventoryEnums\MoveState::CANCELED
                && ! $move->is_scraped
                && $orderLine->product_id == $move->product_id
        );

        $triggeringRuleIds = $strict ? [] : $this->triggeringRuleIds($moves);

        $outgoingIds = [];

        $incomingIds = [];

        foreach ($moves as $move) {
            if ($this->isOutgoing($move, $strict, $triggeringRuleIds)) {
                if (! $move->origin_returned_move_id || $move->is_refund) {
                    $outgoingIds[] = $move->id;
                }
            } elseif ($move->sourceLocation->type == InventoryEnums\LocationType::CUSTOMER && $move->is_refund) {
                $incomingIds[] = $move->id;
            }
        }

        return [
            $moves->whereIn('id', $outgoingIds),
            $moves->whereIn('id', $incomingIds),
        ];
    }

    protected function triggeringRuleIds($moves): array
    {
        if ($moves->isEmpty()) {
            return [];
        }

        $ruleIds = [];

        $seenWarehouseIds = [];

        foreach ($moves->sortBy('id') as $move) {
            if (! in_array($move->warehouse->id, $seenWarehouseIds)) {
                $ruleIds[] = $move->rule_id;

                $seenWarehouseIds[] = $move->warehouse_id;
            }
        }

        return $ruleIds;
    }

    protected function isOutgoing($move, bool $strict, array $triggeringRuleIds): bool
    {
        if ($strict) {
            return $move->destinationLocation->type == InventoryEnums\LocationType::CUSTOMER;
        }

        return in_array($move->rule_id, $triggeringRuleIds)
            && ($move->finalLocation?->type ?? $move->destinationLocation->type) == InventoryEnums\LocationType::CUSTOMER;
    }

    public function procuredQuantity(OrderLine $line, $previousProductUOMQty = false)
    {
        [$outgoingMoves, $incomingMoves] = $this->outgoingAndIncomingMoves($line, strict: false);

        $quantityIn = fn ($move) => $move->uom->computeQuantity(
            $move->state === InventoryEnums\MoveState::DONE ? $move->quantity : $move->product_uom_qty,
            $line->uom,
            roundingMethod: 'HALF-UP'
        );

        return $outgoingMoves->sum($quantityIn) - $incomingMoves->sum($quantityIn);
    }

    public function buildProcurementOptions(OrderLine $line, $procurementGroup = null): ProcurementOptions
    {
        $deadline = $line->order->commitment_date ?? $line->expected_date;

        // TODO: This value will be set in the configuration
        $datePlanned = $deadline->subDays(0);

        return ProcurementOptions::make()
            ->group($procurementGroup)
            ->linkSaleOrderLine($line->id)
            ->plannedAt($datePlanned)
            ->deadline($deadline)
            ->routes($line->route ? collect([$line->route]) : collect())
            ->warehouse($line->warehouse)
            ->partner($line->order->partner)
            ->company($line->company)
            ->packaging($line->product_packaging_id ? InventoryPackaging::find($line->product_packaging_id) : null)
            ->finalLocation($this->customerLocation());
    }

    public function buildProcurementRequest(
        OrderLine $line,
        $productQty,
        $procurementUom,
        $origin,
        $procurementGroup = null
    ): ProcurementRequest {
        $options = $this->buildProcurementOptions($line, $procurementGroup);

        return new ProcurementRequest(
            product: InventoryProduct::withTrashed()->find($line->product_id),
            quantity: $productQty,
            uom: $procurementUom,
            location: $options->destinationLocation(),
            name: $line->product->name,
            origin: $origin,
            company: $line->company,
            options: $options,
        );
    }

    protected function customerLocation(): ?Location
    {
        return Location::where('type', InventoryEnums\LocationType::CUSTOMER)->first();
    }

    public function cancelOperations(Order $record): void
    {
        if (! Package::isPluginInstalled('inventories') || $record->operations->isEmpty()) {
            return;
        }

        $record->operations
            ->filter(fn ($operation) => ! in_array($operation->state, [
                InventoryEnums\OperationState::DONE,
                InventoryEnums\OperationState::CANCELED,
            ]))
            ->each(fn ($operation) => InventoryFacade::cancelTransfer($operation));
    }
}

<?php

namespace Webkul\Sale\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use LogicException;
use Webkul\Inventory\Models\Warehouse;
use Webkul\Product\Models\Packaging;
use Webkul\Product\Models\Product;
use Webkul\Sale\Models\Order;
use Webkul\Sale\Models\OrderLine;
use Webkul\Sale\Models\QuantityCommitment;
use Webkul\Support\Models\UOM;

class QuantityCommitmentRecorder
{
    public const LINE_FIELDS = ['order_id', 'company_id', 'product_id', 'product_uom_id', 'product_qty', 'product_uom_qty', 'product_packaging_id', 'product_packaging_qty', 'warehouse_id', 'route_id', 'display_type', 'is_downpayment', 'is_expense'];

    public const ORDER_FIELDS = ['company_id', 'partner_id', 'partner_shipping_id', 'warehouse_id'];

    public function applicable(OrderLine $line): bool
    {
        return ! $line->display_type && ! $line->is_downpayment && ! $line->is_expense && $line->product_id !== null;
    }

    public function sourceChanged(OrderLine $line, OrderLine $stored): bool
    {
        foreach (self::LINE_FIELDS as $field) {
            if (! $this->equivalent($field, $line->getAttribute($field), $stored->getAttribute($field))) {
                return true;
            }
        }

        return false;
    }

    public function equivalent(string $field, mixed $left, mixed $right): bool
    {
        if (in_array($field, ['product_qty', 'product_uom_qty', 'product_packaging_qty'], true)) {
            if ($left === null || $right === null) {
                return $left === $right;
            }

            return is_numeric($left) && is_numeric($right)
                && is_finite((float) $left) && is_finite((float) $right)
                && (float) $left === (float) $right;
        }

        return $left == $right;
    }

    public function validateQuantity(mixed $quantity): void
    {
        if (! is_numeric($quantity) || ! is_finite((float) $quantity)) {
            $this->reject('quantity');
        }
    }

    /** The caller holds the order and source-line locks in the same transaction. */
    public function record(OrderLine $line, Order $order, bool $newConfirmation = false): ?QuantityCommitment
    {
        if ($line->getConnection()->transactionLevel() < 1) {
            throw new LogicException('Quantity commitments require the source order transaction.');
        }
        if (! $this->applicable($line)) {
            return null;
        }

        $previous = $line->quantityCommitments()->latest('revision')->first();
        $data = $this->describe($line, $order, $previous, $newConfirmation);
        $line->product_uom_qty = $data['stock_quantity'];
        $line->product_packaging_qty = $data['packaging']['count'] ?? null;
        // Derived columns are persisted without dispatching another source-line revision.
        $line->getConnection()->table($line->getTable())->where('id', $line->getKey())->update([
            'product_uom_qty'       => $line->product_uom_qty,
            'product_packaging_qty' => $line->product_packaging_qty,
        ]);
        $line->syncOriginalAttributes(['product_uom_qty', 'product_packaging_qty']);

        $snapshot = QuantityCommitment::forceCreate([
            'order_id'   => $order->getKey(), 'order_line_id' => $line->getKey(),
            'company_id' => $order->company_id, 'revision' => ($previous?->revision ?? 0) + 1,
            'data'       => $data, 'created_by' => Auth::id(), 'created_at' => now(),
        ]);
        $line->unsetRelation('currentQuantityCommitment');

        return $snapshot;
    }

    private function describe(OrderLine $line, Order $order, ?QuantityCommitment $previous, bool $newConfirmation): array
    {
        // Keep accepted conversion metadata stable until the source transaction commits.
        $product = Product::withTrashed()->lockForUpdate()->findOrFail($line->product_id);
        $units = UOM::query()->whereIn('id', array_filter([$line->product_uom_id, $product->uom_id]))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $unit = $units->get($line->product_uom_id);
        $stockUnit = $units->get($product->uom_id);
        if (! $order->company_id || (int) $line->company_id !== (int) $order->company_id
            || ($product->company_id && (int) $product->company_id !== (int) $order->company_id)
            || ($line->warehouse_id && ! Warehouse::query()->whereKey($line->warehouse_id)->where('company_id', $order->company_id)->exists())) {
            $this->reject('company');
        }
        if (! $unit || ! $stockUnit || $unit->category_id !== $stockUnit->category_id
            || ! $unit->category_id || ! is_finite((float) $unit->factor) || $unit->factor <= 0
            || ! is_finite((float) $stockUnit->factor) || $stockUnit->factor <= 0
            || $unit->rounding <= 0 || $stockUnit->rounding <= 0) {
            $this->reject('unit');
        }

        $old = $previous?->data;
        if ($old && $old['product_id'] === $product->id && $old['stock_unit']['id'] !== $product->uom_id) {
            $this->reject('stock-unit');
        }
        $reuse = ! $newConfirmation && $old && $old['product_id'] === $line->product_id
            && $old['commercial_unit']['id'] === $line->product_uom_id
            && $old['stock_unit']['id'] === $product->uom_id
            && ($old['packaging']['id'] ?? null) === $line->product_packaging_id;
        $commercialUnit = $reuse ? $old['commercial_unit'] : $this->unitData($unit);
        $stock = $reuse ? $old['stock_unit'] : $this->unitData($stockUnit);
        $factor = $reuse ? $old['stock_units_per_commercial_unit'] : $stockUnit->factor / $unit->factor;
        $this->validateQuantity($line->product_qty);
        $quantity = (float) $line->product_qty;
        if (! is_finite($quantity) || $quantity < 0 || ($quantity == 0 && ! $previous)) {
            $this->reject('quantity');
        }
        $stockQuantity = $quantity * $factor;
        if (! is_finite($stockQuantity) || ! $this->representable($quantity, $commercialUnit['rounding'])
            || ! $this->representable($stockQuantity, $stock['rounding'])
            || abs(round($stockQuantity, 4) - $stockQuantity) > 0.0000001
            || abs(round($quantity, 4) - $quantity) > 0.0000001
            || $quantity > 99999999999.9999 || $stockQuantity > 99999999999.9999) {
            $this->reject('precision');
        }
        $pack = null;
        if ($line->product_packaging_id) {
            $packaging = Packaging::query()->lockForUpdate()->find($line->product_packaging_id);
            if (! $packaging || (int) $packaging->product_id !== (int) $product->id
                || ($packaging->company_id && (int) $packaging->company_id !== (int) $order->company_id)) {
                $this->reject('packaging');
            }
            $packFactor = $reuse ? $old['packaging']['stock_units_per_pack'] : (float) $packaging->qty;
            if (! is_finite($packFactor) || $packFactor <= 0) {
                $this->reject('packaging');
            }
            $count = $stockQuantity / $packFactor;
            if (! $this->representable($count, 1)
                || ($line->product_packaging_qty !== null && ($newConfirmation || $line->wasChanged('product_packaging_qty'))
                    && abs((float) $line->product_packaging_qty - $count) > 0.0000001)) {
                $this->reject('whole-pack');
            }
            $pack = ['id'              => $packaging->id, 'name' => $reuse ? $old['packaging']['name'] : $packaging->name,
                'stock_units_per_pack' => $packFactor, 'count' => $count];
        }

        return [
            'product_id'                      => $product->id, 'product_name' => $product->name,
            'commercial_quantity'             => $quantity, 'commercial_unit' => $commercialUnit,
            'stock_quantity'                  => round($stockQuantity, 4), 'stock_unit' => $stock,
            'stock_units_per_commercial_unit' => $factor, 'rounding_method' => 'reject-unrepresentable',
            'packaging'                       => $pack, 'warehouse_id' => $line->warehouse_id, 'route_id' => $line->route_id,
            'customer_id'                     => $order->partner_id, 'shipping_partner_id' => $order->partner_shipping_id,
            'shipping_address'                => $old && ! $newConfirmation && $old['shipping_partner_id'] === $order->partner_shipping_id
                ? $old['shipping_address']
                : $order->partnerShipping?->only(['name', 'street1', 'street2', 'city', 'zip', 'state_id', 'country_id']),
        ];
    }

    private function unitData(UOM $unit): array
    {
        return ['id' => $unit->id, 'name' => $unit->name, 'category_id' => $unit->category_id,
            'factor' => (float) $unit->factor, 'rounding' => (float) $unit->rounding];
    }

    private function representable(float $quantity, float $rounding): bool
    {
        return is_finite($rounding) && $rounding > 0 && abs($quantity / $rounding - round($quantity / $rounding)) < 0.0000001;
    }

    private function reject(string $reason): never
    {
        throw ValidationException::withMessages(['products' => __('sales::models/quantity-commitment.'.$reason)]);
    }
}

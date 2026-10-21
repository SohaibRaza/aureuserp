<?php

namespace Webkul\Sale\Services;

use Illuminate\Validation\ValidationException;
use LogicException;
use Webkul\Sale\Enums\OrderState;
use Webkul\Sale\Models\Order;
use Webkul\Sale\Models\OrderLine;

class OrderSourceRevision
{
    public function token(Order $order): string
    {
        $lines = $order->relationLoaded('lines') ? $order->lines : $order->lines()->orderBy('id')->get();
        $lines->loadMissing('currentQuantityCommitment');
        $source = ['id' => $order->getKey(), 'state' => $order->state?->value];
        foreach (QuantityCommitmentRecorder::ORDER_FIELDS as $field) {
            $source[$field] = $this->normalize($field, $order->getAttribute($field));
        }
        $source['lines'] = $lines->sortBy('id')->map(function (OrderLine $line): array {
            $values = ['id' => $line->getKey(), 'commitment_id' => $line->currentQuantityCommitment?->id,
                'revision'  => $line->currentQuantityCommitment?->revision];
            foreach (QuantityCommitmentRecorder::LINE_FIELDS as $field) {
                $values[$field] = $this->normalize($field, $line->getAttribute($field));
            }

            return $values;
        })->values()->all();

        return 'v1:'.hash('sha256', json_encode($source, JSON_THROW_ON_ERROR));
    }

    /** The surrounding command transaction retains these locks until all source writes finish. */
    public function lock(Order $order): Order
    {
        if ($order->getConnection()->transactionLevel() < 1) {
            throw new LogicException('Source revision checks require an order transaction.');
        }
        $stored = $order->newQuery()->lockForUpdate()->findOrFail($order->getKey());
        $stored->setRelation('lines', $stored->lines()->orderBy('id')->lockForUpdate()->get());

        return $stored;
    }

    public function assertCurrent(Order $stored, ?string $expected, string $errorField = 'products'): void
    {
        if (! $expected || ! hash_equals($this->token($stored), $expected)) {
            throw ValidationException::withMessages([$errorField => __('sales::models/quantity-commitment.conflict')]);
        }
    }

    public function requiresCheck(Order $order, array $data): bool
    {
        if ($order->state !== OrderState::SALE) {
            return false;
        }
        foreach (QuantityCommitmentRecorder::ORDER_FIELDS as $field) {
            if (array_key_exists($field, $data) && $data[$field] != $order->getAttribute($field)) {
                return true;
            }
        }
        if (! array_key_exists('lines', $data)) {
            return false;
        }
        $lines = $order->lines->keyBy('id');
        if ($lines->count() !== count($data['lines']) || $lines->keys()->sort()->values()->all() !== collect($data['lines'])->pluck('id')->filter()->map(fn ($id): int => (int) $id)->sort()->values()->all()) {
            return true;
        }
        $recorder = app(QuantityCommitmentRecorder::class);
        foreach ($data['lines'] as $submitted) {
            $line = $lines->get($submitted['id']);
            foreach (QuantityCommitmentRecorder::LINE_FIELDS as $field) {
                if (array_key_exists($field, $submitted) && ! $recorder->equivalent($field, $submitted[$field], $line->getAttribute($field))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalize(string $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if (in_array($field, ['product_qty', 'product_uom_qty', 'product_packaging_qty'], true) && is_numeric($value) && is_finite((float) $value)) {
            return number_format((float) $value, 4, '.', '');
        }
        if (in_array($field, ['is_downpayment', 'is_expense'], true)) {
            return (bool) $value;
        }

        return is_scalar($value) ? (string) $value : $value;
    }
}

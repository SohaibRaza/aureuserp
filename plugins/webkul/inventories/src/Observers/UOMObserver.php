<?php

namespace Webkul\Inventory\Observers;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Webkul\Inventory\Enums\MoveState;
use Webkul\Inventory\Models\Move;
use Webkul\Inventory\Models\MoveLine;
use Webkul\Inventory\Models\Scrap;
use Webkul\PluginManager\Package;
use Webkul\Support\Models\UOM;

class UOMObserver
{
    public function saving(UOM $uom): void
    {
        if (! Package::isPluginInstalled('inventories')) {
            return;
        }

        if (! $uom->exists) {
            return;
        }

        if (! $uom->isDirty(['factor', 'category_id', 'rounding'])) {
            return;
        }

        if (! $this->isUsedByStock($uom)) {
            return;
        }

        throw ValidationException::withMessages([
            "data.uoms.record-{$uom->id}.".($uom->isDirty('rounding') ? 'rounding' : 'ratio') => __('inventories::observers/uom.'.($uom->isDirty('rounding') ? 'rounding-change' : 'ratio-change')),
        ]);
    }

    public function deleting(UOM $uom): void
    {
        if (! Package::isPluginInstalled('inventories')) {
            return;
        }

        $isReferenced = Move::where('uom_id', $uom->id)->exists()
            || MoveLine::where('uom_id', $uom->id)->exists()
            || Scrap::where('uom_id', $uom->id)->exists();

        if (! $isReferenced) {
            return;
        }

        throw new Exception(__('inventories::observers/uom.in-use', [
            'uom' => $uom->name,
        ]));
    }

    protected function isUsedByStock(UOM $uom): bool
    {
        $categoryIds = array_unique(array_filter([
            $uom->category_id,
            $uom->getOriginal('category_id'),
        ]));

        return MoveLine::query()
            ->whereNot('state', MoveState::CANCELED)
            ->where(function (Builder $query) use ($uom, $categoryIds): void {
                $query->where('uom_id', $uom->id);

                if ($categoryIds !== []) {
                    $query->orWhereHas('uom', fn (Builder $uomQuery) => $uomQuery->whereIn('category_id', $categoryIds));
                }
            })
            ->exists();
    }
}

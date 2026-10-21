<?php

namespace Shafique\Logistics\Services;

use Illuminate\Support\Collection;
use Shafique\Logistics\Models\Trip;
use Webkul\Inventory\Enums\LocationType;
use Webkul\Inventory\Enums\MoveState;
use Webkul\Inventory\Enums\OperationType;
use Webkul\Inventory\Models\Location;
use Webkul\Inventory\Models\Move;
use Webkul\PluginManager\Models\Plugin;
use Webkul\Sale\Enums\OrderState;
use Webkul\Sale\Models\OrderLine;
use Webkul\Sale\Models\QuantityCommitment;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Scopes\AllowedCompanyScope;
use Webkul\Support\Services\CompanyContext;

class TripWorkspace
{
    public function companyId(): int
    {
        $id = app(CompanyContext::class)->currentId();
        abort_unless($id !== null, 403);
        $this->authorize('view_any_logistics_trip', $id);

        return $id;
    }

    public function authorize(string $permission, int $companyId): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        $user = User::withoutGlobalScopes()->findOrFail($user->id);
        abort_unless($user->is_active && ! $user->trashed() && $user->can($permission), 403);
        abort_unless(Plugin::query()->where('name', 'logistics')->where('is_installed', true)->exists(), 403);
        abort_unless(app(CompanyContext::class)->currentId() === $companyId, 403);
        abort_unless($user->can('bypass_company_scope') || $user->allowedCompanies()->withoutGlobalScope(AllowedCompanyScope::class)->whereKey($companyId)->exists(), 403);

        return $user;
    }

    /** @return array<int,string> */
    public function locations(int $companyId): array
    {
        return Location::query()->where('company_id', $companyId)->where('type', LocationType::INTERNAL)->orderBy('full_name')->pluck('full_name', 'id')->all();
    }

    /** @return Collection<int,OrderLine> */
    public function demand(int $companyId, ?string $search = null): Collection
    {
        return OrderLine::query()->where('company_id', $companyId)
            ->whereHas('order', fn ($query) => $query->where('company_id', $companyId)->where('state', OrderState::SALE))
            ->whereHas('product', fn ($query) => $query->where('is_storable', true))
            ->whereHas('currentQuantityCommitment')
            ->when(filled($search), fn ($query) => $query->where(fn ($query) => $query->whereHas('order', fn ($query) => $query->where('name', 'ilike', '%'.$search.'%'))->orWhereHas('product', fn ($query) => $query->where('name', 'ilike', '%'.$search.'%'))))
            ->with(['currentQuantityCommitment', 'order'])->orderByDesc('id')->limit(50)->get();
    }

    /** @return array<int,string> */
    public function demandOptions(int $companyId, ?string $search = null): array
    {
        return $this->demand($companyId, $search)->filter(fn (OrderLine $line): bool => (float) ($line->currentQuantityCommitment->data['stock_quantity'] ?? 0) > 0)
            ->mapWithKeys(function (OrderLine $line): array {
                $snapshot = $line->currentQuantityCommitment;
                $data = $snapshot->data;

                return [$snapshot->id => implode(' — ', [$line->order->name, $data['shipping_address']['name'] ?? '', $data['product_name'], ($data['stock_quantity'] ?? '?').' '.($data['stock_unit']['name'] ?? '')])];
            })->all();
    }

    public function commitment(int $companyId, int $id): QuantityCommitment
    {
        return QuantityCommitment::query()->whereKey($id)->whereHas('orderLine', fn ($query) => $query->where('company_id', $companyId)->whereHas('currentQuantityCommitment', fn ($query) => $query->whereKey($id))->whereHas('order', fn ($query) => $query->where('company_id', $companyId)->where('state', OrderState::SALE)))->firstOrFail();
    }

    /** @return list<array<string,mixed>> */
    public function loadRows(Trip $trip, ?int $sourceLocationId = null): array
    {
        $rows = [];
        $existing = $trip->loadRevision?->items()->get()->keyBy('move_line_id') ?? collect();
        foreach ($trip->allocations()->where('state', 'active')->where('source_location_id', $sourceLocationId ?? $trip->source_location_id)->with('product')->get() as $allocation) {
            $moves = Move::query()->where('company_id', $trip->company_id)->where('sale_order_line_id', $allocation->order_line_id)
                ->where('source_location_id', $allocation->source_location_id)
                ->whereIn('state', [MoveState::ASSIGNED, MoveState::PARTIALLY_ASSIGNED])
                ->whereHas('operation.operationType', fn ($query) => $query->where('type', OperationType::OUTGOING))
                ->with(['lines.lot', 'lines.package', 'operation'])->orderBy('id')->get();
            foreach ($moves as $move) {
                foreach ($move->lines as $line) {
                    $rows[] = [
                        'allocation_id'  => $allocation->id, 'move_id' => $move->id, 'move_line_id' => $line->id,
                        'description'    => implode(' — ', array_filter([$allocation->product?->name, $move->operation?->name, $line->lot?->name, $line->package?->name])),
                        'reserved'       => (string) $line->qty,
                        'stock_quantity' => (string) ($existing->get($line->id)?->stock_quantity ?? '0.0000'),
                    ];
                }
            }
        }

        return $rows;
    }
}

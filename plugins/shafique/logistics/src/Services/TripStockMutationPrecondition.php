<?php

namespace Shafique\Logistics\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Shafique\Logistics\Models\LoadItem;
use Shafique\Logistics\Models\LoadRevision;
use Shafique\Logistics\Models\LoadVerificationEvent;
use Shafique\Logistics\Models\Trip;
use Webkul\Inventory\Models\Move;
use Webkul\Inventory\Models\Operation;
use Webkul\Inventory\Services\MoveCompleter;
use Webkul\Inventory\Support\ScopedStockMutationPrecondition;
use Webkul\Security\Models\User;

class TripStockMutationPrecondition implements ScopedStockMutationPrecondition
{
    /** @param Collection<int, Operation> $operations @param Collection<int, Move> $moves @param array<string,mixed> $changes */
    public function appliesTo(string $action, Collection $operations, Collection $moves, array $changes): bool
    {
        if (! Schema::hasTable('logistics_load_items') || $moves->isEmpty()) {
            return false;
        }

        return $this->guardedItems($moves->pluck('id')->all())->exists();
    }

    /** @param Collection<int, Operation> $operations @param Collection<int, Move> $moves @param array<string,mixed> $changes */
    public function assertAllowed(string $action, Collection $operations, Collection $moves, array $changes): void
    {
        if (! Schema::hasTable('logistics_load_items')) {
            return;
        }

        $moveIds = $moves->pluck('id')->all();
        sort($moveIds);
        if (! $this->guardedItems($moveIds)->exists()) {
            return;
        }

        $context = app(ReleaseExecutionContext::class)->current();
        if ($context === null || ! in_array($action, [
            'complete', 'complete.lines', 'backorder.create', 'backorder.split', 'move.update',
            'move_line.update', 'move_line.delete', 'operation.update',
        ], true)) {
            throw ValidationException::withMessages(['operation' => __('logistics::settings.trip.inventory_release_required')]);
        }

        $trip = Trip::query()->whereKey($context['trip_id'])->lockForUpdate()->firstOrFail();
        $load = LoadRevision::query()->whereKey($context['load_revision_id'])->lockForUpdate()->firstOrFail();
        $linkedItems = $this->guardedItems($moveIds)->get(['move_id', 'load_revision_id']);
        $enrolledMoveIds = $linkedItems->where('load_revision_id', $load->id)
            ->pluck('move_id')->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
        $allLinkedMoveIds = $linkedItems->pluck('move_id')->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
        $mutationMoveIds = collect($moveIds)->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();

        if ($trip->state !== 'verified' || (int) $trip->load_revision_id !== (int) $load->getKey()
            || ! LoadVerificationEvent::query()->where('load_revision_id', $load->id)->exists()
            || (int) $trip->company_id !== (int) $operations->first()?->company_id
            || $enrolledMoveIds === [] || $allLinkedMoveIds !== $enrolledMoveIds
            || ! array_intersect($mutationMoveIds, $enrolledMoveIds)) {
            throw ValidationException::withMessages(['operation' => __('logistics::settings.trip.inventory_release_required')]);
        }

        $actor = User::query()->withoutGlobalScopes()->find($context['actor_id']);
        app(OperationalControl::class)->authorizeNewActivity($actor, (int) $trip->company_id);

        if (in_array($action, ['complete', 'complete.lines'], true)) {
            app(MoveCompleter::class)->assertReservedStockAvailable($moves, $changes['counted_line_quantities'] ?? null);
        }
    }

    /** @param list<int> $moveIds */
    private function guardedItems(array $moveIds): Builder
    {
        $query = LoadItem::query()->whereIn('move_id', $moveIds);
        // During an additive upgrade retain the old protection until snapshots are available.
        if (! Schema::hasColumn('logistics_load_items', 'native_line_snapshot')) {
            return $query;
        }

        return $query->where(function (Builder $query): void {
            $query->whereNull('native_line_snapshot')
                ->orWhereExists(function ($query): void {
                    $query->selectRaw('1')->from('logistics_trips')
                        ->whereColumn('logistics_trips.load_revision_id', 'logistics_load_items.load_revision_id')
                        ->whereIn('logistics_trips.state', ['loading', 'verified']);
                })
                ->orWhereExists(function ($query): void {
                    $query->selectRaw('1')->from('logistics_release_batches')
                        ->whereColumn('logistics_release_batches.load_revision_id', 'logistics_load_items.load_revision_id');
                });
        });
    }
}

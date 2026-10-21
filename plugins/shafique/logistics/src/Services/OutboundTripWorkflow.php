<?php

namespace Shafique\Logistics\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Shafique\Logistics\Models\LoadItem;
use Shafique\Logistics\Models\LoadRevision;
use Shafique\Logistics\Models\LoadVerificationEvent;
use Shafique\Logistics\Models\ReceiptObservation;
use Shafique\Logistics\Models\ReleaseBatch;
use Shafique\Logistics\Models\Trip;
use Shafique\Logistics\Models\TripAllocation;
use Shafique\Logistics\Models\TripRecoveryEvent;
use Shafique\Logistics\Models\TripStop;
use Webkul\Inventory\Enums\LocationType;
use Webkul\Inventory\Enums\MoveState;
use Webkul\Inventory\Enums\OperationType;
use Webkul\Inventory\Facades\Inventory as InventoryFacade;
use Webkul\Inventory\Models\Location;
use Webkul\Inventory\Models\Move;
use Webkul\Inventory\Models\MoveLine;
use Webkul\Inventory\Models\Operation;
use Webkul\Inventory\Models\OperationResult;
use Webkul\Partner\Models\Partner;
use Webkul\Sale\Enums\OrderState;
use Webkul\Sale\Models\Order;
use Webkul\Sale\Models\OrderLine;
use Webkul\Sale\Models\QuantityCommitment;
use Webkul\Security\Models\User;

class OutboundTripWorkflow
{
    public function createTrip(User $actor, int $companyId, string $key, string $mode, int $sourceLocationId, ?string $vehicleReference): Trip
    {
        if (! in_array($mode, ['dedicated_vehicle', 'multi_stop'], true)) {
            throw ValidationException::withMessages(['mode' => __('logistics::settings.trip.invalid_mode')]);
        }

        $vehicleReference = $this->optionalText($vehicleReference, 120, 'vehicle_reference');
        $result = app(CommandIdentity::class)->run(
            $actor, $companyId, 'trip.create', $key,
            ['mode' => $mode, 'source_location_id' => $sourceLocationId, 'vehicle_reference' => $vehicleReference],
            'create_logistics_trip',
            function () use ($actor, $companyId, $mode, $sourceLocationId, $vehicleReference): array {
                $this->assertSourceLocation($companyId, $sourceLocationId);
                $trip = Trip::query()->create([
                    'company_id'         => $companyId,
                    'mode'               => $mode,
                    'state'              => 'planning',
                    'source_location_id' => $sourceLocationId,
                    'vehicle_reference'  => $vehicleReference,
                    'revision'           => 1,
                    'created_by'         => $actor->getKey(),
                ]);

                return ['trip_id' => $trip->getKey()];
            },
        );

        return Trip::query()->findOrFail($result['trip_id']);
    }

    /** @param array<string, mixed> $addressSnapshot */
    public function addStop(User $actor, int $tripId, int $expectedRevision, string $key, int $sequence, int $partnerId, array $addressSnapshot): TripStop
    {
        $result = $this->runTripCommand($actor, $tripId, 'trip.add_stop', $key, 'manage_logistics_trip', [
            'expected_revision' => $expectedRevision, 'sequence' => $sequence, 'partner_id' => $partnerId,
            'address_snapshot'  => $addressSnapshot,
        ], function (Trip $trip) use ($sequence, $partnerId, $addressSnapshot): array {
            if ($trip->state !== 'planning' || $sequence < 1 || $sequence > 100) {
                $this->reject('trip', 'trip.not_editable');
            }
            if ($trip->mode === 'dedicated_vehicle' && $trip->stops()->exists()) {
                $this->reject('trip', 'trip.dedicated_vehicle_one_stop');
            }
            if (! Partner::query()->whereKey($partnerId)->exists()) {
                $this->reject('partner', 'trip.invalid_destination');
            }
            $snapshot = $this->validateAddressSnapshot($addressSnapshot);
            $stop = TripStop::query()->create([
                'trip_id'          => $trip->getKey(), 'company_id' => $trip->company_id,
                'sequence'         => $sequence, 'partner_id' => $partnerId,
                'address_snapshot' => $snapshot, 'state' => 'planned',
            ]);
            $trip->increment('revision');

            return ['stop_id' => $stop->getKey()];
        });

        return TripStop::query()->findOrFail($result['stop_id']);
    }

    public function allocateDemand(User $actor, int $tripId, int $expectedRevision, string $key, int $stopId, int $orderLineId, int $commitmentId, int $sourceLocationId, string $stockQuantity): TripAllocation
    {
        $quantity = $this->quantity($stockQuantity, 'quantity');
        $result = $this->runTripCommand($actor, $tripId, 'trip.allocate', $key, 'allocate_logistics_demand', [
            'expected_revision' => $expectedRevision, 'stop_id' => $stopId, 'order_line_id' => $orderLineId,
            'commitment_id'     => $commitmentId, 'source_location_id' => $sourceLocationId, 'stock_quantity' => $quantity,
        ], function (Trip $trip) use ($stopId, $orderLineId, $commitmentId, $sourceLocationId, $quantity): array {
            if (! in_array($trip->state, ['planning', 'released'], true)) {
                $this->reject('trip', 'trip.not_editable');
            }
            $stop = TripStop::query()->where('trip_id', $trip->id)->whereKey($stopId)->lockForUpdate()->firstOrFail();
            $this->assertSourceLocation((int) $trip->company_id, $sourceLocationId);
            $candidate = OrderLine::query()->whereKey($orderLineId)->firstOrFail();
            $order = $candidate->order()->lockForUpdate()->firstOrFail();
            $line = OrderLine::query()->whereKey($orderLineId)->lockForUpdate()->firstOrFail();
            $snapshot = QuantityCommitment::query()->whereKey($commitmentId)->where('order_line_id', $line->id)->lockForUpdate()->firstOrFail();

            if ((int) $line->order_id !== (int) $order->id || (int) $trip->company_id !== (int) $line->company_id || (int) $order->company_id !== (int) $trip->company_id
                || $order->state !== OrderState::SALE || (int) $line->product_id !== (int) ($snapshot->data['product_id'] ?? 0)
                || (int) $line->currentQuantityCommitment()->value('id') !== $commitmentId
                || (int) $stop->partner_id !== (int) ($snapshot->data['shipping_partner_id'] ?? 0)
                || $this->canonicalAddress($stop->address_snapshot ?? []) !== $this->canonicalAddress($snapshot->data['shipping_address'] ?? [])) {
                $this->reject('allocation', 'trip.stale_demand');
            }

            $available = $this->quantity((string) ($snapshot->data['stock_quantity'] ?? ''), 'quantity');
            $previousClaims = TripAllocation::query()->where('order_line_id', $line->id)->orderBy('id')->lockForUpdate()->get();
            $alreadyReleased = $previousClaims->where('product_id', $line->product_id)->sum(fn (TripAllocation $allocation): int => $this->toUnits((string) $allocation->released_quantity));
            $alreadyClaimed = $previousClaims->where('state', 'active')
                ->sum(fn (TripAllocation $allocation): int => $this->toUnits((string) $allocation->stock_quantity));
            $units = $this->toUnits($quantity);

            if ($units + $alreadyClaimed + $alreadyReleased > $this->toUnits($available)) {
                $this->reject('quantity', 'trip.demand_already_claimed');
            }

            $allocation = TripAllocation::query()->create([
                'trip_id'        => $trip->id, 'stop_id' => $stop->id, 'company_id' => $trip->company_id,
                'order_line_id'  => $line->id, 'quantity_commitment_id' => $commitmentId,
                'product_id'     => $line->product_id, 'source_location_id' => $sourceLocationId,
                'stock_quantity' => $quantity, 'loaded_quantity' => 0, 'released_quantity' => 0, 'state' => 'active',
            ]);
            $trip->increment('revision');

            return ['allocation_id' => $allocation->getKey()];
        });

        return TripAllocation::query()->findOrFail($result['allocation_id']);
    }

    /** @param list<array{allocation_id:int,move_id:int,move_line_id:int,stock_quantity:string}> $items */
    public function recordLoad(User $actor, int $tripId, int $expectedRevision, string $key, array $items, ?int $sourceLocationId = null): LoadRevision
    {
        $items = $this->canonicalLoadItems($items);
        $result = $this->runTripCommand($actor, $tripId, 'trip.load.record', $key, 'record_logistics_load', [
            'expected_revision' => $expectedRevision, 'items' => $items, 'source_location_id' => $sourceLocationId,
        ], function (Trip $trip) use ($actor, $items, $sourceLocationId): array {
            if (! in_array($trip->state, ['planning', 'loading', 'verified', 'released'], true) || $trip->stops()->count() < 1 || $items === []) {
                $this->reject('load', 'trip.load_not_ready');
            }
            if ($trip->load_revision_id && ! $trip->releaseBatches()->where('load_revision_id', $trip->load_revision_id)->exists()) {
                $currentLoad = $trip->loadRevision;
                if ((int) $currentLoad->source_location_id !== (int) ($sourceLocationId ?? $trip->source_location_id)
                    || array_diff($currentLoad->items()->pluck('move_id')->all(), array_column($items, 'move_id')) !== []) {
                    $this->reject('load', 'trip.unload_first');
                }
            }
            $sourceLocationId ??= (int) $trip->source_location_id;
            $this->assertSourceLocation((int) $trip->company_id, $sourceLocationId);
            $allocations = TripAllocation::query()->where('trip_id', $trip->id)
                ->whereIn('id', array_column($items, 'allocation_id'))->lockForUpdate()->get()->keyBy('id');
            if ($allocations->count() !== count(array_unique(array_column($items, 'allocation_id')))) {
                $this->reject('load', 'trip.invalid_load_items');
            }
            $moveIds = array_column($items, 'move_id');
            $moves = Move::query()->whereIn('id', array_unique($moveIds))->lockForUpdate()->get()->keyBy('id');
            if ($moves->count() !== count(array_unique($moveIds))) {
                $this->reject('load', 'trip.invalid_load_items');
            }
            $moveLines = MoveLine::query()->whereIn('id', array_column($items, 'move_line_id'))->lockForUpdate()->get()->keyBy('id');
            if ($moveLines->count() !== count($items)) {
                $this->reject('load', 'trip.invalid_load_items');
            }

            $normalized = [];
            $allocationLoads = [];
            foreach ($items as $item) {
                $allocation = $allocations->get($item['allocation_id']);
                $move = $moves->get($item['move_id']);
                $moveLine = $moveLines->get($item['move_line_id']);
                if (! in_array($move->state, [MoveState::ASSIGNED, MoveState::PARTIALLY_ASSIGNED], true) || $allocation->state !== 'active' || (int) $move->company_id !== (int) $trip->company_id
                    || (int) $move->source_location_id !== $sourceLocationId
                    || (int) $move->sale_order_line_id !== (int) $allocation->order_line_id
                    || (int) $move->product_id !== (int) $allocation->product_id
                    || (int) $move->source_location_id !== (int) $allocation->source_location_id
                    || (int) $moveLine->move_id !== (int) $move->id
                    || (int) $moveLine->company_id !== (int) $trip->company_id
                    || $this->toUnits($item['stock_quantity']) > $this->toUnits((string) $moveLine->qty)) {
                    $this->reject('load', 'trip.load_does_not_match_inventory');
                }
                $allocationLoads[$allocation->id] = ($allocationLoads[$allocation->id] ?? 0) + $this->toUnits($item['stock_quantity']);
                $links = DB::table('logistics_trip_move_links')->where('move_id', $move->id)->lockForUpdate()->get();
                $otherActiveTrip = Trip::query()->whereIn('id', $links->pluck('trip_id'))
                    ->where('id', '!=', $trip->id)->whereIn('state', ['loading', 'verified'])
                    ->whereHas('loadRevision.items', fn ($query) => $query->where('move_id', $move->id))->exists();
                if ($otherActiveTrip) {
                    $this->reject('load', 'trip.inventory_move_already_assigned');
                }
                if (! $links->contains(fn ($link): bool => (int) $link->trip_id === (int) $trip->id)) {
                    DB::table('logistics_trip_move_links')->insert([
                        'trip_id' => $trip->id, 'company_id' => $trip->company_id,
                        'move_id' => $move->id, 'created_at' => now(),
                    ]);
                }
                $normalized[] = $item;
            }
            foreach ($allocationLoads as $allocationId => $loadedUnits) {
                if ($loadedUnits > $this->toUnits((string) $allocations->get($allocationId)->stock_quantity)) {
                    $this->reject('load', 'trip.load_does_not_match_inventory');
                }
                $snapshot = $allocations->get($allocationId)->quantityCommitment->data;
                $rounding = (float) ($snapshot['stock_unit']['rounding'] ?? 0);
                $quantity = $loadedUnits / 10000;
                $packFactor = (float) ($snapshot['packaging']['stock_units_per_pack'] ?? 0);
                if ($rounding <= 0 || abs($quantity / $rounding - round($quantity / $rounding)) > 0.0000001
                    || ($packFactor > 0 && abs($quantity / $packFactor - round($quantity / $packFactor)) > 0.0000001)) {
                    $this->reject('load', 'trip.invalid_quantity');
                }
            }
            foreach ($moves as $move) {
                $expectedLineIds = $move->lines()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
                $submittedLineIds = collect($normalized)->where('move_id', $move->id)->pluck('move_line_id')->sort()->values()->all();
                if ($expectedLineIds === [] || $expectedLineIds !== $submittedLineIds) {
                    $this->reject('load', 'trip.inventory_operation_incomplete');
                }
            }

            $revision = ((int) LoadRevision::query()->where('trip_id', $trip->id)->max('revision')) + 1;
            $nativeSnapshots = [];
            foreach ($normalized as $item) {
                $nativeLine = $moveLines->get($item['move_line_id']);
                $snapshot = $nativeLine->only(['id', 'move_id', 'operation_id', 'company_id', 'product_id', 'uom_id', 'source_location_id', 'destination_location_id', 'lot_id', 'lot_name', 'package_id', 'result_package_id', 'partner_id']);
                $snapshot['reserved_quantity'] = number_format((float) $nativeLine->qty, 4, '.', '');
                $nativeSnapshots[$nativeLine->id] = $snapshot;
            }
            $contentHash = hash('sha256', json_encode(['version' => 2, 'items' => $normalized, 'native_lines' => $nativeSnapshots], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $load = LoadRevision::query()->create([
                'trip_id'            => $trip->id, 'company_id' => $trip->company_id,
                'trip_revision'      => $trip->revision, 'revision' => $revision,
                'recorded_by'        => $actor->getKey(),
                'contents_hash'      => $contentHash, 'recorded_at' => now(),
                'source_location_id' => $sourceLocationId,
            ]);
            foreach ($normalized as $item) {
                LoadItem::query()->create($item + ['load_revision_id' => $load->id, 'company_id' => $trip->company_id, 'native_line_snapshot' => $nativeSnapshots[$item['move_line_id']]]);
            }
            $trip->allocations()->where('state', 'active')->update(['loaded_quantity' => 0]);
            foreach ($allocationLoads as $allocationId => $loadedUnits) {
                $allocations->get($allocationId)->update(['loaded_quantity' => $this->fromUnits($loadedUnits)]);
            }
            $trip->update(['state' => 'loading', 'load_revision_id' => $load->id, 'revision' => $trip->revision + 1, 'verified_by' => null, 'verified_at' => null]);

            return ['load_revision_id' => $load->getKey()];
        });

        return LoadRevision::query()->with('items')->findOrFail($result['load_revision_id']);
    }

    public function releaseAllocation(User $actor, int $tripId, int $expectedRevision, string $key, int $allocationId, string $reason): TripAllocation
    {
        $reason = $this->recoveryReason($reason);
        $result = $this->runTripCommand($actor, $tripId, 'trip.allocation.release', $key, 'allocate_logistics_demand', [
            'expected_revision' => $expectedRevision, 'allocation_id' => $allocationId, 'reason' => $reason,
        ], function (Trip $trip) use ($actor, $allocationId, $reason): array {
            if ($trip->state === 'cancelled') {
                $this->reject('trip', 'trip.not_editable');
            }
            $allocation = $trip->allocations()->whereKey($allocationId)->lockForUpdate()->firstOrFail();
            if ($allocation->state !== 'active' || (float) $allocation->loaded_quantity !== 0.0 || (float) $allocation->released_quantity !== 0.0
                || ($trip->load_revision_id && LoadItem::query()->where('load_revision_id', $trip->load_revision_id)->where('allocation_id', $allocation->id)->exists())) {
                $this->reject('allocation', 'trip.unload_first');
            }
            $allocation->update(['state' => 'claim_released']);
            $this->recordRecovery($actor, $trip, 'allocation_released', $reason, $allocation->id);

            return ['allocation_id' => $allocation->id];
        }, false);

        return TripAllocation::query()->findOrFail($result['allocation_id']);
    }

    public function unload(User $actor, int $tripId, int $expectedRevision, string $key, string $reason, bool $physicallyUnloaded): Trip
    {
        $reason = $this->recoveryReason($reason);
        if (! $physicallyUnloaded) {
            $this->reject('physically_unloaded', 'trip.unload_confirmation_required');
        }
        $result = $this->runTripCommand($actor, $tripId, 'trip.load.unload', $key, 'record_logistics_load', [
            'expected_revision' => $expectedRevision, 'reason' => $reason, 'physically_unloaded' => $physicallyUnloaded,
        ], function (Trip $trip) use ($actor, $reason): array {
            if (! in_array($trip->state, ['loading', 'verified'], true) || ! $trip->load_revision_id) {
                $this->reject('load', 'trip.load_not_ready');
            }
            $load = $trip->loadRevision()->firstOrFail();
            $items = $load->items()->with('move')->get();
            if (ReleaseBatch::query()->where('load_revision_id', $load->id)->exists()
                || OperationResult::query()->whereIn('operation_id', $items->pluck('move.operation_id'))->exists()
                || $items->contains(fn ($item) => $item->move?->state === MoveState::DONE)) {
                $this->reject('load', 'trip.issued_recovery_required');
            }
            if ($items->contains(fn ($item) => $item->native_line_snapshot === null)) {
                $this->reject('load', 'trip.historical_load_review_required');
            }
            $trip->allocations()->whereIn('id', $items->pluck('allocation_id'))->where('state', 'active')->update(['loaded_quantity' => 0]);
            $trip->update(['load_revision_id' => null, 'verified_by' => null, 'verified_at' => null,
                'state'                       => $trip->releaseBatches()->exists() ? 'released' : 'planning']);
            $this->recordRecovery($actor, $trip, 'load_unloaded', $reason, loadId: $load->id);

            return ['trip_id' => $trip->id];
        }, false);

        return Trip::query()->findOrFail($result['trip_id']);
    }

    public function cancelTrip(User $actor, int $tripId, int $expectedRevision, string $key, string $reason): Trip
    {
        $reason = $this->recoveryReason($reason);
        $result = $this->runTripCommand($actor, $tripId, 'trip.cancel', $key, 'manage_logistics_trip', [
            'expected_revision' => $expectedRevision, 'reason' => $reason,
        ], function (Trip $trip) use ($actor, $reason): array {
            if ($trip->releaseBatches()->exists() || $trip->released_at !== null) {
                $this->reject('trip', 'trip.issued_recovery_required');
            }
            if ($trip->state !== 'planning' || $trip->load_revision_id !== null) {
                $this->reject('trip', 'trip.unload_first');
            }
            $trip->allocations()->where('state', 'active')->update(['state' => 'claim_released', 'loaded_quantity' => 0]);
            $trip->stops()->update(['state' => 'cancelled']);
            $trip->update(['state' => 'cancelled', 'verified_by' => null, 'verified_at' => null]);
            $this->recordRecovery($actor, $trip, 'trip_cancelled', $reason);

            return ['trip_id' => $trip->id];
        }, false);

        return Trip::query()->findOrFail($result['trip_id']);
    }

    private function recoveryReason(string $reason): string
    {
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:2000']])->validate();

        return $reason;
    }

    private function recordRecovery(User $actor, Trip $trip, string $action, string $reason, ?int $allocationId = null, ?int $loadId = null): void
    {
        TripRecoveryEvent::query()->create([
            'trip_id'           => $trip->id, 'company_id' => $trip->company_id, 'actor_id' => $actor->id,
            'allocation_id'     => $allocationId, 'load_revision_id' => $loadId, 'action' => $action, 'reason' => $reason,
            'previous_revision' => $trip->revision, 'resulting_revision' => $trip->revision + 1, 'recorded_at' => now(),
        ]);
        $trip->increment('revision');
    }

    public function verifyLoad(User $actor, int $tripId, int $expectedRevision, string $key, int $approvalId, ?int $evidenceId = null): Trip
    {
        $result = $this->runTripCommand($actor, $tripId, 'trip.load.verify', $key, 'verify_logistics_load', [
            'expected_revision' => $expectedRevision, 'approval_id' => $approvalId, 'evidence_id' => $evidenceId,
        ], function (Trip $trip) use ($actor, $approvalId, $key, $evidenceId): array {
            if ($trip->state !== 'loading' || ! $trip->load_revision_id) {
                $this->reject('load', 'trip.load_not_ready');
            }
            $load = LoadRevision::query()->whereKey($trip->load_revision_id)->lockForUpdate()->firstOrFail();
            $items = $load->items()->with('allocation')->orderBy('id')->get();
            $quantity = $this->fromUnits($items->sum(fn (LoadItem $item): int => $this->toUnits((string) $item->stock_quantity)));
            if ((int) $load->recorded_by === (int) $actor->getKey()) {
                $this->reject('load', 'trip.independent_verification_required');
            }
            if ($trip->mode === 'multi_stop' && $trip->stops()->count() < 2) {
                $this->reject('trip', 'trip.multi_stop_needs_two_stops');
            }
            $this->assertLoadCoversEveryStop($trip, $items);
            $this->assertCurrentDemand($items);
            app(DispatchReadiness::class)->assertReady($trip, $evidenceId, true);
            app(ApprovalWorkflow::class)->consume($actor, (int) $trip->company_id, $approvalId, 'load.verify', 'load_revision', (int) $load->id, (int) $load->revision, ['quantity' => $quantity], 'trip.load.verify', $key);
            if ($evidenceId !== null) {
                app(EvidenceStore::class)->assertAttachable($actor, (int) $trip->company_id, $evidenceId, ['load_verification', 'loading_photo']);
            }
            LoadVerificationEvent::query()->create([
                'load_revision_id' => $load->id, 'trip_id' => $trip->id,
                'company_id'       => $trip->company_id, 'verified_by' => $actor->getKey(),
                'approval_id'      => $approvalId, 'evidence_id' => $evidenceId, 'verified_at' => now(),
            ]);
            $trip->update(['state' => 'verified', 'verified_by' => $actor->getKey(), 'verified_at' => now(), 'revision' => $trip->revision + 1]);

            return ['trip_id' => $trip->getKey(), 'load_revision_id' => $load->getKey()];
        });

        return Trip::query()->findOrFail($result['trip_id']);
    }

    public function release(User $actor, int $tripId, int $expectedRevision, string $key, int $approvalId): ReleaseBatch
    {
        $result = $this->runTripCommand($actor, $tripId, 'trip.release', $key, 'release_logistics_trip', [
            'expected_revision' => $expectedRevision, 'approval_id' => $approvalId,
        ], function (Trip $trip) use ($actor, $approvalId, $key): array {
            if ($trip->state !== 'verified' || ! $trip->load_revision_id) {
                $this->reject('trip', 'trip.release_not_ready');
            }
            $load = LoadRevision::query()->whereKey($trip->load_revision_id)->lockForUpdate()->firstOrFail();
            if (! LoadVerificationEvent::query()->where('load_revision_id', $load->id)->exists()) {
                $this->reject('load', 'trip.load_not_verified');
            }
            $items = $load->items()->with('allocation')->orderBy('id')->get();
            if ($items->isEmpty() || $items->sum(fn (LoadItem $item): int => $this->toUnits((string) $item->stock_quantity)) < 1) {
                $this->reject('load', 'trip.invalid_load_items');
            }
            $this->assertLoadCoversEveryStop($trip, $items);
            $this->assertCurrentDemand($items);
            app(DispatchReadiness::class)->assertReady($trip);
            if ($trip->loadVerification?->evidence_id) {
                app(EvidenceStore::class)->assertAttachable($actor, (int) $trip->company_id, (int) $trip->loadVerification->evidence_id, ['load_verification', 'loading_photo']);
            }
            $quantity = $this->fromUnits($items->sum(fn (LoadItem $item): int => $this->toUnits((string) $item->stock_quantity)));
            app(ApprovalWorkflow::class)->consume($actor, (int) $trip->company_id, $approvalId, 'release.stock', 'trip', (int) $trip->id, (int) $trip->revision, ['quantity' => $quantity], 'trip.release', $key);

            $byOperation = collect();
            foreach ($items as $item) {
                $move = Move::query()->with('operation.operationType')->whereKey($item->move_id)->firstOrFail();
                if ($move->operation?->operationType?->type !== OperationType::OUTGOING
                    || (int) $move->company_id !== (int) $trip->company_id
                    || (int) $move->sale_order_line_id !== (int) $item->allocation->order_line_id) {
                    $this->reject('load', 'trip.load_does_not_match_inventory');
                }
                $byOperation->put($move->operation_id, true);
            }

            $operationResults = [];
            $context = app(ReleaseExecutionContext::class);
            $context->enter((int) $trip->id, (int) $load->id, (int) $actor->getKey());
            try {
                foreach ($byOperation->keys() as $operationId) {
                    $operation = Operation::query()->findOrFail($operationId);
                    $operationMoveIds = $operation->moves()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
                    $mappedMoveIds = $items->filter(fn (LoadItem $item): bool => (int) $item->move->operation_id === (int) $operationId)
                        ->pluck('move_id')->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
                    if ($operationMoveIds !== $mappedMoveIds) {
                        $this->reject('load', 'trip.inventory_operation_incomplete');
                    }
                    $operationLines = $operation->moves()->with('lines')->get()->flatMap->lines;
                    $itemCounts = $items->filter(fn (LoadItem $item): bool => (int) $item->move->operation_id === (int) $operationId)->keyBy('move_line_id');
                    $countedLineQuantities = [];
                    foreach ($operationLines as $line) {
                        $item = $itemCounts->get($line->id);
                        if (! $item) {
                            $this->reject('load', 'trip.inventory_operation_incomplete');
                        }
                        $countedLineQuantities[$line->id] = (float) $item->stock_quantity;
                    }
                    if (OperationResult::query()->where('operation_id', $operation->id)->exists()) {
                        $this->reject('load', 'trip.inventory_operation_already_completed');
                    }
                    $completed = InventoryFacade::completeTransfer($operation, false, $countedLineQuantities);
                    $operationResults[] = [
                        'operation_id'        => $completed->getKey(),
                        'operation_result_id' => $completed->terminalResult?->getKey(),
                        'state'               => $completed->state->value,
                    ];
                }
            } finally {
                $context->leave();
            }

            $batch = ReleaseBatch::query()->create([
                'trip_id'                 => $trip->id, 'company_id' => $trip->company_id,
                'load_revision_id'        => $load->id, 'actor_id' => $actor->getKey(),
                'command_idempotency_key' => $key,
                'operation_results'       => $operationResults, 'released_at' => now(),
            ]);
            $releasedByAllocation = $items->groupBy('allocation_id')->map(
                fn ($allocationItems): string => $this->fromUnits($allocationItems->sum(fn (LoadItem $item): int => $this->toUnits((string) $item->stock_quantity))),
            );
            foreach ($trip->allocations()->whereIn('id', $releasedByAllocation->keys())->lockForUpdate()->get() as $allocation) {
                $releasedQuantity = $releasedByAllocation->get($allocation->id, '0.0000');
                $releasedUnits = $this->toUnits($releasedQuantity);
                $plannedUnits = $this->toUnits((string) $allocation->stock_quantity);
                $allocation->update([
                    'released_quantity' => $releasedQuantity,
                    'state'             => $releasedUnits === 0 ? 'released' : ($releasedUnits < $plannedUnits ? 'partially_released' : 'consumed'),
                ]);
            }
            $trip->update(['state' => 'released', 'released_at' => now()]);

            return ['release_batch_id' => $batch->getKey()];
        });

        return ReleaseBatch::query()->findOrFail($result['release_batch_id']);
    }

    public function recordReceiptObservation(User $actor, int $tripId, int $stopId, string $key, string $intent, ?string $quantity, ?int $unitId, ?string $notes, ?int $evidenceId = null, ?string $reporterName = null, ?string $occurredAt = null): ReceiptObservation
    {
        if (! in_array($intent, ['incremental', 'cumulative', 'correction', 'unclear'], true)) {
            $this->reject('intent', 'trip.invalid_receipt_intent');
        }
        Validator::make(['reporter_name' => $reporterName, 'occurred_at' => $occurredAt, 'quantity' => $quantity, 'unit_id' => $unitId], [
            'reporter_name' => ['required', 'string', 'max:120'], 'occurred_at' => ['required', 'date', 'before_or_equal:now'],
            'unit_id'       => ['nullable', 'required_with:quantity', 'exists:unit_of_measures,id'], 'quantity' => ['nullable', 'required_with:unit_id'],
        ])->validate();
        $normalizedQuantity = $quantity === null ? null : $this->countedQuantity($quantity);
        $notes = $this->optionalText($notes, 2000, 'notes');
        $trip = Trip::query()->findOrFail($tripId);
        $result = app(CommandIdentity::class)->run($actor, (int) $trip->company_id, 'receipt.observe', $key, [
            'trip_id'  => $tripId, 'stop_id' => $stopId, 'intent' => $intent,
            'quantity' => $normalizedQuantity, 'unit_id' => $unitId, 'notes' => $notes, 'evidence_id' => $evidenceId, 'reporter_name' => $reporterName, 'occurred_at' => $occurredAt,
        ], 'record_logistics_receipt', function () use ($actor, $trip, $stopId, $key, $intent, $normalizedQuantity, $unitId, $notes, $evidenceId, $reporterName, $occurredAt): array {
            $trip = Trip::query()->whereKey($trip->id)->lockForUpdate()->firstOrFail();
            if (! ReleaseBatch::query()->where('trip_id', $trip->id)->exists()) {
                $this->reject('receipt', 'trip.receipt_not_open');
            }
            $stop = TripStop::query()->where('trip_id', $trip->id)->whereKey($stopId)->firstOrFail();
            app(DispatchReadiness::class)->assertReceiptEvidence($trip, $evidenceId);
            if ($evidenceId !== null) {
                app(EvidenceStore::class)->assertAttachable($actor, (int) $trip->company_id, $evidenceId, ['delivery_receipt', 'proof_of_delivery']);
            }
            $observation = ReceiptObservation::query()->create([
                'trip_id'       => $trip->id, 'stop_id' => $stop->id, 'company_id' => $trip->company_id,
                'recorded_by'   => $actor->getKey(), 'evidence_id' => $evidenceId, 'request_key' => $key, 'intent' => $intent,
                'quantity'      => $normalizedQuantity, 'unit_id' => $unitId, 'notes' => $notes, 'recorded_at' => now(),
                'reporter_name' => trim($reporterName), 'occurred_at' => Carbon::parse($occurredAt),
            ]);

            return ['observation_id' => $observation->getKey()];
        }, false);

        return ReceiptObservation::query()->findOrFail($result['observation_id']);
    }

    /** @param array<string, mixed> $intent @param callable(Trip): array<string, mixed> $callback @return array<string, mixed> */
    private function runTripCommand(User $actor, int $tripId, string $type, string $key, string $permission, array $intent, callable $callback, bool $requiresNewActivity = true): array
    {
        $trip = Trip::query()->findOrFail($tripId);

        return app(CommandIdentity::class)->run($actor, (int) $trip->company_id, $type, $key, $intent, $permission, function () use ($tripId, $intent, $callback): array {
            $trip = Trip::query()->whereKey($tripId)->lockForUpdate()->firstOrFail();
            if ((int) $trip->revision !== (int) ($intent['expected_revision'] ?? 0)) {
                $this->reject('revision', 'trip.stale_revision');
            }

            return $callback($trip);
        }, $requiresNewActivity);
    }

    private function assertSourceLocation(int $companyId, int $locationId): void
    {
        $valid = Location::query()->whereKey($locationId)->where('company_id', $companyId)->where('type', LocationType::INTERNAL)->exists();
        if (! $valid) {
            $this->reject('source_location_id', 'trip.invalid_source_location');
        }
    }

    /** @param array<string, mixed> $snapshot @return array<string, mixed> */
    private function validateAddressSnapshot(array $snapshot): array
    {
        if (array_diff(array_keys($snapshot), ['name', 'street1', 'street2', 'city', 'zip', 'state_id', 'country_id']) !== []) {
            $this->reject('address_snapshot', 'trip.invalid_destination');
        }
        foreach ($snapshot as $value) {
            if (! is_string($value) && ! is_int($value) && $value !== null) {
                $this->reject('address_snapshot', 'trip.invalid_destination');
            }
        }
        foreach (['state_id', 'country_id'] as $key) {
            $value = $snapshot[$key] ?? null;
            if ($value !== null && (! is_numeric($value) || (int) $value < 1 || (string) (int) $value !== (string) $value)) {
                $this->reject('address_snapshot', 'trip.invalid_destination');
            }
        }

        return $snapshot;
    }

    /** @param array<string, mixed> $address @return array<string, int|string> */
    private function canonicalAddress(array $address): array
    {
        $canonical = [];
        foreach (['name', 'street1', 'street2', 'city', 'zip', 'state_id', 'country_id'] as $key) {
            $value = $address[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $canonical[$key] = in_array($key, ['state_id', 'country_id'], true)
                ? (int) $value
                : trim((string) $value);
        }
        ksort($canonical);

        return $canonical;
    }

    /** @param list<array{allocation_id:int,move_id:int,move_line_id:int,stock_quantity:string}> $items
     * @return list<array{allocation_id:int,move_id:int,move_line_id:int,stock_quantity:string}> */
    private function canonicalLoadItems(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            if (! isset($item['allocation_id'], $item['move_id'], $item['move_line_id'], $item['stock_quantity'])) {
                $this->reject('load', 'trip.invalid_load_items');
            }
            $normalized[] = [
                'allocation_id'  => (int) $item['allocation_id'],
                'move_id'        => (int) $item['move_id'],
                'move_line_id'   => (int) $item['move_line_id'],
                'stock_quantity' => $this->countedQuantity((string) $item['stock_quantity']),
            ];
        }
        usort($normalized, fn (array $a, array $b): int => [$a['allocation_id'], $a['move_id'], $a['move_line_id']] <=> [$b['allocation_id'], $b['move_id'], $b['move_line_id']]);
        if (count(array_unique(array_column($normalized, 'move_line_id'))) !== count($normalized)) {
            $this->reject('load', 'trip.invalid_load_items');
        }

        return $normalized;
    }

    private function quantity(string $value, string $field): string
    {
        if (! preg_match('/^(?:0|[1-9]\d{0,10})(?:\.\d{1,4})?$/', $value) || $this->toUnits($value) < 1) {
            $this->reject($field, 'trip.invalid_quantity');
        }

        return number_format($this->toUnits($value) / 10000, 4, '.', '');
    }

    private function countedQuantity(string $value): string
    {
        if (! preg_match('/^(?:0|[1-9]\d{0,10})(?:\.\d{1,4})?$/', $value)) {
            $this->reject('quantity', 'trip.invalid_quantity');
        }

        return number_format($this->toUnits($value) / 10000, 4, '.', '');
    }

    /** @param Collection<int, LoadItem> $items */
    /** @param Collection<int, LoadItem> $items */
    private function assertLoadCoversEveryStop(Trip $trip, Collection $items): void
    {
        $sourceLocationId = $trip->loadRevision?->source_location_id;
        $requiredStopIds = $trip->allocations()->where('state', 'active')->where('source_location_id', $sourceLocationId)
            ->pluck('stop_id')->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
        $loadedStopIds = $items->filter(fn (LoadItem $item): bool => $this->toUnits((string) $item->stock_quantity) > 0)
            ->map(fn (LoadItem $item): int => (int) $item->allocation->stop_id)->unique()->sort()->values()->all();

        if ($requiredStopIds === [] || $requiredStopIds !== $loadedStopIds) {
            $this->reject('load', 'trip.load_must_cover_every_stop');
        }
    }

    /** @param Collection<int, LoadItem> $items */
    private function assertCurrentDemand(Collection $items): void
    {
        $allocations = $items->map->allocation->unique('id')->sortBy('order_line_id');
        $orderIds = OrderLine::query()->whereIn('id', $allocations->pluck('order_line_id'))->pluck('order_id')->unique()->sort();
        Order::query()->whereKey($orderIds)->orderBy('id')->lockForUpdate()->get();
        $lines = OrderLine::query()->whereKey($allocations->pluck('order_line_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($allocations as $allocation) {
            $line = $lines->get($allocation->order_line_id);
            if (! $line || $line->order->state !== OrderState::SALE || (int) $line->currentQuantityCommitment()->value('id') !== (int) $allocation->quantity_commitment_id) {
                $this->reject('allocation', 'trip.stale_demand');
            }
        }
    }

    private function toUnits(string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 10000) + (int) str_pad($fraction, 4, '0');
    }

    private function fromUnits(int $units): string
    {
        return number_format($units / 10000, 4, '.', '');
    }

    private function optionalText(?string $value, int $maxLength, string $field): ?string
    {
        $value = $value === null ? null : trim($value);
        if ($value !== null && (mb_strlen($value) > $maxLength || $value === '')) {
            $this->reject($field, 'trip.invalid_text');
        }

        return $value;
    }

    private function reject(string $field, string $translation): never
    {
        throw ValidationException::withMessages([$field => __('logistics::settings.'.$translation)]);
    }
}

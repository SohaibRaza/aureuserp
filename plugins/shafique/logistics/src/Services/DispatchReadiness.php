<?php

namespace Shafique\Logistics\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Shafique\Logistics\Models\DispatchCheck;
use Shafique\Logistics\Models\DispatchPolicy;
use Shafique\Logistics\Models\EvidenceRetentionPolicy;
use Shafique\Logistics\Models\Trip;
use Webkul\Inventory\Enums\LocationType;
use Webkul\Inventory\Models\Location;
use Webkul\Partner\Models\Partner;
use Webkul\Product\Models\Product;
use Webkul\Security\Models\User;

class DispatchReadiness
{
    public function policy(int $companyId): ?DispatchPolicy
    {
        return DispatchPolicy::query()->where('company_id', $companyId)->latest('revision')->first();
    }

    /** @param array<string,mixed> $data */
    public function approvePolicy(User $actor, int $companyId, string $key, array $data): DispatchPolicy
    {
        Validator::make($data, [
            'source_location_ids'              => ['required', 'array', 'min:1'], 'source_location_ids.*' => ['required', 'integer', 'distinct'],
            'product_rules'                    => ['required', 'array', 'min:1'], 'product_rules.*.product_id' => ['required', 'integer', 'distinct'],
            'product_rules.*.quality_required' => ['required', 'boolean'], 'product_rules.*.instructions' => ['required', 'string', 'max:2000'],
            'reason'                           => ['required', 'string', 'max:1000'], 'require_load_evidence' => ['required', 'boolean'], 'require_receipt_evidence' => ['required', 'boolean'],
        ])->validate();
        $result = app(CommandIdentity::class)->run($actor, $companyId, 'dispatch.policy', $key, $data, 'configure_logistics_dispatch', function () use ($actor, $companyId, $data): array {
            $locations = array_values(array_unique(array_map('intval', $data['source_location_ids'] ?? [])));
            if ($locations === [] || Location::query()->whereIn('id', $locations)->where('company_id', $companyId)->where('type', LocationType::INTERNAL)->count() !== count($locations)) {
                $this->reject('source_location_ids', 'sites_required');
            }
            $rules = [];
            foreach ($data['product_rules'] ?? [] as $rule) {
                $product = Product::query()->whereKey($rule['product_id'] ?? 0)->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->first();
                if (! $product || ! $product->uom_id || isset($rules[$product->id]) || trim($rule['instructions'] ?? '') === '' || ! array_key_exists('quality_required', $rule)) {
                    $this->reject('product_rules', 'product_rules_required');
                }
                $rules[$product->id] = ['product_id' => $product->id, 'stock_unit_id' => $product->uom_id, 'quality_required' => (bool) $rule['quality_required'], 'instructions' => trim($rule['instructions'])];
            }
            if ($rules === [] || trim($data['reason'] ?? '') === '' || ! isset($data['require_load_evidence'], $data['require_receipt_evidence'])) {
                $this->reject('product_rules', 'product_rules_required');
            }
            $policy = DispatchPolicy::query()->create([
                'company_id'            => $companyId, 'revision' => ($this->policy($companyId)?->revision ?? 0) + 1,
                'source_location_ids'   => $locations, 'product_rules' => array_values($rules),
                'require_load_evidence' => (bool) $data['require_load_evidence'], 'require_receipt_evidence' => (bool) $data['require_receipt_evidence'],
                'reason'                => trim($data['reason']), 'approved_by' => $actor->id, 'approved_at' => now(),
            ]);

            return ['policy_id' => $policy->id];
        }, false);

        return DispatchPolicy::query()->findOrFail($result['policy_id']);
    }

    /** @param array<string,mixed> $data */
    public function recordChecks(User $actor, int $tripId, int $revision, string $key, array $data): DispatchCheck
    {
        Validator::make($data, [
            'loading_deadline'           => ['required', 'date'], 'provider_id' => ['required', 'integer'],
            'driver_name'                => ['required', 'string', 'max:120'], 'custodian_name' => ['required', 'string', 'max:120'],
            'vehicle_payload_kg'         => ['required', 'numeric', 'decimal:0,4'], 'loaded_gross_weight_kg' => ['required', 'numeric', 'decimal:0,4'],
            'vehicle_volume_m3'          => ['nullable', 'numeric', 'decimal:0,4'], 'loaded_volume_m3' => ['nullable', 'numeric', 'decimal:0,4'],
            'volume_not_required_reason' => ['nullable', 'string', 'max:2000'], 'handling_confirmation' => ['required', 'string', 'max:2000'],
            'quality_release_reference'  => ['nullable', 'string', 'max:2000'],
        ])->validate();
        $trip = Trip::query()->findOrFail($tripId);
        $result = app(CommandIdentity::class)->run($actor, (int) $trip->company_id, 'trip.dispatch_checks', $key, ['trip_id' => $tripId, 'revision' => $revision, 'checks' => $data], 'record_logistics_load', function () use ($actor, $tripId, $revision, $data): array {
            $trip = Trip::query()->whereKey($tripId)->lockForUpdate()->firstOrFail();
            if ($trip->revision !== $revision || $trip->state !== 'loading' || ! $trip->load_revision_id) {
                $this->reject('trip', 'load_required');
            }
            $policy = $this->policy((int) $trip->company_id) ?? $this->reject('policy', 'policy_required');
            $this->assertProductsAndSites($trip, $policy);
            $deadline = Carbon::parse($data['loading_deadline'] ?? '');
            $payload = $this->positive($data['vehicle_payload_kg'] ?? null);
            $weight = $this->positive($data['loaded_gross_weight_kg'] ?? null);
            if ($weight > $payload) {
                $this->reject('loaded_gross_weight_kg', 'over_capacity');
            }
            $volume = $data['loaded_volume_m3'] ?? null;
            $volumeLimit = $data['vehicle_volume_m3'] ?? null;
            if ($volume !== null || $volumeLimit !== null) {
                $volume = $this->positive($volume);
                $volumeLimit = $this->positive($volumeLimit);
                if ($volume > $volumeLimit) {
                    $this->reject('loaded_volume_m3', 'over_capacity');
                }
            } elseif (trim($data['volume_not_required_reason'] ?? '') === '') {
                $this->reject('volume_not_required_reason', 'volume_required');
            }
            foreach (['driver_name', 'custodian_name', 'handling_confirmation'] as $field) {
                if (trim($data[$field] ?? '') === '' || mb_strlen($data[$field]) > ($field === 'handling_confirmation' ? 2000 : 120)) {
                    $this->reject($field, 'details_required');
                }
            }
            if (! $trip->vehicle_reference || ! Partner::query()->whereKey($data['provider_id'] ?? 0)->where(fn ($query) => $query->where('company_id', $trip->company_id)->orWhereNull('company_id'))->exists()) {
                $this->reject('provider_id', 'details_required');
            }
            if (collect($policy->product_rules)->whereIn('product_id', $trip->loadRevision->items->pluck('allocation.product_id'))->contains('quality_required', true)
                && trim($data['quality_release_reference'] ?? '') === '') {
                $this->reject('quality_release_reference', 'quality_required');
            }
            $check = DispatchCheck::query()->create([
                'company_id'                => $trip->company_id, 'trip_id' => $trip->id, 'load_revision_id' => $trip->load_revision_id, 'policy_id' => $policy->id, 'recorded_by' => $actor->id,
                'loading_deadline'          => $deadline, 'vehicle_payload_kg' => $payload, 'loaded_gross_weight_kg' => $weight,
                'vehicle_volume_m3'         => $volumeLimit, 'loaded_volume_m3' => $volume, 'volume_not_required_reason' => $data['volume_not_required_reason'] ?? null,
                'provider_id'               => $data['provider_id'], 'driver_name' => trim($data['driver_name']), 'custodian_name' => trim($data['custodian_name']),
                'vehicle_reference'         => $trip->vehicle_reference, 'handling_confirmation' => trim($data['handling_confirmation']),
                'quality_release_reference' => $data['quality_release_reference'] ?? null, 'recorded_at' => now(),
            ]);
            $trip->increment('revision');

            return ['check_id' => $check->id];
        });

        return DispatchCheck::query()->findOrFail($result['check_id']);
    }

    public function assertReady(Trip $trip, ?int $evidenceId = null, bool $verification = false): void
    {
        $policy = $this->policy((int) $trip->company_id) ?? $this->reject('policy', 'policy_required');
        $this->assertProductsAndSites($trip, $policy);
        if (collect($policy->product_rules)->whereIn('product_id', $trip->loadRevision->items->pluck('allocation.product_id'))->contains('quality_required', true)) {
            $this->reject('quality_release_reference', 'quality_integration_required');
        }
        $check = DispatchCheck::query()->where('trip_id', $trip->id)->where('load_revision_id', $trip->load_revision_id)->latest('id')->first();
        if (! $check || (int) $check->policy_id !== (int) $policy->id || $check->vehicle_reference !== $trip->vehicle_reference) {
            $this->reject('checks', 'checks_required');
        }
        if ($check->loading_deadline->isPast()) {
            $this->reject('loading_deadline', 'deadline_passed');
        }
        if ($policy->require_load_evidence) {
            if (! EvidenceRetentionPolicy::query()->where('company_id', $trip->company_id)->exists()) {
                $this->reject('evidence_id', 'retention_required');
            }
            if ($verification && $evidenceId === null) {
                $this->reject('evidence_id', 'evidence_required');
            }
            if (! $verification && ! $trip->loadVerification?->evidence_id) {
                $this->reject('evidence_id', 'evidence_required');
            }
        }
    }

    public function assertReceiptEvidence(Trip $trip, ?int $evidenceId): void
    {
        if ($this->policy((int) $trip->company_id)?->require_receipt_evidence && $evidenceId === null) {
            $this->reject('evidence_id', 'evidence_required');
        }
    }

    private function assertProductsAndSites(Trip $trip, DispatchPolicy $policy): void
    {
        $trip->load('loadRevision.items.allocation', 'loadRevision.items.move');
        $rules = collect($policy->product_rules)->keyBy('product_id');
        foreach ($trip->loadRevision->items as $item) {
            if ((float) $item->stock_quantity === 0.0) {
                continue;
            }
            $rule = $rules->get($item->allocation->product_id);
            $unit = $item->allocation->quantityCommitment?->data['stock_unit']['id'] ?? null;
            if (! in_array((int) $item->move->source_location_id, $policy->source_location_ids, true) || ! $rule || (int) $rule['stock_unit_id'] !== (int) $unit) {
                $this->reject('policy', 'product_rules_required');
            }
        }
    }

    private function positive(mixed $value): float
    {
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value <= 0 || (float) $value > 99999999999.9999) {
            $this->reject('capacity', 'capacity_required');
        }

        return (float) $value;
    }

    private function reject(string $field, string $key): never
    {
        throw ValidationException::withMessages([$field => __('logistics::filament/resources/trip.readiness.'.$key)]);
    }
}

<?php

use Shafique\Logistics\Models\Trip;
use Shafique\Logistics\Services\DispatchReadiness;
use Webkul\Partner\Models\Partner;
use Webkul\Security\Models\User;

class LogisticsHelper
{
    /** Synthetic business data for isolated tests; never used by the application installer. */
    public static function recordDispatchChecks(User $actor, Trip $trip, string $key): void
    {
        $trip->refresh()->load('loadRevision.items.allocation.quantityCommitment');
        $rules = $trip->loadRevision->items->map(fn ($item) => [
            'product_id'   => $item->allocation->product_id, 'quality_required' => false,
            'instructions' => 'Synthetic test product: check count, sealed packaging and intact condition.',
        ])->unique('product_id')->values()->all();
        app(DispatchReadiness::class)->approvePolicy($actor, (int) $trip->company_id, $key.'.policy', [
            'source_location_ids' => $trip->allocations()->pluck('source_location_id')->unique()->values()->all(),
            'product_rules'       => $rules, 'require_load_evidence' => false, 'require_receipt_evidence' => false,
            'reason'              => 'Synthetic configuration for an isolated PostgreSQL test.',
        ]);
        app(DispatchReadiness::class)->recordChecks($actor, $trip->id, (int) $trip->fresh()->revision, $key.'.checks', self::checkData());
    }

    public static function checkData(): array
    {
        return [
            'loading_deadline'           => now()->addHours(2)->toIso8601String(),
            'vehicle_payload_kg'         => '1000', 'loaded_gross_weight_kg' => '200',
            'vehicle_volume_m3'          => null, 'loaded_volume_m3' => null,
            'volume_not_required_reason' => 'Synthetic load is limited by payload; loading space was checked.',
            'provider_id'                => Partner::query()->value('id'), 'driver_name' => 'Synthetic driver',
            'custodian_name'             => 'Synthetic custodian', 'handling_confirmation' => 'Synthetic packaging and condition checks completed.',
            'quality_release_reference'  => null,
        ];
    }
}

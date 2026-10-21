<?php

namespace Shafique\Logistics;

use Illuminate\Database\Seeder;
use Webkul\Security\Models\Permission;

class LogisticsDatabaseSeeder extends Seeder
{
    public const array PERMISSIONS = [
        'create_logistics_evidence',
        'view_any_logistics_evidence',
        'view_logistics_evidence',
        'manage_logistics_evidence_retention',
        'place_logistics_evidence_legal_hold',
        'release_logistics_evidence_legal_hold',
        'dispose_logistics_evidence',
        'approve_logistics_evidence_disposal',
        'request_logistics_approval',
        'approve_logistics_approval',
        'consume_logistics_approval',
        'revoke_logistics_approval',
        'create_logistics_trip',
        'view_any_logistics_trip',
        'configure_logistics_dispatch',
        'manage_logistics_trip',
        'allocate_logistics_demand',
        'record_logistics_load',
        'verify_logistics_load',
        'release_logistics_trip',
        'record_logistics_receipt',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $permissionName) {
            foreach (['web', 'sanctum'] as $guardName) {
                Permission::findOrCreate($permissionName, $guardName);
            }
        }
    }
}

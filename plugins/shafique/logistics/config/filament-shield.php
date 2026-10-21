<?php

use Shafique\Logistics\Filament\Resources\EvidenceResource;
use Shafique\Logistics\Filament\Resources\ModuleSettingResource;
use Shafique\Logistics\Filament\Resources\TripResource;

return [
    'resources' => [
        'manage' => [
            ModuleSettingResource::class => ['view_any', 'update'],
            TripResource::class          => ['view_any', 'create', 'manage'],
            EvidenceResource::class      => ['view_any', 'view', 'create'],
        ],
    ],
];

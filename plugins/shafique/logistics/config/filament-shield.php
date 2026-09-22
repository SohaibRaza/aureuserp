<?php

use Shafique\Logistics\Filament\Resources\ModuleSettingResource;

return [
    'resources' => [
        'manage' => [ModuleSettingResource::class => ['view_any', 'update']],
    ],
];

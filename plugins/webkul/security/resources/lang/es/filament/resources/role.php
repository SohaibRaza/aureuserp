<?php

return [
    'form' => [
        'fields' => [
            'web'     => 'Web',
            'sanctum' => 'Sanctum',
        ],
    ],

    'notification' => [
        'system-role-delete' => [
            'title' => 'No se puede eliminar el rol del sistema',
            'body'  => 'Este es un rol del sistema y no se puede eliminar.',
        ],
    ],

    'table' => [
        'actions' => [
            'delete' => [
                'notification' => [
                    'title' => 'Rol eliminado',
                    'body'  => 'El rol se ha eliminado correctamente.',
                ],
            ],
        ],

        'bulk-actions' => [
            'delete' => [
                'notification' => [
                    'title' => 'Roles eliminados',
                    'body'  => 'Los roles seleccionados se han eliminado correctamente.',
                ],
            ],
        ],
    ],
];

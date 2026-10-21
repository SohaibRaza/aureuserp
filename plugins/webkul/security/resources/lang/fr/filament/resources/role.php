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
            'title' => 'Le rôle système ne peut pas être supprimé',
            'body'  => 'Il s’agit d’un rôle système et il ne peut pas être supprimé.',
        ],
    ],

    'table' => [
        'actions' => [
            'delete' => [
                'notification' => [
                    'title' => 'Rôle supprimé',
                    'body'  => 'Le rôle a été supprimé avec succès.',
                ],
            ],
        ],

        'bulk-actions' => [
            'delete' => [
                'notification' => [
                    'title' => 'Rôles supprimés',
                    'body'  => 'Les rôles sélectionnés ont été supprimés avec succès.',
                ],
            ],
        ],
    ],
];

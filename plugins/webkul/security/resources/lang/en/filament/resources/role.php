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
            'title' => 'System Role Cannot Be Deleted',
            'body'  => 'This is a system role and cannot be deleted.',
        ],
    ],

    'table' => [
        'actions' => [
            'delete' => [
                'notification' => [
                    'title' => 'Role deleted',
                    'body'  => 'The role has been deleted successfully.',
                ],
            ],
        ],

        'bulk-actions' => [
            'delete' => [
                'notification' => [
                    'title' => 'Roles deleted',
                    'body'  => 'The selected roles have been deleted successfully.',
                ],
            ],
        ],
    ],
];

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
            'title' => 'Função do sistema não pode ser excluída',
            'body'  => 'Esta é uma função do sistema e não pode ser excluída.',
        ],
    ],

    'table' => [
        'actions' => [
            'delete' => [
                'notification' => [
                    'title' => 'Função excluída',
                    'body'  => 'A função foi excluída com sucesso.',
                ],
            ],
        ],

        'bulk-actions' => [
            'delete' => [
                'notification' => [
                    'title' => 'Funções excluídas',
                    'body'  => 'As funções selecionadas foram excluídas com sucesso.',
                ],
            ],
        ],
    ],
];

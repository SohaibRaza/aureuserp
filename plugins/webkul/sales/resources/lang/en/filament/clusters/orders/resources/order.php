<?php

return [
    'title' => 'Order',

    'navigation' => [
        'title' => 'Orders',
    ],

    'pages' => [
        'create' => [
            'notification' => [
                'title'       => 'Order created',
                'body'        => 'The sales order has been confirmed successfully.',
                'error-title' => 'Order could not be confirmed',
            ],
        ],
    ],
];

<?php

return [
    'form' => [
        'fields' => [
            'name'               => 'الاسم',
            'rounding-precision' => 'دقة التقريب',
            'rounding-strategy'  => 'استراتيجية التقريب',
            'profit-account'     => 'حساب الأرباح',
            'loss-account'       => 'حساب الخسائر',
            'rounding-method'    => 'طريقة التقريب',
        ],
    ],

    'table' => [
        'columns' => [
            'name'                 => 'الاسم',
            'rounding-strategy'    => 'استراتيجية التقريب',
            'rounding-method'      => 'طريقة التقريب',
            'created-by'           => 'أنشئ بواسطة',
            'profit-account'       => 'حساب الأرباح',
            'loss-account'         => 'حساب الخسائر',
        ],

        'groups' => [
            'name'              => 'الاسم',
            'rounding-strategy' => 'استراتيجية التقريب',
            'rounding-method'   => 'طريقة التقريب',
            'created-by'        => 'أنشئ بواسطة',
            'profit-account'    => 'حساب الأرباح',
            'loss-account'      => 'حساب الخسائر',
        ],

        'actions' => [
            'edit' => [
                'notification' => [
                    'title' => 'تم تحديث تقريب النقد',
                    'body'  => 'تم تحديث تقريب النقد بنجاح.',
                ],
            ],

            'delete' => [
                'notification' => [
                    'title' => 'تم حذف تقريب النقد',
                    'body'  => 'تم حذف تقريب النقد بنجاح.',
                ],
            ],
        ],

        'bulk-actions' => [
            'delete' => [
                'notification' => [
                    'title' => 'تم حذف تقريب النقد',
                    'body'  => 'تم حذف تقريب النقد بنجاح.',
                ],
            ],
        ],
    ],

    'infolist' => [
        'entries' => [
            'name'               => 'الاسم',
            'rounding-precision' => 'دقة التقريب',
            'rounding-strategy'  => 'استراتيجية التقريب',
            'profit-account'     => 'حساب الأرباح',
            'loss-account'       => 'حساب الخسائر',
            'rounding-method'    => 'طريقة التقريب',
        ],
    ],
];

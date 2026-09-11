<?php

return [
    'model-label' => 'Company Bank Account',
    'navigation'  => [
        'title' => 'Bank Accounts',
        'group' => 'Banking',
    ],
    'form' => [
        'account-number'         => 'Account Number / IBAN',
        'bank'                   => 'Bank',
        'currency'               => 'Currency',
        'source-type'            => 'Statement Source',
        'source-identifier'      => 'Source Identifier',
        'source-identifier-help' => 'Optional bank-feed or API connection identifier.',
        'active'                 => 'Active',
    ],
    'table' => [
        'account-number' => 'Account Number / IBAN',
        'bank'           => 'Bank',
        'currency'       => 'Currency',
        'journals'       => 'Linked Journals',
        'active'         => 'Active',
    ],
];

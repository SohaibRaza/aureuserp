<?php

return [
    'parallel_token'                       => env('TEST_TOKEN'),
    'reuse_database'                       => env('AUREUSERP_TEST_REUSE_DATABASE', false),
    'run_reconciliation_concurrency'       => env('AUREUSERP_RUN_CONCURRENCY_TEST', false),
    'run_sales_confirmation_concurrency'   => env('AUREUSERP_RUN_SALES_CONFIRMATION_CONCURRENCY', false),
];

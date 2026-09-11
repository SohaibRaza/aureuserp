<?php

return [
    'financial_mutations_enabled' => (bool) env('RECONCILIATION_FINANCIAL_MUTATIONS_ENABLED', false),
    'require_company_rollout'     => (bool) env('RECONCILIATION_REQUIRE_COMPANY_ROLLOUT', false),
    'require_different_reviewer'  => (bool) env('RECONCILIATION_REQUIRE_DIFFERENT_REVIEWER', false),
];

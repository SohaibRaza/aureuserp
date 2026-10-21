<?php

return [
    'move' => [
        'no-journal-found-title'      => 'Accounting is not set up',
        'no-journal-found'            => 'No journal could be found in company :company for any of those types: :types. Please set up a chart of accounts for this company first.',
        'cancel-draft-only'           => 'Only draft journal entries can be cancelled.',
        'reset-posted-cancelled-only' => 'Only posted or cancelled journal entries can be reset to draft.',
        'reset-exchange-difference'   => 'This currency adjustment cannot be reset directly. Remove its related payment match first.',
        'posting-identity-changed'    => 'The journal entry or its reversal source changed. Reload it before posting.',
        'completed-bank-period'       => 'This transaction affects a completed bank reconciliation period. Reopen the reconciliation first.',
        'bank-period-busy'            => 'Accounting or bank period completion is in progress. Refresh and try again.',
    ],

    'reconciliation' => [
        'unavailable'        => 'The accounting entries are no longer available. Refresh and try again.',
        'changed'            => 'The reconciliation changed while this action was waiting. Refresh and try again.',
        'bank-match'         => 'Remove the bank reconciliation match before changing these entries.',
        'already-reconciled' => 'Some of these entries are already matched. Refresh the page and try again.',
    ],

    'tax-formula' => [
        'empty'                => 'The formula cannot be empty.',
        'invalid-character'    => 'The formula contains an invalid character: :character',
        'unexpected-token'     => 'The formula contains an unexpected token: :token',
        'unexpected-end'       => 'The formula ends unexpectedly.',
        'unclosed-parenthesis' => 'The formula contains an unclosed parenthesis.',
        'unknown-variable'     => 'Unknown variable ":variable". Only these variables are available: :variables',
        'unknown-function'     => 'Unknown function ":function". Only these functions are available: :functions',
    ],

    'payment-registration' => [
        'connection-mismatch'       => 'Payment registration requires all records to use the Accounts database connection.',
        'unsaved-instruction'       => 'A payment instruction must be saved before registration.',
        'missing-instruction'       => 'Payment instruction does not exist.',
        'instruction-company'       => 'Payment instruction is outside the active company scope.',
        'changed-instruction'       => 'Payment instruction changed; reload it before registration.',
        'completed-conflict'        => 'Payment instruction conflicts with its completed registration.',
        'unverified-instruction'    => 'Historical payment instruction has no verified outcome; reconcile it before retrying.',
        'missing-source-lines'      => 'Payment instruction has no source lines.',
        'ineligible-sources'        => 'Payment sources are no longer eligible for this company.',
        'invalid-amount'            => 'Payment amount must be positive and cannot exceed the remaining source amount.',
        'invalid-journal-method'    => 'Payment journal and method must belong to the instruction company.',
        'invalid-batch-amount'      => 'Payment batches must total the requested amount; partial batch allocation is not supported.',
        'mixed-batch-currencies'    => 'Payment batches in different currencies require separate instructions.',
        'request-company'           => 'Payment request is outside the active company scope.',
        'request-key-conflict'      => 'Payment request key was already used for a different instruction.',
        'invalid-request-lines'     => 'Payment source lines must belong to the requested move and company.',
        'request-argument-conflict' => 'Payment request arguments conflict with the completed instruction.',
        'invalid-request-key'       => 'Payment request key must contain 1 to 128 ASCII letters, digits, dots, colons, underscores or hyphens.',
    ],

    'payment' => [
        'unavailable'                  => 'The payment is no longer available. Refresh and try again.',
        'company'                      => 'Switch to the payment\'s company to continue.',
        'changed'                      => 'The payment or its journal entry changed while this action was waiting. Refresh and try again.',
        'journal-mismatch'             => 'The payment\'s journal entry is linked incorrectly. Ask your Accounts administrator to check it.',
        'cancel-draft-only'            => 'Only draft payments can be cancelled.',
        'reset-non-draft-only'         => 'This payment is already in draft.',
        'post-draft-process-paid-only' => 'This payment cannot be confirmed in its current state. Refresh and check its status.',
        'bank-validation'              => 'To record payments with :method, validate the recipient bank account for :partner first.',
    ],

    'payment-reversal' => [
        'posted-only' => 'Only posted journal entries can be reversed.',
        'paid-cancel' => 'A paid journal entry cannot be reversed and cancelled; issue a credit note or refund instead.',
    ],
];

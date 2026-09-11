<?php

use App\Models\Recon\BankReconciliation;
use App\Models\Recon\ReconMatch;
use App\Services\Recon\AdjustmentService;
use App\Services\Recon\FinalizationService;
use App\Services\Recon\MatchingEngine;
use App\Services\Recon\MatchService;
use App\Services\Recon\ReconciliationSafety;

it('blocks every unsafe reconciliation service entry point while financial mutations are disabled', function () {
    config()->set('reconciliation.financial_mutations_enabled', false);

    $reconciliation = new BankReconciliation;
    $match = new ReconMatch;

    $actions = [
        fn () => app(MatchService::class)->createMatch($reconciliation, [1], [1]),
        fn () => app(MatchService::class)->confirm($match),
        fn () => app(MatchService::class)->unmatch($match),
        fn () => app(MatchService::class)->applyMatchEffects($match),
        fn () => app(MatchingEngine::class)->autoMatch($reconciliation),
        fn () => app(AdjustmentService::class)->post($reconciliation, 'bank_fee', -10, 1),
        fn () => app(FinalizationService::class)->complete($reconciliation),
        fn () => app(FinalizationService::class)->reopen($reconciliation, 'Correction required'),
    ];

    foreach ($actions as $action) {
        expect($action)->toThrow(
            InvalidArgumentException::class,
            'Financial reconciliation actions are temporarily disabled while reconciliation controls are being upgraded.',
        );
    }
});

it('reports that financial mutations are disabled', function () {
    config()->set('reconciliation.financial_mutations_enabled', false);

    expect(app(ReconciliationSafety::class)->financialMutationsEnabled())->toBeFalse();
});

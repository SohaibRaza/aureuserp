<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Shafique\Logistics\Models\ApprovalEvent;
use Shafique\Logistics\Models\CompanyControl;
use Shafique\Logistics\Services\ApprovalWorkflow;
use Shafique\Logistics\Services\CommandIdentity;

require_once __DIR__.'/../../../../webkul/support/tests/Helpers/SecurityHelper.php';

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('logistics');
});

it('requires a different approver and records an exact scoped approval', function (): void {
    $requester = SecurityHelper::authenticateWithPermissions(['request_logistics_approval']);
    $approver = SecurityHelper::authenticateWithPermissions(['approve_logistics_approval']);
    Auth::shouldUse('web');
    $companyId = $requester->default_company_id;
    CompanyControl::query()->create(['company_id' => $companyId, 'accept_new_activity' => true, 'revision' => 4]);
    $workflow = app(ApprovalWorkflow::class);
    $approval = $workflow->approve(
        $requester,
        $approver,
        $companyId,
        'approval-issued-20261016-0001',
        'load.verify',
        'trip',
        77,
        3,
        ['quantity' => '12.500'],
        Carbon::now()->addHour(),
        'Synthetic independent approval',
    );

    expect($approval->company_id)->toBe($companyId)
        ->and($approval->action)->toBe('load.verify')
        ->and($approval->scope_type)->toBe('trip')
        ->and($approval->scope_id)->toBe(77)
        ->and($approval->scope_revision)->toBe(3)
        ->and($approval->control_revision)->toBe(4)
        ->and($approval->requested_by)->toBe($requester->id)
        ->and($approval->approved_by)->toBe($approver->id)
        ->and(ApprovalEvent::query()->sole()->event_type)->toBe('approved');

    Auth::guard('web')->login($requester);
    Auth::shouldUse('web');
    expect(fn () => $workflow->approve(
        $requester,
        $approver,
        $companyId,
        'approval-issued-20261016-0003',
        'load.verify',
        'trip',
        77,
        3,
        ['quantity' => '12.500'],
        Carbon::now()->addHour(),
        'Attempt to submit another user as approver',
    ))->toThrow(AuthorizationException::class);

    Auth::guard('web')->login($approver);
    Auth::shouldUse('web');

    expect(fn () => $workflow->approve(
        $requester,
        $requester,
        $companyId,
        'approval-issued-20261016-0002',
        'load.verify',
        'trip',
        77,
        3,
        ['quantity' => '12.500'],
        Carbon::now()->addHour(),
        'Self approval is prohibited',
    ))->toThrow(ValidationException::class);
});

it('consumes an approval once for the matching revision and within its limits', function (): void {
    $requester = SecurityHelper::authenticateWithPermissions(['request_logistics_approval']);
    $approver = SecurityHelper::authenticateWithPermissions(['approve_logistics_approval', 'consume_logistics_approval']);
    Auth::shouldUse('web');
    $companyId = $requester->default_company_id;
    CompanyControl::query()->create(['company_id' => $companyId, 'accept_new_activity' => true, 'revision' => 2]);
    $workflow = app(ApprovalWorkflow::class);
    $approval = $workflow->approve($requester, $approver, $companyId, 'approval-issued-20261016-0003', 'load.verify', 'trip', 88, 5, ['quantity' => '12.500'], Carbon::now()->addHour(), 'Synthetic load approval');
    $command = app(CommandIdentity::class);
    $intent = ['approval_id' => $approval->id, 'trip_id' => 88, 'load_revision' => 5];
    $result = $command->run($approver, $companyId, 'load.verify', 'load-verify-20261016-0001', $intent, 'consume_logistics_approval', function () use ($workflow, $approver, $approval): array {
        $workflow->consume($approver, $approval->company_id, $approval->id, 'load.verify', 'trip', 88, 5, ['quantity' => '12.499'], 'load.verify', 'load-verify-20261016-0001');

        return ['verified' => true];
    });

    expect($result)->toBe(['verified' => true])
        ->and(ApprovalEvent::query()->orderBy('id')->pluck('event_type')->all())->toBe(['approved', 'consumed']);

    expect(fn () => $command->run($approver, $companyId, 'load.verify', 'load-verify-20261016-0002', $intent, 'consume_logistics_approval', function () use ($workflow, $approver, $approval): array {
        $workflow->consume($approver, $approval->company_id, $approval->id, 'load.verify', 'trip', 88, 5, ['quantity' => '12.499'], 'load.verify', 'load-verify-20261016-0002');

        return ['verified' => true];
    }))->toThrow(ValidationException::class);
});

it('rejects stale scope, expired approval, and values above the approved limit', function (string $case): void {
    $requester = SecurityHelper::authenticateWithPermissions(['request_logistics_approval']);
    $approver = SecurityHelper::authenticateWithPermissions(['approve_logistics_approval', 'consume_logistics_approval']);
    Auth::shouldUse('web');
    $companyId = $requester->default_company_id;
    CompanyControl::query()->create(['company_id' => $companyId, 'accept_new_activity' => true, 'revision' => 2]);
    $expiry = Carbon::now()->addHour();
    $approval = app(ApprovalWorkflow::class)->approve($requester, $approver, $companyId, 'approval-issued-20261016-0004', 'release.stock', 'trip', 99, 6, ['quantity' => '5.000'], $expiry, 'Synthetic scope test');
    if ($case === 'expired') {
        DB::table('logistics_scoped_approvals')->where('id', $approval->id)->update(['valid_until' => now()->subSecond()]);
    }
    if ($case === 'stale control') {
        DB::table('logistics_company_controls')->where('company_id', $companyId)->update(['revision' => 3]);
    }
    $consume = fn () => DB::transaction(fn () => app(ApprovalWorkflow::class)->consume($approver, $companyId, $approval->id, 'release.stock', 'trip', $case === 'stale scope' ? 100 : 99, 6, ['quantity' => $case === 'over limit' ? '5.001' : '5.000'], 'release.stock', 'release-command-20261016-0001'));

    expect($consume)->toThrow(ValidationException::class);
})->with(['stale scope', 'stale control', 'expired', 'over limit']);

<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Shafique\Logistics\Models\CompanyControl;
use Shafique\Logistics\Services\CommandIdentity;

require_once __DIR__.'/../../../../webkul/support/tests/Helpers/SecurityHelper.php';

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('logistics');
});

it('returns the saved result for a same-intent retry without running the operation twice', function (): void {
    $user = SecurityHelper::authenticateWithPermissions([
        'create_logistics_evidence',
        'view_any_logistics_module::setting',
        'update_logistics_module::setting',
    ]);
    $companyId = $user->default_company_id;
    CompanyControl::query()->create(['company_id' => $companyId, 'accept_new_activity' => true, 'revision' => 1]);
    $calls = 0;
    $command = app(CommandIdentity::class);
    $run = function (array $intent) use ($command, $user, $companyId, &$calls): array {
        return $command->run(
            $user,
            $companyId,
            'evidence.accept',
            'evidence-upload-20261016-0001',
            $intent,
            'create_logistics_evidence',
            function () use (&$calls): array {
                $calls++;

                return ['evidence_id' => 91];
            },
        );
    };

    $first = $run(['purpose' => 'delivery_receipt', 'sha256' => str_repeat('a', 64)]);
    $retry = $run(['sha256' => str_repeat('a', 64), 'purpose' => 'delivery_receipt']);

    expect($first)->toBe(['evidence_id' => 91])
        ->and($retry)->toBe($first)
        ->and($calls)->toBe(1);
});

it('rejects a changed intent under the same command identity without effects', function (): void {
    $user = SecurityHelper::authenticateWithPermissions([
        'create_logistics_evidence',
        'view_any_logistics_module::setting',
        'update_logistics_module::setting',
    ]);
    $companyId = $user->default_company_id;
    CompanyControl::query()->create(['company_id' => $companyId, 'accept_new_activity' => true, 'revision' => 1]);
    $command = app(CommandIdentity::class);
    $operation = fn (): array => ['evidence_id' => 91];
    $command->run($user, $companyId, 'evidence.accept', 'evidence-upload-20261016-0002', ['sha256' => str_repeat('a', 64)], 'create_logistics_evidence', $operation);
    $before = DB::table('logistics_command_records')->count();

    expect(fn () => $command->run($user, $companyId, 'evidence.accept', 'evidence-upload-20261016-0002', ['sha256' => str_repeat('b', 64)], 'create_logistics_evidence', $operation))
        ->toThrow(ValidationException::class)
        ->and(DB::table('logistics_command_records')->count())->toBe($before);
});

it('does not allow a new command after suspension but still returns an authorized saved result', function (): void {
    $user = SecurityHelper::authenticateWithPermissions([
        'create_logistics_evidence',
        'view_any_logistics_module::setting',
        'update_logistics_module::setting',
    ]);
    $companyId = $user->default_company_id;
    CompanyControl::query()->create(['company_id' => $companyId, 'accept_new_activity' => true, 'revision' => 1]);
    $command = app(CommandIdentity::class);
    $intent = ['purpose' => 'delivery_receipt'];
    $command->run($user, $companyId, 'evidence.accept', 'evidence-upload-20261016-0003', $intent, 'create_logistics_evidence', fn (): array => ['evidence_id' => 92]);
    CompanyControl::query()->where('company_id', $companyId)->update(['accept_new_activity' => false, 'revision' => 2]);

    expect($command->run($user, $companyId, 'evidence.accept', 'evidence-upload-20261016-0003', $intent, 'create_logistics_evidence', fn (): array => ['evidence_id' => 93]))
        ->toBe(['evidence_id' => 92]);

    expect(fn () => $command->run($user, $companyId, 'evidence.accept', 'evidence-upload-20261016-0004', $intent, 'create_logistics_evidence', fn (): array => ['evidence_id' => 94]))
        ->toThrow(DomainException::class);
});

it('refuses to roll back accepted command and approval history without dropping either table', function (): void {
    $user = SecurityHelper::authenticateWithPermissions([
        'create_logistics_evidence',
        'view_any_logistics_module::setting',
        'update_logistics_module::setting',
    ]);
    $companyId = $user->default_company_id;
    CompanyControl::query()->create(['company_id' => $companyId, 'accept_new_activity' => true, 'revision' => 1]);
    app(CommandIdentity::class)->run(
        $user,
        $companyId,
        'evidence.accept',
        'rollback-preservation-command',
        ['sha256' => str_repeat('c', 64)],
        'create_logistics_evidence',
        fn (): array => ['evidence_id' => 97],
    );
    DB::table('logistics_scoped_approvals')->insert([
        'company_id'              => $companyId,
        'command_idempotency_key' => 'rollback-preservation-approval',
        'action'                  => 'load.verify',
        'scope_type'              => 'trip',
        'scope_id'                => 97,
        'scope_revision'          => 1,
        'limits'                  => json_encode(['quantity' => '10']),
        'requested_by'            => $user->id,
        'approved_by'             => $user->id,
        'reason'                  => 'Synthetic rollback preservation fixture',
        'control_revision'        => 1,
        'valid_until'             => now()->addHour(),
        'created_at'              => now(),
    ]);
    $migration = require __DIR__.'/../../database/migrations/2026_10_16_000300_create_logistics_commands_and_approvals.php';

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward recovery');
    expect(DB::table('logistics_command_records')->count())->toBe(1)
        ->and(DB::table('logistics_scoped_approvals')->count())->toBe(1);
});

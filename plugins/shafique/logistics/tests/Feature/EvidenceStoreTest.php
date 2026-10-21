<?php

use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Shafique\Logistics\Models\EvidenceLifecycleEvent;
use Shafique\Logistics\Services\EvidenceStore;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../../webkul/support/tests/Helpers/SecurityHelper.php';

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('logistics');
    Storage::fake('logistics-evidence');
});

it('blocks evidence acceptance until the Company has an approved retention policy', function (): void {
    $user = SecurityHelper::authenticateWithPermissions(['create_logistics_evidence']);
    $file = UploadedFile::fake()->createWithContent('delivery.pdf', "%PDF-1.7\nsynthetic delivery evidence\n");

    expect($user->getAllPermissions()->pluck('name'))->toContain('create_logistics_evidence');

    expect(fn () => app(EvidenceStore::class)->store($user, $user->default_company_id, $file, 'delivery_receipt', 'evidence-retention-test-0001'))
        ->toThrow(ValidationException::class, 'An approved evidence retention policy is required before files can be accepted.');
});

it('stores allowed evidence privately with a content hash and immutable metadata', function (): void {
    $user = SecurityHelper::authenticateWithPermissions(['create_logistics_evidence', 'view_any_logistics_evidence', 'view_logistics_evidence', 'manage_logistics_evidence_retention']);
    $company = Company::query()->findOrFail($user->default_company_id);
    $file = UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.7\nsynthetic delivery evidence\n");

    $policy = app(EvidenceStore::class)->approveRetentionPolicy($user, $company->id, 365, 'Synthetic test policy');
    $evidence = app(EvidenceStore::class)->store($user, $company->id, $file, 'delivery_receipt', 'evidence-upload-test-0001');
    $retry = app(EvidenceStore::class)->store($user, $company->id, $file, 'delivery_receipt', 'evidence-upload-test-0001');

    expect($evidence->company_id)->toBe($company->id)
        ->and($evidence->retention_policy_id)->toBe($policy->id)
        ->and($evidence->detected_mime_type)->toBe('application/pdf')
        ->and($evidence->sha256)->toBe(hash('sha256', "%PDF-1.7\nsynthetic delivery evidence\n"))
        ->and(Storage::disk('logistics-evidence')->exists($evidence->object_key))->toBeTrue()
        ->and($evidence->original_name)->toBe('receipt.pdf')
        ->and(app(EvidenceStore::class)->assertAttachable($user, $company->id, $evidence->id, ['delivery_receipt'])->id)->toBe($evidence->id)
        ->and($retry->id)->toBe($evidence->id);
});

it('rejects content whose detected format is not on the approved allowlist', function (): void {
    $user = SecurityHelper::authenticateWithPermissions(['create_logistics_evidence', 'manage_logistics_evidence_retention']);
    app(EvidenceStore::class)->approveRetentionPolicy($user, $user->default_company_id, 365, 'Synthetic test policy');
    $file = UploadedFile::fake()->createWithContent('receipt.pdf', '<script>alert(1)</script>');

    expect(fn () => app(EvidenceStore::class)->store($user, $user->default_company_id, $file, 'delivery_receipt', 'evidence-invalid-test-0001'))
        ->toThrow(ValidationException::class);
});

function expiredEvidenceForDisposal(): array
{
    $permissions = ['create_logistics_evidence', 'manage_logistics_evidence_retention',
        'dispose_logistics_evidence', 'approve_logistics_evidence_disposal',
        'place_logistics_evidence_legal_hold', 'release_logistics_evidence_legal_hold'];
    $actor = SecurityHelper::authenticateWithPermissions($permissions);
    $reviewer = SecurityHelper::authenticateWithPermissions($permissions);
    $company = Company::factory()->create(['is_active' => true]);
    foreach ([$actor, $reviewer] as $user) {
        $user->forceFill(['default_company_id' => $company->id])->saveQuietly();
        $user->allowedCompanies()->sync([$company->id]);
    }
    session([CompanyContext::SESSION_KEY => [$company->id]]);
    app()->forgetInstance(CompanyContext::class);
    $store = app(EvidenceStore::class);
    $store->approveRetentionPolicy($actor, $actor->default_company_id, 1, 'Synthetic disposal test policy');
    $evidence = $store->store($actor, $actor->default_company_id,
        UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.7\nsynthetic retained evidence\n"),
        'delivery_receipt', (string) Str::uuid());
    test()->travel(2)->days();
    expect(DB::connection()->getDatabaseName())->toEndWith('_testing');
    while (DB::connection()->transactionLevel() > 0) {
        DB::connection()->commit();
    }

    return compact('actor', 'reviewer', 'store', 'evidence');
}

it('retains the evidence tombstone and returns a completed same-intent disposal retry without deleting twice', function (): void {
    extract(expiredEvidenceForDisposal());
    $metadata = $evidence->getAttributes();
    $store->dispose($actor, $reviewer, $evidence->id, 'Approved retention expiry');
    $store->dispose($actor, $reviewer, $evidence->id, 'Approved retention expiry');
    expect(Storage::disk('logistics-evidence')->exists($evidence->object_key))->toBeFalse()
        ->and($evidence->fresh()->getAttributes())->toBe($metadata)
        ->and($evidence->lifecycleEvents()->where('event_type', 'disposed_per_retention_policy')->count())->toBe(1);
    expect(fn () => $store->placeLegalHold($actor, $evidence->id, 'Too late for a new hold'))
        ->toThrow(ValidationException::class);
});

it('never reports unexplained missing or corrupt evidence as an approved disposal', function (bool $corrupt): void {
    extract(expiredEvidenceForDisposal());
    $disk = Storage::disk('logistics-evidence');
    if ($corrupt) {
        $disk->put($evidence->object_key, 'unexpected changed bytes');
    } else {
        $disk->delete($evidence->object_key);
    }
    expect(fn () => $store->dispose($actor, $reviewer, $evidence->id, 'Approved retention expiry'))
        ->toThrow(DomainException::class);
    expect($evidence->lifecycleEvents()->where('event_type', 'disposed_per_retention_policy')->exists())->toBeFalse()
        ->and($evidence->lifecycleEvents()->where('event_type', 'disposal_integrity_failed')->count())->toBe(1)
        ->and($disk->exists($evidence->object_key))->toBe($corrupt);
})->with(['missing' => false, 'corrupt' => true]);

it('honors a legal hold accepted after disposal becomes pending', function (): void {
    extract(expiredEvidenceForDisposal());
    $dispatcher = EvidenceLifecycleEvent::getEventDispatcher();
    EvidenceLifecycleEvent::setEventDispatcher(clone $dispatcher);
    EvidenceLifecycleEvent::created(function (EvidenceLifecycleEvent $event) use ($store, $actor, $evidence): void {
        if ($event->evidence_id === $evidence->id && $event->event_type === 'disposal_pending') {
            $store->placeLegalHold($actor, $evidence->id, 'Preserve this document for investigation');
        }
    });
    try {
        expect(fn () => $store->dispose($actor, $reviewer, $evidence->id, 'Approved retention expiry'))
            ->toThrow(ValidationException::class);
        expect(Storage::disk('logistics-evidence')->exists($evidence->object_key))->toBeTrue()
            ->and($evidence->legalHoldEvents()->where('action', 'placed')->count())->toBe(1)
            ->and($evidence->lifecycleEvents()->where('event_type', 'disposed_per_retention_policy')->exists())->toBeFalse();
    } finally {
        EvidenceLifecycleEvent::setEventDispatcher($dispatcher);
    }
});

it('keeps a deletion with an unrecorded outcome unresolved rather than inferring success from missing bytes', function (): void {
    extract(expiredEvidenceForDisposal());
    $dispatcher = EvidenceLifecycleEvent::getEventDispatcher();
    EvidenceLifecycleEvent::setEventDispatcher(clone $dispatcher);
    EvidenceLifecycleEvent::creating(function (EvidenceLifecycleEvent $event): void {
        if ($event->event_type === 'disposed_per_retention_policy') {
            throw new RuntimeException('Injected completion-record failure');
        }
    });
    try {
        expect(fn () => $store->dispose($actor, $reviewer, $evidence->id, 'Approved retention expiry'))
            ->toThrow(RuntimeException::class, 'Injected completion-record failure');
    } finally {
        EvidenceLifecycleEvent::setEventDispatcher($dispatcher);
    }
    expect(Storage::disk('logistics-evidence')->exists($evidence->object_key))->toBeFalse();
    expect(fn () => $store->dispose($actor, $reviewer, $evidence->id, 'Approved retention expiry'))
        ->toThrow(DomainException::class);
    expect($evidence->lifecycleEvents()->where('event_type', 'disposed_per_retention_policy')->exists())->toBeFalse()
        ->and($evidence->lifecycleEvents()->where('event_type', 'disposal_integrity_failed')->exists())->toBeTrue();
});

it('holds the evidence row lock through deletion against an independent PostgreSQL hold request', function (): void {
    if (! config('testing.run_reconciliation_concurrency') || DB::connection()->getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requires the opted-in private PostgreSQL runner.');
    }
    expect(DB::connection()->getDatabaseName())->toMatch('/^aureuserp_[A-Za-z0-9_]+_testing$/');
    extract(expiredEvidenceForDisposal());
    while (DB::connection()->transactionLevel() > 0) {
        DB::connection()->commit();
    }
    $originalConnection = DB::getDefaultConnection();
    config(['database.connections.evidence_competitor' => config('database.connections.'.$originalConnection)]);
    $competitor = DB::connection('evidence_competitor');
    $competitor->statement("SET lock_timeout = '200ms'");
    expect($competitor->selectOne('SELECT pg_backend_pid() AS pid')->pid)
        ->not->toBe(DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
    $disk = Storage::disk('logistics-evidence');
    $proxy = Mockery::mock($disk);
    $blocked = false;
    $proxy->shouldReceive('delete')->once()->andReturnUsing(function (string $path) use ($disk, $store, $actor, $evidence, $originalConnection, &$blocked): bool {
        DB::setDefaultConnection('evidence_competitor');
        try {
            $store->placeLegalHold($actor, $evidence->id, 'Competing hold request');
        } catch (QueryException $exception) {
            $blocked = $exception->getCode() === '55P03';
            if (! $blocked) {
                throw $exception;
            }
        } finally {
            DB::setDefaultConnection($originalConnection);
        }

        return $disk->delete($path);
    });
    Storage::shouldReceive('disk')->with('logistics-evidence')->andReturn($proxy);
    try {
        $store->dispose($actor, $reviewer, $evidence->id, 'Approved retention expiry');
        expect($blocked)->toBeTrue()
            ->and($evidence->legalHoldEvents()->count())->toBe(0)
            ->and($evidence->lifecycleEvents()->where('event_type', 'disposed_per_retention_policy')->count())->toBe(1);
        expect(fn () => $store->placeLegalHold($actor, $evidence->id, 'Hold after completed disposal'))
            ->toThrow(ValidationException::class);
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::purge('evidence_competitor');
        DB::beginTransaction();
    }
});

it('refuses disposal inside a caller transaction before changing storage or history', function (): void {
    extract(expiredEvidenceForDisposal());
    $events = $evidence->lifecycleEvents()->count();
    DB::beginTransaction();
    try {
        expect(fn () => $store->dispose($actor, $reviewer, $evidence->id, 'Approved retention expiry'))
            ->toThrow(LogicException::class, 'outside an existing database transaction');
        expect(Storage::disk('logistics-evidence')->exists($evidence->object_key))->toBeTrue()
            ->and($evidence->lifecycleEvents()->count())->toBe($events);
    } finally {
        DB::rollBack();
    }
});

it('honors the current legal hold after release and after another hold is placed', function (): void {
    extract(expiredEvidenceForDisposal());
    $first = $store->placeLegalHold($actor, $evidence->id, 'First investigation');
    $store->releaseLegalHold($actor, $evidence->id, $first->id, 'First investigation completed');
    $second = $store->placeLegalHold($actor, $evidence->id, 'Second investigation');
    expect(fn () => $store->dispose($actor, $reviewer, $evidence->id, 'Approved retention expiry'))
        ->toThrow(ValidationException::class);
    expect(Storage::disk('logistics-evidence')->exists($evidence->object_key))->toBeTrue();
    $store->releaseLegalHold($actor, $evidence->id, $second->id, 'Second investigation completed');
    $store->dispose($actor, $reviewer, $evidence->id, 'Approved retention expiry');
    expect(Storage::disk('logistics-evidence')->exists($evidence->object_key))->toBeFalse()
        ->and($evidence->lifecycleEvents()->where('event_type', 'disposed_per_retention_policy')->count())->toBe(1);
});

it('retries a failed storage deletion only with the original authorized intent', function (): void {
    extract(expiredEvidenceForDisposal());
    $disk = Storage::disk('logistics-evidence');
    $proxy = Mockery::mock($disk);
    $proxy->shouldReceive('delete')->once()->andReturn(false)->ordered();
    $proxy->shouldReceive('delete')->once()->andReturnUsing(fn (string $path): bool => $disk->delete($path))->ordered();
    Storage::shouldReceive('disk')->with('logistics-evidence')->andReturn($proxy);
    expect(fn () => $store->dispose($actor, $reviewer, $evidence->id, 'Approved retention expiry'))
        ->toThrow(DomainException::class);
    expect($disk->exists($evidence->object_key))->toBeTrue()
        ->and($evidence->lifecycleEvents()->where('event_type', 'disposal_pending')->count())->toBe(1);
    expect(fn () => $store->dispose($actor, $reviewer, $evidence->id, 'A different disposal request'))
        ->toThrow(ValidationException::class);
    expect(fn () => $store->dispose($reviewer, $actor, $evidence->id, 'Approved retention expiry'))
        ->toThrow(ValidationException::class);
    $store->dispose($actor, $reviewer, $evidence->id, 'Approved retention expiry');
    expect($disk->exists($evidence->object_key))->toBeFalse()
        ->and($evidence->lifecycleEvents()->where('event_type', 'disposal_pending')->count())->toBe(1)
        ->and($evidence->lifecycleEvents()->where('event_type', 'disposed_per_retention_policy')->count())->toBe(1);
});

<?php

namespace Shafique\Logistics\Services;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Flysystem\FilesystemException;
use LogicException;
use Shafique\Logistics\Models\Evidence;
use Shafique\Logistics\Models\EvidenceLegalHold;
use Shafique\Logistics\Models\EvidenceLifecycleEvent;
use Shafique\Logistics\Models\EvidenceRetentionPolicy;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\PluginManager\Models\Plugin;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\AllowedCompanyScope;

class EvidenceStore
{
    private const EVIDENCE_CLASS = 'operational';

    /** @var array<string, string> */
    private const ALLOWED_MIME_TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
    ];

    public function approveRetentionPolicy(User $actor, int $companyId, int $retentionDays, string $reason): EvidenceRetentionPolicy
    {
        if ($retentionDays < 1) {
            throw ValidationException::withMessages([
                'retention_days' => __('logistics::settings.evidence.invalid_retention_period'),
            ]);
        }

        $reason = $this->validateReason($reason);

        return DB::transaction(function () use ($actor, $companyId, $retentionDays, $reason): EvidenceRetentionPolicy {
            if (! $this->lockCompany($companyId)->is_active) {
                throw new DomainException(__('logistics::settings.company_inactive'));
            }
            $actor = $this->authorize($actor, $companyId, 'manage_logistics_evidence_retention');
            $revision = ((int) EvidenceRetentionPolicy::query()
                ->where('company_id', $companyId)
                ->where('evidence_class', self::EVIDENCE_CLASS)
                ->max('revision')) + 1;

            return EvidenceRetentionPolicy::query()->create([
                'company_id'      => $companyId,
                'evidence_class'  => self::EVIDENCE_CLASS,
                'revision'        => $revision,
                'retention_days'  => $retentionDays,
                'approved_by'     => $actor->getKey(),
                'approval_reason' => $reason,
                'approved_at'     => now(),
                'created_at'      => now(),
            ]);
        });
    }

    public function store(User $actor, int $companyId, UploadedFile $file, string $purpose, string $idempotencyKey): Evidence
    {
        $purpose = $this->validatePurpose($purpose);
        $detectedMimeType = $this->detectAllowedMimeType($file);
        $sourcePath = $file->getRealPath();
        $byteSize = $file->getSize();

        if (! $sourcePath || ! is_file($sourcePath) || ! is_int($byteSize) || $byteSize < 1) {
            throw ValidationException::withMessages([
                'file' => __('logistics::settings.evidence.invalid_file'),
            ]);
        }

        $sha256 = hash_file('sha256', $sourcePath);

        if (! is_string($sha256)) {
            throw ValidationException::withMessages([
                'file' => __('logistics::settings.evidence.invalid_file'),
            ]);
        }

        $objectKey = sprintf('company/%d/%s.%s', $companyId, Str::uuid(), self::ALLOWED_MIME_TYPES[$detectedMimeType]);
        $disk = Storage::disk('logistics-evidence');
        $stored = false;

        try {
            $result = app(CommandIdentity::class)->run($actor, $companyId, 'evidence.accept', $idempotencyKey, [
                'purpose'            => $purpose, 'sha256' => $sha256, 'byte_size' => $byteSize,
                'detected_mime_type' => $detectedMimeType,
            ], 'create_logistics_evidence', function () use ($actor, $companyId, $file, $purpose, $detectedMimeType, $sourcePath, $byteSize, $sha256, $objectKey, $disk, &$stored): array {
                $evidence = DB::transaction(function () use ($actor, $companyId, $file, $purpose, $detectedMimeType, $sourcePath, $byteSize, $sha256, $objectKey, $disk, &$stored): Evidence {
                    $this->ensureInstalled();
                    if (! $this->lockCompany($companyId)->is_active) {
                        throw new DomainException(__('logistics::settings.company_inactive'));
                    }
                    $actor = $this->authorize($actor, $companyId, 'create_logistics_evidence');
                    $policy = EvidenceRetentionPolicy::query()
                        ->where('company_id', $companyId)
                        ->where('evidence_class', self::EVIDENCE_CLASS)
                        ->orderByDesc('revision')
                        ->lockForUpdate()
                        ->first();

                    if (! $policy || $policy->approved_at === null || $policy->retention_days < 1) {
                        throw ValidationException::withMessages([
                            'file' => __('logistics::settings.evidence.retention_policy_required'),
                        ]);
                    }

                    $stream = fopen($sourcePath, 'rb');

                    if ($stream === false) {
                        throw ValidationException::withMessages([
                            'file' => __('logistics::settings.evidence.invalid_file'),
                        ]);
                    }

                    $stored = true;

                    try {
                        $disk->writeStream($objectKey, $stream, ['visibility' => 'private']);
                    } finally {
                        fclose($stream);
                    }

                    $storedPath = $disk->path($objectKey);

                    if (! is_file($storedPath)
                        || filesize($storedPath) !== $byteSize
                        || ! hash_equals($sha256, (string) hash_file('sha256', $storedPath))) {
                        throw new DomainException(__('logistics::settings.evidence.storage_verification_failed'));
                    }

                    $evidence = Evidence::query()->create([
                        'company_id'          => $companyId,
                        'retention_policy_id' => $policy->getKey(),
                        'evidence_class'      => self::EVIDENCE_CLASS,
                        'purpose'             => $purpose,
                        'original_name'       => $this->safeOriginalName($file->getClientOriginalName()),
                        'object_key'          => $objectKey,
                        'sha256'              => $sha256,
                        'byte_size'           => $byteSize,
                        'detected_mime_type'  => $detectedMimeType,
                        'uploaded_by'         => $actor->getKey(),
                        'accepted_at'         => now(),
                        'retain_until'        => now()->addDays($policy->retention_days),
                    ]);

                    EvidenceLifecycleEvent::query()->create([
                        'evidence_id' => $evidence->getKey(),
                        'company_id'  => $companyId,
                        'actor_id'    => $actor->getKey(),
                        'event_type'  => 'accepted',
                        'created_at'  => now(),
                    ]);

                    return $evidence;
                });

                return ['evidence_id' => $evidence->getKey()];
            }, false);

            return Evidence::query()->where('company_id', $companyId)->findOrFail($result['evidence_id']);
        } catch (\Throwable $exception) {
            if ($stored) {
                try {
                    $disk->delete($objectKey);
                } catch (\Throwable $cleanupFailure) {
                    Log::error('Unaccepted Logistics evidence bytes could not be removed.', [
                        'object_key' => $objectKey,
                        'sha256'     => $sha256,
                        'exception'  => $cleanupFailure::class,
                    ]);
                }
            }

            throw $exception;
        }
    }

    public function download(User $actor, int $evidenceId): StreamedResponse
    {
        $evidence = Evidence::query()->findOrFail($evidenceId);
        $actor = $this->authorize($actor, (int) $evidence->company_id, 'view_logistics_evidence');
        $this->assertAccepted($evidence);
        $disk = Storage::disk('logistics-evidence');
        $stream = $disk->readStream($evidence->object_key);

        if (! is_resource($stream)) {
            throw new DomainException(__('logistics::settings.evidence.bytes_missing'));
        }

        $hash = hash_init('sha256');
        hash_update_stream($hash, $stream);
        $actualHash = hash_final($hash);
        rewind($stream);

        if (! hash_equals($evidence->sha256, $actualHash)) {
            fclose($stream);
            throw new DomainException(__('logistics::settings.evidence.integrity_failure'));
        }

        return response()->streamDownload(
            function () use ($stream): void {
                fpassthru($stream);
                fclose($stream);
            },
            $evidence->original_name,
            [
                'Content-Type'                  => 'application/octet-stream',
                'X-Content-Type-Options'        => 'nosniff',
                'Cache-Control'                 => 'private, no-store',
                'Content-Security-Policy'       => "sandbox; default-src 'none'",
            ]
        );
    }

    /** @param list<string> $allowedPurposes */
    public function assertAttachable(User $actor, int $companyId, int $evidenceId, array $allowedPurposes): Evidence
    {
        $evidence = Evidence::query()->whereKey($evidenceId)->firstOrFail();

        if ((int) $evidence->company_id !== $companyId || ! in_array($evidence->purpose, $allowedPurposes, true)) {
            throw ValidationException::withMessages([
                'evidence_id' => __('logistics::settings.evidence.invalid_attachment'),
            ]);
        }

        $this->authorize($actor, $companyId, 'view_logistics_evidence');
        $this->assertAccepted($evidence);
        $this->assertObjectIntegrity($evidence);

        return $evidence;
    }

    public function placeLegalHold(User $actor, int $evidenceId, string $reason): EvidenceLegalHold
    {
        return $this->changeLegalHold($actor, $evidenceId, 'place_logistics_evidence_legal_hold', 'placed', $reason, null);
    }

    public function releaseLegalHold(User $actor, int $evidenceId, int $holdId, string $reason): EvidenceLegalHold
    {
        return $this->changeLegalHold($actor, $evidenceId, 'release_logistics_evidence_legal_hold', 'released', $reason, $holdId);
    }

    public function dispose(User $actor, User $reviewer, int $evidenceId, string $reason): void
    {
        if (DB::transactionLevel() > 0) {
            throw new LogicException('Evidence disposal must run outside an existing database transaction so its deletion history can be committed.');
        }

        $reason = $this->validateReason($reason);

        if ($actor->getKey() === $reviewer->getKey()) {
            throw ValidationException::withMessages([
                'reviewer' => __('logistics::settings.evidence.independent_review_required'),
            ]);
        }

        // Persist the attempt before touching storage. A crash cannot erase its authorization.
        DB::transaction(function () use ($actor, $reviewer, $evidenceId, $reason): void {
            $evidence = Evidence::query()->whereKey($evidenceId)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, (int) $evidence->company_id, 'dispose_logistics_evidence');
            $this->authorize($reviewer, (int) $evidence->company_id, 'approve_logistics_evidence_disposal');
            $latestEvent = $this->latestLifecycleEvent($evidence);
            $sameAttempt = $latestEvent?->reason === $reason
                && (int) $latestEvent?->actor_id === (int) $actor->getKey()
                && (int) $latestEvent?->approved_by === (int) $reviewer->getKey();
            if ($latestEvent?->event_type === 'disposed_per_retention_policy' && $sameAttempt) {
                return;
            }
            if ($this->hasActiveLegalHold($evidence)
                || now()->lt($evidence->retain_until)
                || ! in_array($latestEvent?->event_type, ['accepted', 'disposal_pending'], true)
                || ($latestEvent?->event_type === 'disposal_pending' && ! $sameAttempt)) {
                throw ValidationException::withMessages([
                    'evidence' => __('logistics::settings.evidence.disposal_not_allowed'),
                ]);
            }
            if ($latestEvent?->event_type !== 'disposal_pending') {
                EvidenceLifecycleEvent::query()->create([
                    'evidence_id' => $evidence->getKey(), 'company_id' => $evidence->company_id,
                    'actor_id'    => $actor->getKey(), 'approved_by' => $reviewer->getKey(),
                    'event_type'  => 'disposal_pending', 'reason' => $reason, 'created_at' => now(),
                ]);
            }
        });

        $integrityFailure = false;
        DB::transaction(function () use ($actor, $reviewer, $evidenceId, $reason, &$integrityFailure): void {
            $evidence = Evidence::query()->whereKey($evidenceId)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, (int) $evidence->company_id, 'dispose_logistics_evidence');
            $this->authorize($reviewer, (int) $evidence->company_id, 'approve_logistics_evidence_disposal');
            $latestEvent = $this->latestLifecycleEvent($evidence);
            $sameAttempt = $latestEvent?->reason === $reason
                && (int) $latestEvent?->actor_id === (int) $actor->getKey()
                && (int) $latestEvent?->approved_by === (int) $reviewer->getKey();
            if ($latestEvent?->event_type === 'disposed_per_retention_policy' && $sameAttempt) {
                return;
            }
            if ($this->hasActiveLegalHold($evidence) || now()->lt($evidence->retain_until)
                || $latestEvent?->event_type !== 'disposal_pending' || ! $sameAttempt) {
                throw ValidationException::withMessages([
                    'evidence' => __('logistics::settings.evidence.disposal_not_allowed'),
                ]);
            }

            $disk = Storage::disk('logistics-evidence');
            try {
                if (! $disk->exists($evidence->object_key)) {
                    throw new DomainException(__('logistics::settings.evidence.bytes_missing'));
                }
                $this->assertObjectIntegrity($evidence);
            } catch (DomainException|FilesystemException) {
                EvidenceLifecycleEvent::query()->create([
                    'evidence_id'     => $evidence->getKey(), 'company_id' => $evidence->company_id,
                    'actor_id'        => $actor->getKey(), 'approved_by' => $reviewer->getKey(),
                    'event_type'      => 'disposal_integrity_failed', 'reason' => $reason,
                    'deletion_result' => 'object_integrity_unverified', 'created_at' => now(),
                ]);
                $integrityFailure = true;

                return;
            }

            // Hold acceptance uses this same row lock. Do not release it during deletion.
            if (! $disk->delete($evidence->object_key) || $disk->exists($evidence->object_key)) {
                throw new DomainException(__('logistics::settings.evidence.disposal_pending'));
            }
            EvidenceLifecycleEvent::query()->create([
                'evidence_id'     => $evidence->getKey(), 'company_id' => $evidence->company_id,
                'actor_id'        => $actor->getKey(), 'approved_by' => $reviewer->getKey(),
                'event_type'      => 'disposed_per_retention_policy', 'reason' => $reason,
                'deletion_result' => 'storage_delete_confirmed', 'created_at' => now(),
            ]);
        });
        if ($integrityFailure) {
            // Commit the incident instead of rolling it back with the reported failure.
            throw new DomainException(__('logistics::settings.evidence.disposal_integrity_failed'));
        }
    }

    private function changeLegalHold(User $actor, int $evidenceId, string $permission, string $action, string $reason, ?int $relatedHoldId): EvidenceLegalHold
    {
        $reason = $this->validateReason($reason);

        return DB::transaction(function () use ($actor, $evidenceId, $permission, $action, $reason, $relatedHoldId): EvidenceLegalHold {
            $evidence = Evidence::query()->whereKey($evidenceId)->lockForUpdate()->firstOrFail();
            $actor = $this->authorize($actor, (int) $evidence->company_id, $permission);
            $activeHold = $this->activeLegalHold($evidence);

            if ($action === 'placed' && $this->latestLifecycleEvent($evidence)?->event_type === 'disposed_per_retention_policy') {
                throw ValidationException::withMessages([
                    'evidence' => __('logistics::settings.evidence.hold_after_disposal'),
                ]);
            }

            if (($action === 'placed' && $activeHold)
                || ($action === 'released' && (! $activeHold || (int) $activeHold->getKey() !== $relatedHoldId))) {
                throw ValidationException::withMessages([
                    'evidence' => __('logistics::settings.evidence.legal_hold_state_changed'),
                ]);
            }

            return EvidenceLegalHold::query()->create([
                'evidence_id'     => $evidence->getKey(),
                'company_id'      => $evidence->company_id,
                'actor_id'        => $actor->getKey(),
                'action'          => $action,
                'reason'          => $reason,
                'related_hold_id' => $relatedHoldId,
                'created_at'      => now(),
            ]);
        });
    }

    private function activeLegalHold(Evidence $evidence): ?EvidenceLegalHold
    {
        $latest = $evidence->legalHoldEvents()->reorder('id', 'desc')->first();

        return $latest?->action === 'placed' ? $latest : null;
    }

    private function hasActiveLegalHold(Evidence $evidence): bool
    {
        return $this->activeLegalHold($evidence) !== null;
    }

    private function latestLifecycleEvent(Evidence $evidence): ?EvidenceLifecycleEvent
    {
        return $evidence->lifecycleEvents()->reorder('id', 'desc')->first();
    }

    private function lockCompany(int $companyId): Company
    {
        return Company::query()
            ->withoutGlobalScope(AllowedCompanyScope::class)
            ->whereKey($companyId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function authorize(User $actor, int $companyId, string $permission): User
    {
        $this->ensureInstalled();
        $actor = User::query()->withoutGlobalScopes()->find($actor->getKey());

        if (! $actor || ! $actor->is_active || $actor->trashed()) {
            throw new AuthorizationException(__('logistics::settings.authentication_required'));
        }

        $permissions = $actor->getAllPermissions()->pluck('name');

        if (! $permissions->contains($permission)) {
            throw new AuthorizationException(__('logistics::settings.permission_denied'));
        }

        $allowed = $permissions->contains('bypass_company_scope')
            ? Company::query()->withoutGlobalScope(AllowedCompanyScope::class)->whereKey($companyId)->exists()
            : $actor->allowedCompanies()->withoutGlobalScope(AllowedCompanyScope::class)->whereKey($companyId)->exists();

        if (! $allowed) {
            throw new AuthorizationException(__('logistics::settings.company_denied'));
        }

        return $actor;
    }

    private function ensureInstalled(): void
    {
        if (! Plugin::query()->where('name', 'logistics')->where('is_installed', true)->exists()) {
            throw new DomainException(__('logistics::settings.not_installed'));
        }
    }

    private function detectAllowedMimeType(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        $mimeType = $path ? (new \finfo(FILEINFO_MIME_TYPE))->file($path) : false;

        if (! is_string($mimeType) || ! array_key_exists($mimeType, self::ALLOWED_MIME_TYPES)) {
            throw ValidationException::withMessages([
                'file' => __('logistics::settings.evidence.unsupported_file_type'),
            ]);
        }

        return $mimeType;
    }

    private function validatePurpose(string $purpose): string
    {
        if (strlen($purpose) > 120 || ! preg_match('/^[a-z][a-z0-9_]*$/', $purpose)) {
            throw ValidationException::withMessages([
                'purpose' => __('logistics::settings.evidence.invalid_purpose'),
            ]);
        }

        return $purpose;
    }

    private function validateReason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages([
                'reason' => __('logistics::settings.reason_required'),
            ]);
        }

        return $reason;
    }

    private function safeOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';

        return Str::limit(trim($name) ?: 'evidence', 255, '');
    }

    private function assertAccepted(Evidence $evidence): void
    {
        if ($this->latestLifecycleEvent($evidence)?->event_type !== 'accepted') {
            throw new DomainException(__('logistics::settings.evidence.no_longer_available'));
        }
    }

    private function assertObjectIntegrity(Evidence $evidence): void
    {
        $stream = Storage::disk('logistics-evidence')->readStream($evidence->object_key);

        if (! is_resource($stream)) {
            throw new DomainException(__('logistics::settings.evidence.bytes_missing'));
        }

        $hash = hash_init('sha256');
        hash_update_stream($hash, $stream);
        $actualHash = hash_final($hash);
        fclose($stream);

        if (! hash_equals($evidence->sha256, $actualHash)) {
            throw new DomainException(__('logistics::settings.evidence.integrity_failure'));
        }
    }
}

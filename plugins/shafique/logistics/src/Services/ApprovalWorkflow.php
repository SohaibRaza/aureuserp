<?php

namespace Shafique\Logistics\Services;

use DateTimeInterface;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Shafique\Logistics\Models\ApprovalEvent;
use Shafique\Logistics\Models\CompanyControl;
use Shafique\Logistics\Models\ScopedApproval;
use Webkul\PluginManager\Models\Plugin;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\AllowedCompanyScope;

class ApprovalWorkflow
{
    /** @param array<string, int|string|bool> $limits */
    public function approve(
        User $requester,
        User $approver,
        int $companyId,
        string $idempotencyKey,
        string $action,
        string $scopeType,
        int $scopeId,
        int $scopeRevision,
        array $limits,
        DateTimeInterface $validUntil,
        string $reason,
    ): ScopedApproval {
        if ($requester->getKey() === $approver->getKey()) {
            throw ValidationException::withMessages([
                'approver' => __('logistics::settings.approval.independent_review_required'),
            ]);
        }

        $action = $this->validateToken($action, 'action');
        $scopeType = $this->validateToken($scopeType, 'scope_type');
        $reason = $this->validateReason($reason);
        $limits = $this->validateLimits($limits);
        $validUntil = Carbon::instance($validUntil)->utc();

        if ($scopeId < 1 || $scopeRevision < 1 || $validUntil <= now()) {
            throw ValidationException::withMessages([
                'approval' => __('logistics::settings.approval.invalid_scope'),
            ]);
        }

        if ((int) Auth::id() !== (int) $approver->getKey()) {
            throw new AuthorizationException(__('logistics::settings.approval.authenticated_approver_required'));
        }

        $result = app(CommandIdentity::class)->run(
            $approver,
            $companyId,
            'approval.issue',
            $idempotencyKey,
            [
                'action'         => $action,
                'scope_type'     => $scopeType,
                'scope_id'       => $scopeId,
                'scope_revision' => $scopeRevision,
                'limits'         => $limits,
                'requester_id'   => $requester->getKey(),
                'valid_until'    => $validUntil->format(DATE_ATOM),
                'reason'         => $reason,
            ],
            'approve_logistics_approval',
            function () use ($requester, $approver, $companyId, $idempotencyKey, $action, $scopeType, $scopeId, $scopeRevision, $limits, $validUntil, $reason): array {
                $company = Company::query()
                    ->withoutGlobalScope(AllowedCompanyScope::class)
                    ->whereKey($companyId)
                    ->firstOrFail();
                $requester = $this->authorize($requester, $company, 'request_logistics_approval');
                $approver = $this->authorize($approver, $company, 'approve_logistics_approval');

                if ($requester->getKey() === $approver->getKey() || $validUntil <= now()) {
                    throw ValidationException::withMessages([
                        'approval' => __('logistics::settings.approval.invalid_scope'),
                    ]);
                }

                $control = CompanyControl::query()->where('company_id', $companyId)->lockForUpdate()->firstOrFail();
                $approvalId = DB::table('logistics_scoped_approvals')->insertGetId([
                    'company_id'              => $companyId,
                    'command_idempotency_key' => $idempotencyKey,
                    'action'                  => $action,
                    'scope_type'              => $scopeType,
                    'scope_id'                => $scopeId,
                    'scope_revision'          => $scopeRevision,
                    'limits'                  => json_encode($limits, JSON_THROW_ON_ERROR),
                    'requested_by'            => $requester->getKey(),
                    'approved_by'             => $approver->getKey(),
                    'reason'                  => $reason,
                    'control_revision'        => $control->revision,
                    'valid_until'             => $validUntil->format(DATE_ATOM),
                    'created_at'              => now()->format(DATE_ATOM),
                ]);
                $approval = ScopedApproval::query()->findOrFail($approvalId);
                ApprovalEvent::query()->create([
                    'approval_id' => $approval->getKey(),
                    'company_id'  => $companyId,
                    'actor_id'    => $approver->getKey(),
                    'event_type'  => 'approved',
                    'reason'      => $reason,
                    'created_at'  => now(),
                ]);

                return ['approval_id' => $approval->getKey()];
            },
        );

        return ScopedApproval::query()
            ->where('company_id', $companyId)
            ->findOrFail($result['approval_id']);
    }

    /** @param array<string, int|string|bool> $values */
    public function consume(
        User $actor,
        int $companyId,
        int $approvalId,
        string $action,
        string $scopeType,
        int $scopeId,
        int $scopeRevision,
        array $values,
        string $commandType,
        string $idempotencyKey,
    ): void {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(__('logistics::settings.command.transaction_required'));
        }

        $unlocked = ScopedApproval::query()->findOrFail($approvalId);
        if ((int) $unlocked->company_id !== $companyId) {
            throw ValidationException::withMessages([
                'approval' => __('logistics::settings.approval.scope_changed'),
            ]);
        }
        $controlRevision = app(OperationalControl::class)->authorizeNewActivity($actor, $companyId);
        $approval = ScopedApproval::query()->whereKey($approvalId)->lockForUpdate()->firstOrFail();
        $company = Company::query()
            ->withoutGlobalScope(AllowedCompanyScope::class)
            ->whereKey($approval->company_id)
            ->firstOrFail();
        $actor = $this->authorize($actor, $company, 'consume_logistics_approval');
        $latestEvent = ApprovalEvent::query()->where('approval_id', $approvalId)->latest('id')->first();

        if ($latestEvent?->event_type !== 'approved'
            || $approval->action !== $action
            || $approval->scope_type !== $scopeType
            || (int) $approval->scope_id !== $scopeId
            || (int) $approval->scope_revision !== $scopeRevision
            || (int) $approval->control_revision !== $controlRevision) {
            throw ValidationException::withMessages([
                'approval' => __('logistics::settings.approval.scope_changed'),
            ]);
        }

        if ($approval->valid_until->isPast()) {
            throw ValidationException::withMessages([
                'approval' => __('logistics::settings.approval.expired'),
            ]);
        }

        $this->assertWithinLimits($approval->limits, $values);

        ApprovalEvent::query()->create([
            'approval_id'             => $approval->getKey(),
            'company_id'              => $approval->company_id,
            'actor_id'                => $actor->getKey(),
            'event_type'              => 'consumed',
            'command_idempotency_key' => $idempotencyKey,
            'reason'                  => $commandType,
            'created_at'              => now(),
        ]);
    }

    public function revoke(User $actor, int $approvalId, string $reason): void
    {
        $reason = $this->validateReason($reason);

        DB::transaction(function () use ($actor, $approvalId, $reason): void {
            $unlocked = ScopedApproval::query()->findOrFail($approvalId);
            Company::query()
                ->withoutGlobalScope(AllowedCompanyScope::class)
                ->whereKey($unlocked->company_id)
                ->lockForUpdate()
                ->firstOrFail();
            $approval = ScopedApproval::query()->whereKey($approvalId)->lockForUpdate()->firstOrFail();
            $company = Company::query()->withoutGlobalScope(AllowedCompanyScope::class)->findOrFail($approval->company_id);
            $actor = $this->authorize($actor, $company, 'revoke_logistics_approval');
            $latestEvent = ApprovalEvent::query()->where('approval_id', $approvalId)->latest('id')->first();

            if ($latestEvent?->event_type !== 'approved') {
                throw ValidationException::withMessages([
                    'approval' => __('logistics::settings.approval.scope_changed'),
                ]);
            }

            ApprovalEvent::query()->create([
                'approval_id' => $approval->getKey(),
                'company_id'  => $approval->company_id,
                'actor_id'    => $actor->getKey(),
                'event_type'  => 'revoked',
                'reason'      => $reason,
                'created_at'  => now(),
            ]);
        });
    }

    /** @param array<string, int|string|bool> $limits
     * @return array<string, string|bool>
     */
    private function validateLimits(array $limits): array
    {
        foreach ($limits as $key => $value) {
            if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]{0,79}$/', $key)
                || (! is_bool($value) && ! is_string($value) && ! is_int($value))) {
                throw ValidationException::withMessages([
                    'limits' => __('logistics::settings.approval.invalid_scope'),
                ]);
            }

            if (! is_bool($value) && ! $this->isDecimal($value)) {
                throw ValidationException::withMessages([
                    'limits' => __('logistics::settings.approval.invalid_scope'),
                ]);
            }

            $limits[$key] = is_bool($value) ? $value : (string) $value;
        }

        ksort($limits);

        return $limits;
    }

    /** @param array<string, string|bool> $limits
     * @param  array<string, int|string|bool>  $values
     */
    private function assertWithinLimits(array $limits, array $values): void
    {
        if (array_keys($limits) !== array_keys($values)) {
            ksort($values);

            if (array_keys($limits) !== array_keys($values)) {
                throw ValidationException::withMessages([
                    'approval' => __('logistics::settings.approval.limit_exceeded'),
                ]);
            }
        }

        foreach ($limits as $key => $limit) {
            $value = $values[$key];

            if (is_bool($limit)) {
                $withinLimit = $value === $limit;
            } elseif (is_bool($value) || ! $this->isDecimal($value)) {
                $withinLimit = false;
            } else {
                $withinLimit = $this->compareDecimals((string) $value, $limit) <= 0;
            }

            if (! $withinLimit) {
                throw ValidationException::withMessages([
                    'approval' => __('logistics::settings.approval.limit_exceeded'),
                ]);
            }
        }
    }

    private function compareDecimals(string $left, string $right): int
    {
        [$leftWhole, $leftFraction] = array_pad(explode('.', $left, 2), 2, '');
        [$rightWhole, $rightFraction] = array_pad(explode('.', $right, 2), 2, '');
        $leftWhole = ltrim($leftWhole, '0') ?: '0';
        $rightWhole = ltrim($rightWhole, '0') ?: '0';
        $scale = max(strlen($leftFraction), strlen($rightFraction));
        $leftFraction = str_pad($leftFraction, $scale, '0');
        $rightFraction = str_pad($rightFraction, $scale, '0');

        if (strlen($leftWhole) !== strlen($rightWhole)) {
            return strlen($leftWhole) <=> strlen($rightWhole);
        }

        $wholeComparison = strcmp($leftWhole, $rightWhole);

        return $wholeComparison !== 0 ? $wholeComparison : strcmp($leftFraction, $rightFraction);
    }

    private function isDecimal(int|string $value): bool
    {
        return preg_match('/^\d+(?:\.\d+)?$/', (string) $value) === 1;
    }

    private function authorize(User $actor, Company $company, string $permission): User
    {
        if (! Plugin::query()->where('name', 'logistics')->where('is_installed', true)->exists()) {
            throw new DomainException(__('logistics::settings.not_installed'));
        }

        $actor = User::query()->withoutGlobalScopes()->find($actor->getKey());

        if (! $actor || ! $actor->is_active || $actor->trashed()) {
            throw new AuthorizationException(__('logistics::settings.authentication_required'));
        }

        $permissions = $actor->getAllPermissions()->pluck('name');

        if (! $permissions->contains($permission)) {
            throw new AuthorizationException(__('logistics::settings.permission_denied'));
        }

        $allowed = $permissions->contains('bypass_company_scope')
            || $actor->allowedCompanies()
                ->withoutGlobalScope(AllowedCompanyScope::class)
                ->whereKey($company->getKey())
                ->exists();

        if (! $allowed) {
            throw new AuthorizationException(__('logistics::settings.company_denied'));
        }

        return $actor;
    }

    private function validateToken(string $token, string $field): string
    {
        if ($token === '' || strlen($token) > 120 || ! preg_match('/^[a-z][a-z0-9_.-]*$/', $token)) {
            throw ValidationException::withMessages([
                $field => __('logistics::settings.approval.invalid_scope'),
            ]);
        }

        return $token;
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
}

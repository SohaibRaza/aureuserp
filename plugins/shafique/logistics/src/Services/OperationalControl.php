<?php

namespace Shafique\Logistics\Services;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;
use Shafique\Logistics\Models\CompanyControl;
use Shafique\Logistics\Models\ControlChange;
use Webkul\PluginManager\Models\Plugin;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\AllowedCompanyScope;

class OperationalControl
{
    private const VIEW_PERMISSION = 'view_any_logistics_module::setting';

    private const UPDATE_PERMISSION = 'update_logistics_module::setting';

    public function read(?User $actor, int $companyId): bool
    {
        $this->ensureInstalled();
        $this->authorize($actor, $companyId, self::VIEW_PERMISSION);

        return (bool) CompanyControl::query()
            ->where('company_id', $companyId)
            ->value('accept_new_activity');
    }

    public function change(?User $actor, int $companyId, bool $acceptNewActivity, string $reason): void
    {
        $this->ensureInstalled();
        $reason = $this->validateReason($reason);

        DB::transaction(function () use ($actor, $companyId, $acceptNewActivity, $reason): void {
            Company::query()
                ->withoutGlobalScope(AllowedCompanyScope::class)
                ->whereKey($companyId)
                ->lockForUpdate()
                ->firstOrFail();

            $actor = $this->authorize($actor, $companyId, self::VIEW_PERMISSION, self::UPDATE_PERMISSION);

            $control = CompanyControl::query()
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->first();
            $oldValue = (bool) $control?->accept_new_activity;

            if ($oldValue === $acceptNewActivity) {
                return;
            }

            $revision = ($control?->revision ?? 0) + 1;

            if ($control) {
                $control->update(['accept_new_activity' => $acceptNewActivity, 'revision' => $revision]);
            } else {
                CompanyControl::query()->create([
                    'company_id'          => $companyId,
                    'accept_new_activity' => $acceptNewActivity,
                    'revision'            => $revision,
                ]);
            }

            ControlChange::query()->create([
                'company_id' => $companyId,
                'actor_id'   => $actor->getKey(),
                'old_value'  => $oldValue,
                'new_value'  => $acceptNewActivity,
                'reason'     => $reason,
                'revision'   => $revision,
            ]);
        });
    }

    public function authorizeNewActivity(?User $actor, int $companyId): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(__('logistics::settings.transaction_required'));
        }

        $this->ensureInstalled();
        $company = Company::query()
            ->withoutGlobalScope(AllowedCompanyScope::class)
            ->whereKey($companyId)
            ->lockForUpdate()
            ->firstOrFail();
        $this->authorize($actor, $companyId);

        if (! $company->is_active) {
            throw new DomainException(__('logistics::settings.company_inactive'));
        }

        $control = CompanyControl::query()
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->first();

        if (! $control?->accept_new_activity) {
            throw new DomainException(__('logistics::settings.activity_suspended'));
        }

        return $control->revision;
    }

    private function ensureInstalled(): void
    {
        if (! Plugin::query()
            ->where('name', 'logistics')
            ->where('is_installed', true)
            ->exists()) {
            throw new DomainException(__('logistics::settings.not_installed'));
        }
    }

    private function authorize(?User $actor, int $companyId, string ...$permissions): User
    {
        if (! $actor) {
            throw new AuthorizationException(__('logistics::settings.authentication_required'));
        }

        $actor = User::query()->withoutGlobalScopes()->find($actor->getKey());

        if (! $actor || ! $actor->is_active || $actor->trashed()) {
            throw new AuthorizationException(__('logistics::settings.actor_inactive'));
        }

        foreach ($permissions as $permission) {
            Gate::forUser($actor)->authorize($permission);
        }

        $canAccessCompany = Gate::forUser($actor)->allows('bypass_company_scope')
            ? Company::query()->withoutGlobalScope(AllowedCompanyScope::class)->whereKey($companyId)->exists()
            : $actor->allowedCompanies()
                ->withoutGlobalScope(AllowedCompanyScope::class)
                ->whereKey($companyId)
                ->exists();

        if (! $canAccessCompany) {
            throw new AuthorizationException(__('logistics::settings.company_denied'));
        }

        return $actor;
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

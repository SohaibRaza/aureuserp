<?php

namespace Shafique\Logistics\Services;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
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
        $actor = $this->authorize($actor, $companyId, self::VIEW_PERMISSION, self::UPDATE_PERMISSION);
        $reason = $this->validateReason($reason);

        DB::transaction(function () use ($actor, $companyId, $acceptNewActivity, $reason): void {
            Company::query()
                ->withoutGlobalScope(AllowedCompanyScope::class)
                ->whereKey($companyId)
                ->lockForUpdate()
                ->firstOrFail();

            $control = CompanyControl::query()
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->first();
            $oldValue = (bool) $control?->accept_new_activity;

            if ($oldValue === $acceptNewActivity) {
                return;
            }

            if ($control) {
                $control->update(['accept_new_activity' => $acceptNewActivity]);
            } else {
                CompanyControl::query()->create([
                    'company_id'          => $companyId,
                    'accept_new_activity' => $acceptNewActivity,
                ]);
            }

            ControlChange::query()->create([
                'company_id' => $companyId,
                'actor_id'   => $actor->getKey(),
                'old_value'  => $oldValue,
                'new_value'  => $acceptNewActivity,
                'reason'     => $reason,
            ]);
        });
    }

    public function authorizeNewActivity(?User $actor, int $companyId): void
    {
        $this->ensureInstalled();
        $this->authorize($actor, $companyId, self::VIEW_PERMISSION);

        if (! CompanyControl::query()
            ->where('company_id', $companyId)
            ->where('accept_new_activity', true)
            ->exists()) {
            throw new DomainException('New Logistics activity is suspended for this Company.');
        }
    }

    private function ensureInstalled(): void
    {
        if (! Plugin::query()
            ->where('name', 'logistics')
            ->where('is_installed', true)
            ->exists()) {
            throw new DomainException('Logistics is not installed.');
        }
    }

    private function authorize(?User $actor, int $companyId, string ...$permissions): User
    {
        if (! $actor) {
            throw new AuthorizationException('Authentication is required.');
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
            throw new AuthorizationException('You are not authorized for this Company.');
        }

        return $actor;
    }

    private function validateReason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages([
                'reason' => 'A reason between 1 and 1000 characters is required.',
            ]);
        }

        return $reason;
    }
}

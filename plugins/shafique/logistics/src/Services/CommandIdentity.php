<?php

namespace Shafique\Logistics\Services;

use Closure;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Shafique\Logistics\Models\CommandRecord;
use Webkul\PluginManager\Models\Plugin;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\AllowedCompanyScope;

class CommandIdentity
{
    /**
     * @param  array<string, mixed>  $intent
     * @param  Closure(): array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    public function run(
        User $actor,
        int $companyId,
        string $commandType,
        string $idempotencyKey,
        array $intent,
        string $permission,
        Closure $operation,
        bool $requiresNewActivity = true,
    ): array {
        $commandType = $this->validateToken($commandType, 'command_type', 1);
        $idempotencyKey = $this->validateToken($idempotencyKey, 'idempotency_key', 16);

        if ($intent === []) {
            throw ValidationException::withMessages([
                'intent' => __('logistics::settings.command.intent_required'),
            ]);
        }

        $intentHash = hash('sha256', json_encode($this->canonicalize($intent), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return DB::transaction(function () use ($actor, $companyId, $commandType, $idempotencyKey, $intentHash, $permission, $operation, $requiresNewActivity): array {
            $company = Company::query()
                ->withoutGlobalScope(AllowedCompanyScope::class)
                ->whereKey($companyId)
                ->lockForUpdate()
                ->firstOrFail();

            $actor = $this->authorize($actor, $company, $permission);

            $existing = CommandRecord::query()
                ->where('company_id', $companyId)
                ->where('command_type', $commandType)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (! hash_equals($existing->intent_hash, $intentHash)) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => __('logistics::settings.command.identity_conflict'),
                    ]);
                }

                return $existing->result;
            }

            if (! $requiresNewActivity) {
                if (! $company->is_active) {
                    throw new DomainException(__('logistics::settings.company_inactive'));
                }
            }

            $controlRevision = $requiresNewActivity
                ? app(OperationalControl::class)->authorizeNewActivity($actor, $companyId)
                : null;
            $result = $operation();

            if (! is_array($result)) {
                throw new DomainException(__('logistics::settings.command.invalid_result'));
            }

            CommandRecord::query()->create([
                'company_id'        => $companyId,
                'actor_id'          => $actor->getKey(),
                'command_type'      => $commandType,
                'idempotency_key'   => $idempotencyKey,
                'intent_hash'       => $intentHash,
                'control_revision'  => $controlRevision,
                'result'            => $result,
                'created_at'        => now(),
            ]);

            return $result;
        });
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

    private function validateToken(string $token, string $field, int $minimumLength): string
    {
        if (strlen($token) < $minimumLength || strlen($token) > 128 || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $token)) {
            throw ValidationException::withMessages([
                $field => __('logistics::settings.command.invalid_identity'),
            ]);
        }

        return $token;
    }

    /** @param array<string, mixed> $value */
    private function canonicalize(array $value): array
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonicalize($item);
            }
        }

        unset($item);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}

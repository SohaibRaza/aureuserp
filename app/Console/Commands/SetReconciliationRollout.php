<?php

namespace App\Console\Commands;

use App\Models\Recon\ReconciliationRollout;
use App\Services\Recon\LegacyReconciliationAuditor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webkul\Support\Models\Company;

class SetReconciliationRollout extends Command
{
    protected $signature = 'reconciliation:rollout
        {company : Company ID}
        {--operator= : Person authorizing the operation}
        {--phase=p0 : Rollout phase: p0, p1, or p2}
        {--backup-confirmed : Confirm a recoverable backup exists}
        {--staging-verified : Confirm the manual staging proof passed}
        {--controller-signoff= : Controller who approved enablement}
        {--disable : Disable mutations without deleting evidence}
        {--reason= : Required reason when disabling}';

    protected $description = 'Enable or disable bank reconciliation mutations for one company';

    public function handle(LegacyReconciliationAuditor $auditor): int
    {
        $companyId = (int) $this->argument('company');
        $operator = trim((string) $this->option('operator'));
        if (! Company::query()->whereKey($companyId)->exists() || $operator === '') {
            $this->error('A valid company and --operator are required.');

            return self::INVALID;
        }
        if ($this->option('disable')) {
            return $this->disable($companyId, $operator);
        }

        $phase = strtolower((string) $this->option('phase'));
        $controller = trim((string) $this->option('controller-signoff'));
        if (! in_array($phase, ['p0', 'p1', 'p2'], true) || ! $this->option('backup-confirmed') || ! $this->option('staging-verified') || $controller === '') {
            $this->error('Enablement requires a valid phase, backup confirmation, staging verification, and controller sign-off.');

            return self::INVALID;
        }

        $findings = [...$auditor->findings($companyId), ...$auditor->configurationFindings($companyId)];
        if ($findings !== []) {
            $this->error('Enablement refused. Run reconciliation:audit-legacy and resolve every reported blocker.');

            return self::FAILURE;
        }
        $existing = ReconciliationRollout::query()->where('company_id', $companyId)->first();
        if ($existing?->enabled_at && ! $existing->disabled_at) {
            $this->info("Reconciliation mutations are already enabled for company {$companyId}.");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($companyId, $operator, $phase, $controller): void {
            ReconciliationRollout::query()->updateOrCreate(['company_id' => $companyId], [
                'phase'                    => $phase,
                'backup_confirmed_at'      => now(),
                'staging_verified_at'      => now(),
                'controller_signed_off_at' => now(),
                'controller_name'          => $controller,
                'enabled_at'               => now(),
                'enabled_by'               => $operator,
                'disabled_at'              => null,
                'disabled_by'              => null,
                'disable_reason'           => null,
            ]);
        });
        $this->info("Reconciliation mutations enabled for company {$companyId}.");

        return self::SUCCESS;
    }

    private function disable(int $companyId, string $operator): int
    {
        $reason = trim((string) $this->option('reason'));
        if ($reason === '') {
            $this->error('--reason is required when disabling a rollout.');

            return self::INVALID;
        }
        $rollout = ReconciliationRollout::query()->where('company_id', $companyId)->first();
        if (! $rollout) {
            $this->error('This company has no reconciliation rollout record.');

            return self::FAILURE;
        }
        if ($rollout->disabled_at) {
            $this->info("Reconciliation mutations are already disabled for company {$companyId}.");

            return self::SUCCESS;
        }
        $rollout->update(['disabled_at' => now(), 'disabled_by' => $operator, 'disable_reason' => $reason]);
        $this->info("Reconciliation mutations disabled for company {$companyId}; evidence remains intact.");

        return self::SUCCESS;
    }
}

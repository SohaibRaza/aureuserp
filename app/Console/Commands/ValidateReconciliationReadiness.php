<?php

namespace App\Console\Commands;

use App\Models\Recon\ReconciliationRollout;
use App\Services\Recon\LegacyReconciliationAuditor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Webkul\Support\Models\Company;

class ValidateReconciliationReadiness extends Command
{
    protected $signature = 'reconciliation:validate-readiness {company : Company ID} {--json : Emit machine-readable output}';

    protected $description = 'Validate reconciliation data, configuration, evidence, and rollout gates';

    public function handle(LegacyReconciliationAuditor $auditor): int
    {
        $companyId = (int) $this->argument('company');
        if (! Company::query()->whereKey($companyId)->exists()) {
            $this->error('Company does not exist.');

            return self::INVALID;
        }

        $findings = [...$auditor->findings($companyId), ...$auditor->configurationFindings($companyId)];
        if (! config('reconciliation.financial_mutations_enabled', false)) {
            $findings[] = ['code' => 'global_gate_disabled', 'count' => 1, 'message' => 'RECONCILIATION_FINANCIAL_MUTATIONS_ENABLED must be enabled before release.'];
        }
        if (! config('reconciliation.require_company_rollout', false)) {
            $findings[] = ['code' => 'company_gate_disabled', 'count' => 1, 'message' => 'RECONCILIATION_REQUIRE_COMPANY_ROLLOUT must be enabled before release.'];
        }

        $rollout = Schema::hasTable('reconciliation_rollouts')
            ? ReconciliationRollout::query()->where('company_id', $companyId)->first()
            : null;
        foreach ([
            'backup_confirmed_at'      => 'Backup confirmation is missing.',
            'staging_verified_at'      => 'Staging reconciliation proof is missing.',
            'controller_signed_off_at' => 'Controller sign-off is missing.',
        ] as $field => $message) {
            if (! $rollout?->{$field}) {
                $findings[] = ['code' => $field.'_missing', 'count' => 1, 'message' => $message];
            }
        }
        if (! $rollout?->enabled_at || $rollout->disabled_at) {
            $findings[] = ['code' => 'company_rollout_inactive', 'count' => 1, 'message' => 'Company reconciliation rollout is not active.'];
        }

        if ($this->option('json')) {
            $this->line(json_encode(['company_id' => $companyId, 'ready' => $findings === [], 'findings' => $findings], JSON_THROW_ON_ERROR));
        } elseif ($findings === []) {
            $this->info('Reconciliation rollout is ready for this company.');
        } else {
            $this->table(['Code', 'Count', 'Finding'], array_map(fn (array $finding): array => array_values($finding), $findings));
        }

        return $findings === [] ? self::SUCCESS : self::FAILURE;
    }
}

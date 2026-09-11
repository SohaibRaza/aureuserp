<?php

namespace App\Console\Commands;

use App\Services\Recon\LegacyReconciliationAuditor;
use Illuminate\Console\Command;
use Webkul\Support\Models\Company;

class AuditLegacyReconciliations extends Command
{
    protected $signature = 'reconciliation:audit-legacy {--company= : Limit the read-only audit to one company ID} {--json : Emit machine-readable output}';

    protected $description = 'Read-only audit of legacy and ambiguous bank reconciliation state';

    public function handle(LegacyReconciliationAuditor $auditor): int
    {
        $companyId = $this->option('company') === null ? null : (int) $this->option('company');
        if ($companyId !== null && ! Company::query()->whereKey($companyId)->exists()) {
            $this->error('Company does not exist.');

            return self::INVALID;
        }
        $findings = $auditor->findings($companyId);

        if ($this->option('json')) {
            $this->line(json_encode(['company_id' => $companyId, 'ready' => $findings === [], 'findings' => $findings], JSON_THROW_ON_ERROR));
        } elseif ($findings === []) {
            $this->info('No legacy reconciliation blockers found.');
        } else {
            $this->table(['Code', 'Count', 'Finding'], array_map(fn (array $finding): array => array_values($finding), $findings));
        }

        return $findings === [] ? self::SUCCESS : self::FAILURE;
    }
}

<?php

namespace App\Services\Recon;

use App\Models\Recon\BankReconciliation;
use App\Models\Recon\StatementLine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StatementLineTreatment
{
    private const REFERENCE_TYPES = [
        'matched'    => 'match',
        'adjustment' => 'adjustment',
        'bank_error' => 'reconciling_item',
        'duplicate'  => 'reconciling_item',
    ];

    public function __construct(private readonly ReconciliationMutationGuard $mutationGuard) {}

    public function lock(BankReconciliation $reconciliation, int $statementLineId): StatementLine
    {
        $line = StatementLine::query()
            ->whereKey($statementLineId)
            ->where('statement_id', $reconciliation->statement_id)
            ->where('company_id', $reconciliation->company_id)
            ->where('journal_id', $reconciliation->journal_id)
            ->where('currency_id', $reconciliation->currency_id)
            ->where('import_status', 'accepted')
            ->lockForUpdate()
            ->first();

        if (! $line) {
            throw new InvalidArgumentException('The statement line is not an accepted line in this reconciliation.');
        }

        return $line;
    }

    public function apply(
        BankReconciliation $reconciliation,
        StatementLine $line,
        string $treatment,
        string $referenceType,
        int $referenceId,
        ?string $reason = null,
        bool $settleResidual = false,
        ?int $moveId = null,
    ): void {
        DB::transaction(function () use ($reconciliation, $line, $treatment, $referenceType, $referenceId, $reason, $settleResidual, $moveId): void {
            $reconciliation = BankReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);
            $this->mutationGuard->assert($reconciliation);
            $line = $this->lock($reconciliation, $line->id);

            if (! array_key_exists($treatment, self::REFERENCE_TYPES)) {
                throw new InvalidArgumentException('Unsupported terminal treatment.');
            }
            if ($referenceType !== self::REFERENCE_TYPES[$treatment]) {
                throw new InvalidArgumentException('The terminal treatment reference type is invalid.');
            }
            if (in_array($treatment, ['bank_error', 'duplicate'], true) && blank($reason)) {
                throw new InvalidArgumentException('A reason is required for bank errors and exclusions.');
            }
            if ($treatment !== 'matched' && ! $settleResidual) {
                throw new InvalidArgumentException('A terminal treatment must settle the statement line residual.');
            }
            if ($line->terminal_treatment) {
                throw new InvalidArgumentException('This statement line already has a terminal treatment.');
            }
            if (! $this->referenceBelongsToReconciliation($reconciliation, $line, $treatment, $referenceType, $referenceId)) {
                throw new InvalidArgumentException('The terminal treatment source is outside this reconciliation.');
            }
            if ($treatment !== 'matched' && DB::table('recon_match_lines as match_line')
                ->join('recon_matches as recon_match', 'recon_match.id', '=', 'match_line.match_id')
                ->where('match_line.lineable_type', 'statement_line')
                ->where('match_line.lineable_id', $line->id)
                ->whereNull('match_line.reversed_at')
                ->whereIn('recon_match.status', ['proposed', 'confirmed'])
                ->whereNull('recon_match.reversed_at')
                ->exists()) {
                throw new InvalidArgumentException('A partially matched statement line cannot be adjusted or excluded. Unmatch it first.');
            }

            $line->update([
                'terminal_treatment'        => $treatment,
                'terminal_reference_type'   => $referenceType,
                'terminal_reference_id'     => $referenceId,
                'terminal_treatment_reason' => $reason,
                'terminal_treatment_at'     => now(),
                'terminal_treatment_by'     => Auth::id(),
                ...($settleResidual ? [
                    'amount_residual' => '0.0000',
                    'is_reconciled'   => true,
                    'move_id'         => $moveId,
                ] : []),
            ]);
        });
    }

    public function clear(BankReconciliation $reconciliation, StatementLine $line, string $referenceType, int $referenceId, string $restoredResidual): void
    {
        DB::transaction(function () use ($reconciliation, $line, $referenceType, $referenceId, $restoredResidual): void {
            $reconciliation = BankReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);
            $this->mutationGuard->assert($reconciliation);
            $line = $this->lock($reconciliation, $line->id);

            if ($line->terminal_reference_type !== $referenceType || (int) $line->terminal_reference_id !== $referenceId) {
                throw new InvalidArgumentException('The statement line treatment belongs to another source.');
            }

            $line->update([
                'terminal_treatment'        => null,
                'terminal_reference_type'   => null,
                'terminal_reference_id'     => null,
                'terminal_treatment_reason' => null,
                'terminal_treatment_at'     => null,
                'terminal_treatment_by'     => null,
                'amount_residual'           => $restoredResidual,
                'is_reconciled'             => false,
                'move_id'                   => null,
            ]);
        });
    }

    private function referenceBelongsToReconciliation(BankReconciliation $reconciliation, StatementLine $line, string $treatment, string $referenceType, int $referenceId): bool
    {
        return match ($referenceType) {
            'match' => DB::table('recon_matches as recon_match')
                ->join('recon_match_lines as match_line', 'match_line.match_id', '=', 'recon_match.id')
                ->where('recon_match.id', $referenceId)
                ->where('recon_match.reconciliation_id', $reconciliation->id)
                ->where('recon_match.company_id', $reconciliation->company_id)
                ->where('recon_match.status', 'confirmed')
                ->whereNull('recon_match.reversed_at')
                ->where('match_line.lineable_type', 'statement_line')
                ->where('match_line.lineable_id', $line->id)
                ->whereNull('match_line.reversed_at')
                ->exists(),
            'adjustment' => DB::table('recon_adjustments')
                ->where('id', $referenceId)
                ->where('reconciliation_id', $reconciliation->id)
                ->where('company_id', $reconciliation->company_id)
                ->where('statement_line_id', $line->id)
                ->whereNull('reversed_at')
                ->whereNotNull('posted_at')
                ->exists(),
            'reconciling_item' => DB::table('recon_reconciling_items')
                ->where('id', $referenceId)
                ->where('reconciliation_id', $reconciliation->id)
                ->where('company_id', $reconciliation->company_id)
                ->where('statement_line_id', $line->id)
                ->where('classification', $treatment)
                ->where('status', $treatment === 'duplicate' ? 'excluded' : 'resolved')
                ->exists(),
            default => false,
        };
    }
}

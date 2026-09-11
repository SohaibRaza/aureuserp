<?php

namespace App\Services\Recon;

use App\Models\Recon\BankReconciliation;
use App\Models\Recon\ReconMatchLine;
use App\Models\Recon\StatementLine as BankStatementLine;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Webkul\Account\Models\MoveLine;

class MatchingEngine
{
    public function __construct(
        private readonly ReconciliationSafety $safety,
        private readonly ReconciliationRuleEngine $rules,
        private readonly ReconciliationMutationGuard $mutationGuard,
    ) {}

    public function ledgerCandidates(BankReconciliation $reconciliation, ?int $ignoreMatchId = null): Builder
    {
        $cutoff = $this->cutoff($reconciliation);
        $allocated = DB::table('recon_match_lines as allocation')
            ->join('recon_matches as match', 'match.id', '=', 'allocation.match_id')
            ->whereColumn('allocation.lineable_id', 'line.id')
            ->where('allocation.lineable_type', ReconMatchLine::TYPE_MOVE_LINE)
            ->where('match.company_id', $reconciliation->company_id)
            ->whereIn('match.status', ['proposed', 'confirmed'])
            ->whereNull('allocation.reversed_at')
            ->whereNull('match.reversed_at')
            ->when($ignoreMatchId, fn ($query) => $query->where('match.id', '<>', $ignoreMatchId))
            ->selectRaw('COALESCE(SUM(ABS(allocation.amount_applied)), 0)');
        $allocatedSql = $allocated->toSql();
        $allocatedBindings = $allocated->getBindings();

        return MoveLine::query()
            ->withoutGlobalScopes()
            ->from('accounts_account_move_lines as line')
            ->join('accounts_account_moves as move', 'move.id', '=', 'line.move_id')
            ->where('move.state', 'posted')
            ->where('move.company_id', $reconciliation->company_id)
            ->where('line.company_id', $reconciliation->company_id)
            ->where('line.account_id', $reconciliation->liquidity_account_id)
            ->where('line.currency_id', $reconciliation->currency_id)
            ->whereDate('move.date', '<=', $cutoff)
            ->whereDate('line.date', '<=', $cutoff)
            ->where('line.reconciled', false)
            ->whereNull('line.statement_line_id')
            ->whereNotIn('line.move_id', function ($query) use ($reconciliation): void {
                $query->select('move_id')->from('recon_adjustments')
                    ->where('company_id', $reconciliation->company_id)
                    ->where('reconciliation_id', $reconciliation->id)
                    ->whereNull('reversed_at')
                    ->whereNotNull('move_id');
            })
            ->whereRaw('ABS(CASE WHEN line.currency_id <> line.company_currency_id THEN COALESCE(line.amount_residual_currency, line.amount_currency, line.amount_residual, line.balance) ELSE COALESCE(line.amount_residual, line.balance) END) - ('.$allocatedSql.') >= ?', [...$allocatedBindings, $this->currencySmallestUnit($reconciliation)])
            ->orderBy('line.date')
            ->select('line.*', 'move.name as move_name', 'move.reference as move_ref', 'move.payment_reference as move_payment_reference')
            ->selectRaw('ABS(CASE WHEN line.currency_id <> line.company_currency_id THEN COALESCE(line.amount_residual_currency, line.amount_currency, line.amount_residual, line.balance) ELSE COALESCE(line.amount_residual, line.balance) END) - ('.$allocatedSql.') as remaining_amount', $allocatedBindings);
    }

    /**
     * U13: Accounting-screen view of open liquidity candidates.
     *
     * Uses the exact same allocation-aware definition as ledgerCandidates so
     * a fully consumed ledger line disappears from both the reconciliation
     * workspace and the normal accounting open-items view. Partially consumed
     * lines remain visible with reduced remaining in both.
     */
    public function accountingOpenCandidates(BankReconciliation $reconciliation, ?int $ignoreMatchId = null): Builder
    {
        return $this->ledgerCandidates($reconciliation, $ignoreMatchId);
    }

    /**
     * U13: Pre-match remaining for a single ledger line, excluding one match.
     * Used to decide sole-consumer full linkage without racing allocations.
     */
    public function ledgerRemainingFor(BankReconciliation $reconciliation, int $ledgerLineId, ?int $ignoreMatchId = null): string
    {
        $allocated = (string) DB::table('recon_match_lines as allocation')
            ->join('recon_matches as match', 'match.id', '=', 'allocation.match_id')
            ->where('allocation.lineable_type', ReconMatchLine::TYPE_MOVE_LINE)
            ->where('allocation.lineable_id', $ledgerLineId)
            ->where('match.company_id', $reconciliation->company_id)
            ->whereIn('match.status', ['proposed', 'confirmed'])
            ->whereNull('allocation.reversed_at')
            ->whereNull('match.reversed_at')
            ->when($ignoreMatchId, fn ($query) => $query->where('match.id', '<>', $ignoreMatchId))
            ->sum(DB::raw('ABS(allocation.amount_applied)'));

        $line = MoveLine::query()->withoutGlobalScopes()->findOrFail($ledgerLineId);
        $grossSource = (int) $line->currency_id !== (int) $line->company_currency_id
            ? ($line->amount_residual_currency ?? $line->amount_currency ?? $line->amount_residual ?? $line->balance)
            : ($line->amount_residual ?? $line->balance ?? '0');
        $gross = $this->absolute((string) $grossSource);

        return bcsub($gross, bcadd($allocated, '0', 4), 4);
    }

    /**
     * Available unsigned bank-line amount after live proposal reservations.
     *
     * Bank statements and the corresponding bank GL liquidity lines use the
     * same economic direction: receipts are positive in both and payments are
     * negative in both. The allocation UI accepts magnitudes only.
     */
    public function bankRemainingFor(BankReconciliation $reconciliation, int $bankLineId, ?int $ignoreMatchId = null): string
    {
        $allocated = (string) DB::table('recon_match_lines as allocation')
            ->join('recon_matches as match', 'match.id', '=', 'allocation.match_id')
            ->where('allocation.lineable_type', ReconMatchLine::TYPE_STATEMENT_LINE)
            ->where('allocation.lineable_id', $bankLineId)
            ->where('match.company_id', $reconciliation->company_id)
            ->where('match.status', 'proposed')
            ->whereNull('allocation.reversed_at')
            ->whereNull('match.reversed_at')
            ->when($ignoreMatchId, fn ($query) => $query->where('match.id', '<>', $ignoreMatchId))
            ->sum(DB::raw('ABS(allocation.amount_applied)'));

        $line = BankStatementLine::query()
            ->where('statement_id', $reconciliation->statement_id)
            ->where('company_id', $reconciliation->company_id)
            ->where('journal_id', $reconciliation->journal_id)
            ->findOrFail($bankLineId);
        $gross = $this->absolute((string) ($line->amount_residual ?? $line->amount));

        return bcsub($gross, bcadd($allocated, '0', 4), 4);
    }

    public function scorePair(BankStatementLine $bankLine, $ledgerLine): array
    {
        $score = 0;
        $reasons = [];
        $bankAmount = (float) ($bankLine->amount_residual ?? $bankLine->amount);
        $ledgerSource = (int) ($ledgerLine->currency_id ?? 0) !== (int) ($ledgerLine->company_currency_id ?? 0)
            ? (string) ($ledgerLine->amount_residual_currency ?? $ledgerLine->amount_currency ?? $ledgerLine->balance)
            : (string) ($ledgerLine->amount_residual ?? $ledgerLine->balance ?? ($ledgerLine->debit - $ledgerLine->credit));
        $ledgerAmount = (float) $this->signedAllocation($ledgerSource, (string) ($ledgerLine->remaining_amount ?? $this->absolute($ledgerSource)));
        $precision = max(0, min(4, (int) ($ledgerLine->currency?->decimal_places ?? 2)));
        $tolerance = 0.5 * (10 ** (-$precision));
        if (abs($bankAmount - $ledgerAmount) <= $tolerance) {
            $score += 10;
            $reasons[] = 'amount-exact';
        } elseif (abs(abs($bankAmount) - abs($ledgerAmount)) <= $tolerance) {
            $score += 6;
            $reasons[] = 'amount-absolute';
        }

        $normRef = fn ($value): string => strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $value));
        $bankRef = $normRef($bankLine->payment_reference);
        $ledgerRef = $normRef($ledgerLine->move_payment_reference ?: $ledgerLine->move_ref ?: $ledgerLine->name);
        if ($bankRef !== '' && $bankRef === $ledgerRef) {
            $score += 8;
            $reasons[] = 'reference';
        }
        if ($bankLine->partner_id && $ledgerLine->partner_id && $bankLine->partner_id === (int) $ledgerLine->partner_id) {
            $score += 4;
            $reasons[] = 'partner';
        }

        $bankDate = $bankLine->transaction_date?->startOfDay();
        $ledgerDate = $ledgerLine->date ? Carbon::parse($ledgerLine->date) : null;
        if ($bankDate && $ledgerDate && abs($bankDate->diffInDays($ledgerDate)) <= 5) {
            $score += 2;
            $reasons[] = 'date-proximity';
        }

        return ['score' => $score, 'reasons' => $reasons];
    }

    public function autoMatch(BankReconciliation $reconciliation, bool $confirmStrong = true): array
    {
        $this->safety->assertFinancialMutationsEnabled($reconciliation->company_id);

        return DB::transaction(function () use ($reconciliation, $confirmStrong): array {
            $reconciliation = BankReconciliation::query()->lockForUpdate()->findOrFail($reconciliation->id);
            $this->mutationGuard->assert($reconciliation, lockedMessage: 'Reconciliation is locked. Reopen it before automatic matching.');
            $bankLines = $this->bankLines($reconciliation)->get();
            $candidates = $this->ledgerCandidates($reconciliation)->get();
            $stats = ['proposed' => 0, 'confirmed' => 0];
            $usedLedgerIds = [];

            foreach ($bankLines as $bankLine) {
                $best = null;
                $ambiguous = false;
                foreach ($candidates as $ledgerLine) {
                    if (in_array($ledgerLine->id, $usedLedgerIds, true)) {
                        continue;
                    }
                    $result = $this->scorePair($bankLine, $ledgerLine);
                    if ($result['score'] < 8) {
                        continue;
                    }
                    if ($best && $result['score'] === $best['score']) {
                        $ambiguous = true;
                    } elseif (! $best || $result['score'] > $best['score']) {
                        $best = ['line' => $ledgerLine, 'score' => $result['score'], 'reasons' => $result['reasons']];
                        $ambiguous = false;
                    }
                }

                if (! $best || $ambiguous) {
                    continue;
                }

                $rule = $this->rules->forLine($reconciliation, $bankLine);
                $threshold = $rule ? max(14, (float) $rule->approval_threshold) : 14;
                $status = ($confirmStrong && $best['score'] >= $threshold && ($rule?->action ?? 'auto_confirm') === 'auto_confirm') ? 'confirmed' : 'proposed';
                $allocations = [
                    'bank'   => [$bankLine->id => $this->absolute((string) $bankLine->remaining_amount)],
                    'ledger' => [$best['line']->id => $this->absolute((string) $best['line']->remaining_amount)],
                ];
                $notes = 'Auto: '.($best['reasons'][0] ?? 'eligible').($rule ? ' | rule '.$rule->name.' v'.$rule->version : '');

                if ($status === 'confirmed') {
                    app(MatchService::class)->createMatch($reconciliation, [$bankLine->id], [$best['line']->id], $notes, $allocations, $rule?->id);
                } else {
                    app(MatchService::class)->proposeMatch($reconciliation, [$bankLine->id], [$best['line']->id], $notes, $allocations, $rule?->id);
                }

                $usedLedgerIds[] = $best['line']->id;
                $stats[$status]++;
            }

            BankReconciliation::logEvent($reconciliation, 'auto_matched', $stats);

            return $stats;
        });
    }

    public function validateBasket(BankReconciliation $reconciliation, array $bankLineIds, array $ledgerLineIds, array $allocations = [], ?int $ignoreMatchId = null): array
    {
        $bankLineIds = array_map('intval', $bankLineIds);
        $ledgerLineIds = array_map('intval', $ledgerLineIds);
        if ($bankLineIds === [] || $ledgerLineIds === []) {
            throw new InvalidArgumentException('Select at least one bank line and one ledger line.');
        }
        $bankLines = $this->bankLines($reconciliation, $ignoreMatchId)->whereIn('line.id', $bankLineIds)->get();
        $ledgerLines = $this->ledgerCandidates($reconciliation, $ignoreMatchId)->whereIn('line.id', $ledgerLineIds)->get();
        if ($bankLines->count() !== count(array_unique($bankLineIds)) || $ledgerLines->count() !== count(array_unique($ledgerLineIds))) {
            throw new InvalidArgumentException('One or more selected lines are outside this reconciliation or already allocated.');
        }

        $bankSigns = $bankLines->map(fn ($line): int => $this->sign((string) ($line->amount_residual ?? $line->amount)))->unique();
        $ledgerSigns = $ledgerLines->map(fn ($line): int => $this->sign((string) ((int) $line->currency_id !== (int) $line->company_currency_id ? ($line->amount_currency ?? $line->balance) : ($line->balance ?? '0'))))->unique();
        if ($bankSigns->count() !== 1 || $ledgerSigns->count() !== 1 || $bankSigns->first() !== $ledgerSigns->first()) {
            throw new InvalidArgumentException('Mixed-direction or netted baskets are not supported; select bank and ledger activity in the same direction.');
        }

        $bankTotal = '0.0000';
        $ledgerTotal = '0.0000';
        $resolved = ['bank' => [], 'ledger' => []];
        foreach ($bankLines as $line) {
            $applied = $this->allocation($reconciliation, (string) ($line->amount_residual ?? $line->amount), $allocations['bank'][$line->id] ?? null, (string) $line->remaining_amount);
            $resolved['bank'][$line->id] = $applied;
            $bankTotal = bcadd($bankTotal, $applied, 4);
        }
        foreach ($ledgerLines as $line) {
            $source = (int) $line->currency_id !== (int) $line->company_currency_id
                ? (string) ($line->amount_currency ?? $line->balance)
                : (string) $line->balance;
            $applied = $this->allocation($reconciliation, $source, $allocations['ledger'][$line->id] ?? null, (string) $line->remaining_amount);
            $resolved['ledger'][$line->id] = $applied;
            $ledgerTotal = bcadd($ledgerTotal, $applied, 4);
        }
        $variance = bcsub($bankTotal, $ledgerTotal, 4);

        return [
            'bank_total'   => $bankTotal,
            'ledger_total' => $ledgerTotal,
            'variance'     => $variance,
            'balanced'     => $this->isZero($reconciliation, $variance),
            'allocations'  => $resolved,
        ];
    }

    private function bankLines(BankReconciliation $reconciliation, ?int $ignoreMatchId = null): Builder
    {
        $allocated = DB::table('recon_match_lines as allocation')
            ->join('recon_matches as match', 'match.id', '=', 'allocation.match_id')
            ->whereColumn('allocation.lineable_id', 'line.id')
            ->where('allocation.lineable_type', ReconMatchLine::TYPE_STATEMENT_LINE)
            ->where('match.company_id', $reconciliation->company_id)
            // Confirmed bank allocations are already reflected in
            // amount_residual. Only proposals reserve additional availability.
            ->where('match.status', 'proposed')
            ->whereNull('allocation.reversed_at')
            ->whereNull('match.reversed_at')
            ->when($ignoreMatchId, fn ($query) => $query->where('match.id', '<>', $ignoreMatchId))
            ->selectRaw('COALESCE(SUM(ABS(allocation.amount_applied)), 0)');
        $allocatedSql = $allocated->toSql();
        $allocatedBindings = $allocated->getBindings();

        return BankStatementLine::query()
            ->from('accounts_bank_statement_lines as line')
            ->where('line.statement_id', $reconciliation->statement_id)
            ->where('line.journal_id', $reconciliation->journal_id)
            ->where('line.company_id', $reconciliation->company_id)
            ->where('line.import_status', 'accepted')
            ->whereNull('line.terminal_treatment')
            ->where('line.is_reconciled', false)
            ->whereRaw('ABS(COALESCE(line.amount_residual, line.amount)) - ('.$allocatedSql.') >= ?', [...$allocatedBindings, $this->currencySmallestUnit($reconciliation)])
            ->orderBy('line.id')
            ->select('line.*')
            ->selectRaw('ABS(COALESCE(line.amount_residual, line.amount)) - ('.$allocatedSql.') as remaining_amount', $allocatedBindings);
    }

    private function allocation(BankReconciliation $reconciliation, string $sourceAmount, mixed $requested, ?string $remainingOverride = null): string
    {
        $available = $this->absolute($remainingOverride ?? $sourceAmount);
        $amount = $requested === null ? $available : (string) $requested;
        if (! preg_match('/^(?:0|[1-9]\d{0,17})(?:\.\d{1,4})?$/', $amount) || bccomp($amount, '0', 4) <= 0 || bccomp($amount, $available, 4) > 0) {
            throw new InvalidArgumentException('Allocation must be a positive decimal not exceeding the line remaining amount.');
        }

        $precision = $this->currencyPrecision($reconciliation);
        if (bccomp($amount, bcadd($amount, '0', $precision), 4) !== 0) {
            throw new InvalidArgumentException("Allocation exceeds the reconciliation currency precision of {$precision} decimal places.");
        }

        return str_starts_with($sourceAmount, '-') ? '-'.bcadd($amount, '0', 4) : bcadd($amount, '0', 4);
    }

    private function absolute(string $amount): string
    {
        return str_starts_with($amount, '-') ? substr($amount, 1) : $amount;
    }

    private function signedAllocation(string $sourceAmount, string $magnitude): string
    {
        $normalized = bcadd($magnitude, '0', 4);

        return str_starts_with($sourceAmount, '-') ? '-'.$normalized : $normalized;
    }

    private function cutoff(BankReconciliation $reconciliation): string
    {
        return ($reconciliation->statement_date ?? $reconciliation->period_end)?->toDateString() ?? now()->toDateString();
    }

    public function currencyPrecision(BankReconciliation $reconciliation): int
    {
        return max(0, min(4, (int) ($reconciliation->currency()->value('decimal_places') ?? 2)));
    }

    public function currencyTolerance(BankReconciliation $reconciliation): string
    {
        $precision = $this->currencyPrecision($reconciliation);

        return '0.'.str_repeat('0', $precision).'5';
    }

    public function currencySmallestUnit(BankReconciliation $reconciliation): string
    {
        $precision = $this->currencyPrecision($reconciliation);

        return $precision === 0 ? '1' : '0.'.str_repeat('0', $precision - 1).'1';
    }

    public function isZero(BankReconciliation $reconciliation, string $amount): bool
    {
        $precision = $this->currencyPrecision($reconciliation);
        if ($precision >= 4) {
            return bccomp($amount, '0', 4) === 0;
        }

        $tolerance = $this->currencyTolerance($reconciliation);
        $rounded = bcadd($amount, str_starts_with($amount, '-') ? '-'.$tolerance : $tolerance, $precision);

        return bccomp($rounded, '0', $precision) === 0;
    }

    private function sign(string $amount): int
    {
        return str_starts_with(trim($amount), '-') ? -1 : (bccomp($amount, '0', 4) === 0 ? 0 : 1);
    }
}

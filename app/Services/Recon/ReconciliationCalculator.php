<?php

namespace App\Services\Recon;

use App\Enums\Recon\ReconcilingItemClassification;
use App\Enums\Recon\ReconcilingItemSide;
use App\Models\Recon\BankReconciliation;
use App\Models\Recon\ReconcilingItem;
use App\Models\Recon\StatementLine;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReconciliationCalculator
{
    public function calculate(BankReconciliation $reconciliation): array
    {
        $accountId = $this->liquidityAccountId($reconciliation);
        $periodStart = $reconciliation->period_start?->toDateString();
        $cutoff = $reconciliation->statement_date?->toDateString() ?? $reconciliation->period_end?->toDateString();

        if (! $periodStart || ! $cutoff) {
            throw new RuntimeException('Reconciliation period and statement date must be established before calculation.');
        }

        $precision = max(0, min(4, (int) ($reconciliation->currency()->value('decimal_places') ?? 2)));
        $glBalanceStart = $this->glBalance($reconciliation, $accountId, '<', $periodStart);
        $glBalanceEnd = $this->glBalance($reconciliation, $accountId, '<=', $cutoff);
        $components = [
            'deposits_in_transit'  => ['amount' => '0.0000', 'item_ids' => []],
            'outstanding_payments' => ['amount' => '0.0000', 'item_ids' => []],
            'bank_errors'          => ['amount' => '0.0000', 'item_ids' => []],
        ];
        $blockingItemIds = [];

        $items = ReconcilingItem::query()
            ->where('company_id', $reconciliation->company_id)
            ->where('reconciliation_id', $reconciliation->id)
            ->get();

        foreach ($items as $item) {
            $component = null;

            if ($item->status->value === 'resolved'
                && $item->classification !== ReconcilingItemClassification::BankError) {
                if ($this->hasValidAdjustmentResolution($item, $reconciliation)) {
                    continue;
                }

                $blockingItemIds[] = $item->id;

                continue;
            }

            if (in_array($item->status->value, ['open', 'carried_forward'], true)) {
                $component = match ($item->classification) {
                    ReconcilingItemClassification::DepositInTransit   => 'deposits_in_transit',
                    ReconcilingItemClassification::OutstandingPayment => 'outstanding_payments',
                    ReconcilingItemClassification::BankError          => 'bank_errors',
                    default                                           => null,
                };
            } elseif ($item->classification === ReconcilingItemClassification::BankError && $item->status->value === 'resolved') {
                $component = 'bank_errors';
            } elseif ($item->classification === ReconcilingItemClassification::Duplicate && $item->status->value === 'excluded') {
                if ($item->side === ReconcilingItemSide::Bank && $this->hasValidSource($item, $reconciliation, $accountId, $cutoff)) {
                    continue;
                }
            }

            if (! $component || $item->side !== ReconcilingItemSide::Bank || ! $this->hasValidSource($item, $reconciliation, $accountId, $cutoff)) {
                $blockingItemIds[] = $item->id;

                continue;
            }

            $amount = $component === 'bank_errors' ? (string) $item->amount : $this->absolute((string) $item->amount);
            $components[$component]['amount'] = bcadd($components[$component]['amount'], $amount, 4);
            $components[$component]['item_ids'][] = $item->id;
        }

        $adjustedBank = bcadd((string) $reconciliation->balance_end_real, $components['deposits_in_transit']['amount'], 4);
        $adjustedBank = bcsub($adjustedBank, $components['outstanding_payments']['amount'], 4);
        $adjustedBank = bcadd($adjustedBank, $components['bank_errors']['amount'], 4);
        $adjustedBank = $this->round($adjustedBank, $precision);
        $adjustedBook = $this->round($glBalanceEnd, $precision);
        $difference = bcsub($adjustedBank, $adjustedBook, $precision);
        $postedAdjustments = (string) DB::table('recon_adjustments as adjustment')
            ->join('accounts_account_moves as move', 'move.id', '=', 'adjustment.move_id')
            ->where('adjustment.reconciliation_id', $reconciliation->id)
            ->where('adjustment.company_id', $reconciliation->company_id)
            ->whereNull('adjustment.reversed_at')
            ->where('move.state', 'posted')
            ->whereDate('move.date', '<=', $cutoff)
            ->sum('adjustment.amount');

        return [
            'precision'             => $precision,
            'gl_balance_start'      => $this->round($glBalanceStart, $precision),
            'gl_balance_end'        => $adjustedBook,
            'statement_balance_end' => $this->round((string) $reconciliation->balance_end_real, $precision),
            'deposits_in_transit'   => $this->round($components['deposits_in_transit']['amount'], $precision),
            'outstanding_payments'  => $this->round($components['outstanding_payments']['amount'], $precision),
            'bank_errors'           => $this->round($components['bank_errors']['amount'], $precision),
            'adjusted_bank_balance' => $adjustedBank,
            'adjusted_book_balance' => $adjustedBook,
            'expected_ledger_end'   => $adjustedBook,
            'adjustments_total'     => $this->round($postedAdjustments, $precision),
            'reconciled_bank'       => $this->reconciledBankTotal($reconciliation, $precision),
            'difference'            => $difference,
            'is_balanced'           => bccomp($difference, '0', $precision) === 0,
            'components'            => $components,
            'blocking_item_ids'     => $blockingItemIds,
        ];
    }

    public function liquidityAccountId(BankReconciliation $reconciliation): int
    {
        if (! $reconciliation->liquidity_account_id) {
            throw new RuntimeException('Reconciliation has no liquidity account configured.');
        }

        return (int) $reconciliation->liquidity_account_id;
    }

    private function glBalance(BankReconciliation $reconciliation, int $accountId, string $operator, string $date): string
    {
        $companyCurrencyId = (int) $reconciliation->company()->value('currency_id');

        $query = DB::table('accounts_account_move_lines as line')
            ->join('accounts_account_moves as move', 'move.id', '=', 'line.move_id')
            ->where('move.state', 'posted')
            ->where('move.company_id', $reconciliation->company_id)
            ->where('line.company_id', $reconciliation->company_id)
            ->where('line.account_id', $accountId)
            ->whereDate('move.date', $operator, $date)
            ->whereDate('line.date', $operator, $date);

        if ((int) $reconciliation->currency_id !== $companyCurrencyId) {
            $query->where('line.currency_id', $reconciliation->currency_id);
        }

        return (string) $query
            ->selectRaw('COALESCE(SUM(CASE WHEN line.currency_id <> line.company_currency_id THEN COALESCE(line.amount_currency, 0) ELSE line.balance END), 0) AS reconciliation_balance')
            ->value('reconciliation_balance');
    }

    private function hasValidSource(ReconcilingItem $item, BankReconciliation $reconciliation, int $accountId, string $cutoff): bool
    {
        if ($item->origin_period_end->toDateString() > $cutoff) {
            return false;
        }

        if (in_array($item->classification, [ReconcilingItemClassification::BankError, ReconcilingItemClassification::Duplicate], true)) {
            return $item->statement_line_id
                && StatementLine::query()
                    ->whereKey($item->statement_line_id)
                    ->where('statement_id', $reconciliation->statement_id)
                    ->where('company_id', $reconciliation->company_id)
                    ->where('journal_id', $reconciliation->journal_id)
                    ->where('import_status', 'accepted')
                    ->whereDate('transaction_date', '<=', $cutoff)
                    ->exists();
        }

        return $item->move_line_id
            && DB::table('accounts_account_move_lines as line')
                ->join('accounts_account_moves as move', 'move.id', '=', 'line.move_id')
                ->where('line.id', $item->move_line_id)
                ->where('line.company_id', $reconciliation->company_id)
                ->where('line.account_id', $accountId)
                ->where('move.company_id', $reconciliation->company_id)
                ->where('move.state', 'posted')
                ->whereDate('move.date', '<=', $cutoff)
                ->whereDate('line.date', '<=', $cutoff)
                ->exists();
    }

    private function hasValidAdjustmentResolution(ReconcilingItem $item, BankReconciliation $reconciliation): bool
    {
        $adjustmentId = $item->evidence['adjustment_id'] ?? null;

        return $adjustmentId
            && (int) $item->resolved_reconciliation_id === (int) $reconciliation->id
            && DB::table('recon_adjustments')
                ->where('id', $adjustmentId)
                ->where('reconciliation_id', $reconciliation->id)
                ->where('company_id', $reconciliation->company_id)
                ->where('statement_line_id', $item->statement_line_id)
                ->where('amount', (string) $item->amount)
                ->whereNotNull('posted_at')
                ->whereNull('reversed_at')
                ->exists();
    }

    private function reconciledBankTotal(BankReconciliation $reconciliation, int $precision): string
    {
        $total = (string) StatementLine::query()
            ->where('statement_id', $reconciliation->statement_id)
            ->where('company_id', $reconciliation->company_id)
            ->where('import_status', 'accepted')
            ->where('is_reconciled', true)
            ->sum('amount');

        return $this->round($total, $precision);
    }

    private function absolute(string $amount): string
    {
        return str_starts_with($amount, '-') ? substr($amount, 1) : $amount;
    }

    private function round(string $amount, int $precision): string
    {
        if ($precision >= 4) {
            return bcadd($amount, '0', 4);
        }

        $increment = '0.'.str_repeat('0', $precision).'5';

        return bcadd($amount, str_starts_with($amount, '-') ? '-'.$increment : $increment, $precision);
    }
}

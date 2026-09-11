<?php

namespace App\Services\Recon;

use App\Models\Recon\BankReconciliation;
use App\Models\Recon\ReconciliationRule;
use App\Models\Recon\StatementLine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Webkul\Account\Models\Journal;
use Webkul\Partner\Models\Partner;

class ReconciliationRuleEngine
{
    public function forLine(BankReconciliation $reconciliation, StatementLine $line): ?ReconciliationRule
    {
        $date = $line->transaction_date ?? $reconciliation->statement_date ?? $reconciliation->period_end;

        return ReconciliationRule::query()
            ->where('company_id', $reconciliation->company_id)
            ->where(function ($query) use ($reconciliation): void {
                $query->whereNull('journal_id')->orWhere('journal_id', $reconciliation->journal_id);
            })
            ->where('active', true)
            ->where(function ($query) use ($date): void {
                $query->whereNull('active_from')->orWhereDate('active_from', '<=', $date);
            })
            ->where(function ($query) use ($date): void {
                $query->whereNull('active_until')->orWhereDate('active_until', '>=', $date);
            })
            ->orderBy('priority')->orderBy('id')
            ->get()
            ->first(fn (ReconciliationRule $rule): bool => $this->matches($rule, $line));
    }

    public function createVersion(array $attributes): ReconciliationRule
    {
        $this->validate($attributes);

        return DB::transaction(function () use ($attributes): ReconciliationRule {
            $rules = ReconciliationRule::query()
                ->where('company_id', $attributes['company_id'])
                ->lockForUpdate()
                ->orderBy('priority')->orderBy('id')
                ->get();
            foreach ($rules as $index => $rule) {
                $rule->updateQuietly(['priority' => $index + 1]);
            }
            $previous = $rules->where('name', $attributes['name'])->sortByDesc('version')->first();
            $attributes['priority'] ??= $rules->count() + 1;
            $attributes['version'] = ($previous?->version ?? 0) + 1;
            $attributes['created_by'] ??= Auth::id();
            if ($previous) {
                $previous->update(['active' => false]);
            }

            return ReconciliationRule::create($attributes);
        });
    }

    private function validate(array $attributes): void
    {
        $validator = Validator::make($attributes, [
            'company_id'         => ['required', 'integer', 'exists:companies,id'],
            'name'               => ['required', 'string', 'max:255'],
            'journal_id'         => ['nullable', 'integer', 'exists:accounts_journals,id'],
            'conditions'         => ['nullable', 'array'],
            'action'             => ['required', 'in:propose,auto_confirm'],
            'approval_threshold' => ['required', 'numeric', 'min:14', 'max:100'],
            'priority'           => ['nullable', 'integer', 'min:1', 'max:2147483647'],
            'active'             => ['nullable', 'boolean'],
            'active_from'        => ['nullable', 'date'],
            'active_until'       => ['nullable', 'date', 'after_or_equal:active_from'],
        ]);
        $validator->after(function ($validator) use ($attributes): void {
            if (! empty($attributes['journal_id']) && ! Journal::query()->whereKey($attributes['journal_id'])->where(function ($query) use ($attributes): void {
                $query->whereNull('company_id')->orWhere('company_id', $attributes['company_id']);
            })->exists()) {
                $validator->errors()->add('journal_id', 'The selected bank journal does not belong to this company.');
            }
            $conditions = $attributes['conditions'] ?? [];
            $allowed = ['reference_contains', 'transaction_code', 'partner_id', 'amount_min', 'amount_max'];
            foreach ($conditions as $key => $value) {
                if (! in_array($key, $allowed, true) || ! is_scalar($value) || trim((string) $value) === '') {
                    $validator->errors()->add('conditions', 'Each condition must use a supported field and a non-empty value.');

                    continue;
                }
                if (in_array($key, ['amount_min', 'amount_max'], true) && ! preg_match('/^(?:0|[1-9]\d{0,17})(?:\.\d{1,4})?$/', (string) $value)) {
                    $validator->errors()->add('conditions', 'Amount conditions accept numbers only, with up to four decimal places.');
                }
                if ($key === 'partner_id' && ! ctype_digit((string) $value)) {
                    $validator->errors()->add('conditions', 'Customer or vendor must be selected using a valid ID.');
                }
                if ($key === 'partner_id' && ctype_digit((string) $value) && ! Partner::query()->whereKey($value)->where('company_id', $attributes['company_id'])->exists()) {
                    $validator->errors()->add('conditions', 'The selected customer or vendor does not belong to this company.');
                }
            }
            if (isset($conditions['amount_min'], $conditions['amount_max']) && (float) $conditions['amount_min'] > (float) $conditions['amount_max']) {
                $validator->errors()->add('conditions', 'The minimum amount cannot be greater than the maximum amount.');
            }
        });
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    private function matches(ReconciliationRule $rule, StatementLine $line): bool
    {
        foreach ($rule->conditions ?? [] as $key => $expected) {
            $actual = match ($key) {
                'reference_contains' => strtolower((string) ($line->payment_reference ?? $line->reference ?? '')),
                'transaction_code'   => (string) ($line->transaction_code ?? ''),
                'partner_id'         => (string) ($line->partner_id ?? ''),
                default              => null,
            };
            if ($key === 'reference_contains' && ! str_contains($actual, strtolower((string) $expected))) {
                return false;
            }
            if ($key === 'transaction_code' && $actual !== (string) $expected) {
                return false;
            }
            if ($key === 'partner_id' && $actual !== (string) $expected) {
                return false;
            }
            if ($key === 'amount_min' && (float) ($line->amount ?? 0) < (float) $expected) {
                return false;
            }
            if ($key === 'amount_max' && (float) ($line->amount ?? 0) > (float) $expected) {
                return false;
            }
            if (! in_array($key, ['reference_contains', 'transaction_code', 'partner_id', 'amount_min', 'amount_max'], true)) {
                return false;
            }
        }

        return true;
    }
}

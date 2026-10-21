<?php

namespace Webkul\Account\Services;

use Exception;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\DisplayType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Enums\PaymentState;
use Webkul\Account\Events\MoveCancelled;
use Webkul\Account\Events\MoveConfirmed;
use Webkul\Account\Events\MoveDrafted;
use Webkul\Account\Events\MoveReversed;
use Webkul\Account\Mail\Invoice\Actions\InvoiceEmail;
use Webkul\Account\Models\FullReconcile;
use Webkul\Account\Models\Move as AccountMove;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Models\PartialReconcile;
use Webkul\Account\Models\Partner;
use Webkul\Support\Services\EmailService;

class MoveWorkflow
{
    public function cancel(AccountMove $move): AccountMove
    {
        if (! $move->exists || $move->isDirty($move->getKeyName()) || $move->getConnection() !== (new AccountMove)->getConnection()) {
            throw ValidationException::withMessages(['state' => __('accounts::system.reconciliation.unavailable')]);
        }

        return $move->getConnection()->transaction(function () use ($move): AccountMove {
            $reconciler = app(Reconciler::class);
            $lines = $reconciler->lockReconciliationLines([], [$move->getKey()]);
            $move = $move->newQuery()->findOrFail($move->getKey());
            if ($move->state !== MoveState::DRAFT) {
                throw ValidationException::withMessages(['state' => __('accounts::system.move.cancel-draft-only')]);
            }
            $reconciler->assertNoBankLinkage($lines->where('move_id', $move->getKey()));
            app(AccountingDateGuard::class)->assertReconciliationPeriodOpen($move);
            $move->state = MoveState::CANCEL;
            $move->save();
            $move = app(MoveCalculator::class)->recompute($move);
            $move->refresh();
            $this->stampLineStates($move, MoveState::CANCEL);
            MoveCancelled::dispatch($move);

            return $move;
        });
    }

    public function post(AccountMove $move): AccountMove
    {
        $dirty = $move->getDirty();
        $original = $move->getRawOriginal();
        $input = $move->getAttributes();

        if ($move->isDirty([$move->getKeyName(), 'reversed_entry_id'])) {
            throw new Exception(__('accounts::system.move.posting-identity-changed'));
        }

        try {
            return $move->getConnection()->transaction(function () use ($move, $dirty): AccountMove {
                $snapshot = $move->newQuery()->whereKey($move->getKey())->firstOrFail();
                $moveIds = collect([$snapshot->reversed_entry_id, $move->getKey()])->filter()->unique()->sort();

                app(Reconciler::class)->lockReconciliationLines([], $moveIds->values()->all());
                $stored = $move->newQuery()->whereKey($move->getKey())->firstOrFail();

                if ((int) $stored->reversed_entry_id !== (int) $snapshot->reversed_entry_id) {
                    throw new Exception(__('accounts::system.move.posting-identity-changed'));
                }

                if ($stored->state !== MoveState::DRAFT) {
                    throw ValidationException::withMessages(['state' => __('accounts::account-manager.post-action-validate.draft-state-required')]);
                }

                $move->setRawAttributes($stored->getAttributes(), true);
                $move->setRawAttributes(array_replace($move->getAttributes(), $dirty));
                $move->unsetRelations();

                app(AccountingDateGuard::class)->assertReconciliationPeriodOpen($move);
                $this->assertPostable($move);

                $move->state = MoveState::POSTED;
                $move->posted_before = true;
                $move->save();

                $move = app(MoveCalculator::class)->recompute($move);
                $move->refresh();
                $this->stampLineStates($move, MoveState::POSTED);
                $move->refresh();

                if ($move->reversedEntry?->state == MoveState::POSTED) {
                    $this->reconcileAgainstReversedEntry($move);
                }

                $this->bumpPartnerRanks($move);
                MoveConfirmed::dispatch($move);

                return $move;
            });
        } catch (Throwable $error) {
            // Rollback does not restore Eloquent attributes; keep a failed caller retryable.
            $move->setRawAttributes($original, true);
            $move->setRawAttributes($input);
            $move->unsetRelations();

            throw $error;
        }
    }

    public function markChecked(AccountMove $move): AccountMove
    {
        $move->checked = true;

        $move->save();

        return app(MoveCalculator::class)->recompute($move);
    }

    public function resetToDraft(AccountMove $move): AccountMove
    {
        if (! $move->exists || $move->isDirty($move->getKeyName()) || $move->getConnection() !== (new AccountMove)->getConnection()) {
            throw ValidationException::withMessages(['state' => __('accounts::system.reconciliation.unavailable')]);
        }

        return $move->getConnection()->transaction(function () use ($move): AccountMove {
            $reconciler = app(Reconciler::class);
            $lines = $reconciler->lockReconciliationLines([], [$move->getKey()]);
            $move = $move->newQuery()->findOrFail($move->getKey());
            app(AccountingDateGuard::class)->assertReconciliationPeriodOpen($move);
            if (! in_array($move->state, [MoveState::CANCEL, MoveState::POSTED], true)) {
                throw ValidationException::withMessages(['state' => __('accounts::system.move.reset-posted-cancelled-only')]);
            }
            $reconciler->assertNoBankLinkage($lines->where('move_id', $move->getKey()));
            $this->assertNotExchangeDifference($move);
            $lineIds = $move->lines()->pluck('id');
            $partials = PartialReconcile::where(function ($query) use ($lineIds): void {
                $query->whereIn('debit_move_id', $lineIds)->orWhereIn('credit_move_id', $lineIds);
            })->orderBy('id')->get();
            $partials->each(fn (PartialReconcile $partial) => $reconciler->unReconcile($partial));
            $move->refresh();
            $move->state = MoveState::DRAFT;
            $move->payment_state = PaymentState::NOT_PAID;
            $move->save();
            $move = app(MoveCalculator::class)->recompute($move);
            $move->save();
            $move->refresh();
            $this->stampLineStates($move, MoveState::DRAFT);
            MoveDrafted::dispatch($move);

            return $move;
        });
    }

    public function reverse(Collection $moves, array $defaultValuesPerMove = [], bool $cancel = false): Collection
    {
        return DB::transaction(function () use ($moves, $defaultValuesPerMove, $cancel): Collection {
            $moves = $moves->values();
            $reconciler = app(Reconciler::class);
            $lockedLines = $reconciler->lockReconciliationLines([], $moves->pluck('id')->all());
            $reconciler->assertNoBankLinkage($lockedLines->whereIn('move_id', $moves->pluck('id')->all()));
            foreach ($moves->sortBy('id') as $move) {
                $move->refresh();
                if ($move->state !== MoveState::POSTED) {
                    throw new Exception(__('accounts::system.payment-reversal.posted-only'));
                }
                if ($cancel && $move->matchedPayments()->exists()) {
                    throw new Exception(__('accounts::system.payment-reversal.paid-cancel'));
                }
            }

            $moves->values()->each(fn (AccountMove $move, int $index) => app(AccountingDateGuard::class)->assertReconciliationPeriodOpen(
                $move,
                $defaultValuesPerMove[$index]['date'] ?? $move->date,
            ));

            if ($cancel) {
                $lineIds = $moves->flatMap(fn (AccountMove $move) => $move->lines->pluck('id'));
                PartialReconcile::where(function ($query) use ($lineIds): void {
                    $query->whereIn('debit_move_id', $lineIds)->orWhereIn('credit_move_id', $lineIds);
                })->orderBy('id')->get()->each(fn (PartialReconcile $partial) => $reconciler->unReconcile($partial));
            }

            $reversals = collect();

            foreach ($moves->values() as $index => $move) {
                $reversals->push($this->buildReversal($move, $defaultValuesPerMove[$index] ?? []));
            }

            foreach ($reversals as $reversal) {
                foreach ($reversal->lines as $line) {
                    if ($reversal->move_type === MoveType::ENTRY || $line->display_type === DisplayType::COGS) {
                        $line->update([
                            'balance'         => -$line->balance,
                            'amount_currency' => -$line->amount_currency,
                        ]);
                    }
                }
            }

            if ($cancel) {
                $reversals->each(fn (AccountMove $reversal) => $this->post($reversal));
            }

            $moves->each(fn (AccountMove $move) => MoveReversed::dispatch($move));

            return $reversals;
        });
    }

    protected function buildReversal(AccountMove $move, array $defaultValues): AccountMove
    {
        $reversal = $move->replicate();

        $reversal->fill(array_merge($defaultValues, [
            'move_type'         => $move->typeReverseMapping[$move->move_type->value],
            'reversed_entry_id' => $move->id,
            'partner_id'        => $move->partner_id,
        ]));

        $reversal->state = MoveState::DRAFT;

        $reversal->posted_before = false;

        $reversal->name = null;

        $reversal->save();

        foreach ($move->lines as $line) {
            $copy = $line->replicate();

            $copy->move_id = $reversal->id;

            $copy->save();
        }

        return app(MoveCalculator::class)->recompute($reversal);
    }

    public function sendByEmail(AccountMove $move, array $data): AccountMove
    {
        $partners = Partner::whereIn('id', $data['partners'])->get();

        $view = 'accounts::mail/invoice/actions/invoice';

        $attachments = array_map(fn ($file) => ['path' => $file, 'name' => basename($file)], $data['files']);

        $lastPartner = null;

        foreach ($partners as $partner) {
            if (! $partner->email) {
                continue;
            }

            $lastPartner = $partner;

            app(EmailService::class)->send(
                mailClass: InvoiceEmail::class,
                view: $view,
                payload: $this->buildEmailPayload($move, $partner, $data),
                attachments: $attachments,
            );
        }

        $move->addMessage([
            'from' => ['company' => current_company()->toArray()],
            'body' => view($view, ['payload' => $this->buildEmailPayload($move, $lastPartner, $data)])->render(),
            'type' => 'comment',
        ], Auth::user()->id);

        Notification::make()
            ->success()
            ->title(__('accounts::filament/resources/invoice/actions/print-and-send.modal.notification.invoice-sent.title'))
            ->body(__('accounts::filament/resources/invoice/actions/print-and-send.modal.notification.invoice-sent.body'))
            ->send();

        return $move;
    }

    protected function buildEmailPayload(AccountMove $move, $partner, array $data): array
    {
        return [
            'record_name' => $move->name,
            'model_name'  => class_basename($move),
            'subject'     => $data['subject'],
            'description' => $data['description'],
            'to'          => [
                'address' => $partner?->email,
                'name'    => $partner?->name,
            ],
        ];
    }

    public function assertPostable(AccountMove $move): void
    {
        if (! $move->partner_id) {
            if ($move->isSaleDocument(true)) {
                throw ValidationException::withMessages(['partner_id' => __('accounts::account-manager.post-action-validate.customer-required')]);
            }

            if ($move->isPurchaseDocument(true)) {
                throw ValidationException::withMessages(['partner_id' => __('accounts::account-manager.post-action-validate.vendor-required')]);
            }
        }

        if ($move->partnerBank?->trashed()) {
            throw ValidationException::withMessages(['partner_bank_id' => __('accounts::account-manager.post-action-validate.bank-archived')]);
        }

        if (! $move->currency) {
            throw ValidationException::withMessages(['currency_id' => __('accounts::account-manager.post-action-validate.currency-archived')]);
        }

        if (float_compare($move->amount_total, 0, precisionRounding: $move->currency->rounding) < 0) {
            throw ValidationException::withMessages(['amount_total' => __('accounts::account-manager.post-action-validate.negative-amount')]);
        }

        if (! $move->invoice_date) {
            if ($move->isSaleDocument(true)) {
                $move->invoice_date = now();
            } elseif ($move->isPurchaseDocument(true)) {
                throw ValidationException::withMessages(['invoice_date' => __('accounts::account-manager.post-action-validate.date-required')]);
            }
        }

        if (in_array($move->state, [MoveState::POSTED, MoveState::CANCEL])) {
            throw ValidationException::withMessages(['state' => __('accounts::account-manager.post-action-validate.draft-state-required')]);
        }

        if ($move->lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => __('accounts::account-manager.post-action-validate.lines-required')]);
        }

        if ($move->lines->some(fn (MoveLine $line) => $line->account && $line->account->deprecated)) {
            throw ValidationException::withMessages(['lines' => __('accounts::account-manager.post-action-validate.account-deprecated')]);
        }

        if (! $move->journal) {
            throw ValidationException::withMessages(['journal_id' => __('accounts::account-manager.post-action-validate.journal-archived')]);
        }
    }

    protected function assertNotExchangeDifference(AccountMove $move): void
    {
        $partialIds = PartialReconcile::where('exchange_move_id', $move->id)->pluck('id')->unique();

        $fullIds = FullReconcile::where('exchange_move_id', $move->id)->pluck('id')->unique();

        if ($partialIds->merge($fullIds)->isNotEmpty()) {
            throw ValidationException::withMessages(['state' => __('accounts::system.move.reset-exchange-difference')]);
        }
    }

    protected function stampLineStates(AccountMove $move, MoveState $state): void
    {
        foreach ($move->lines as $line) {
            $line->update(['parent_state' => $state]);
        }
    }

    protected function reconcileAgainstReversedEntry(AccountMove $move): void
    {
        $reconciler = app(Reconciler::class);

        $reconciler->reconcileReversals(collect([$move->reversedEntry]), collect([$move]));

        $provisionalNumbers = $move->lines
            ->filter(fn (MoveLine $line) => ! empty($line->matching_number) && str_starts_with($line->matching_number, 'I'))
            ->pluck('matching_number')
            ->unique()
            ->values();

        if ($provisionalNumbers->isEmpty()) {
            return;
        }

        $grouped = MoveLine::with(['move', 'account'])
            ->whereIn('matching_number', $provisionalNumbers)
            ->get()
            ->groupBy(fn (MoveLine $line) => $line->matching_number.':'.$line->account_id);

        foreach ($grouped as $lines) {
            if (! $lines->every(fn (MoveLine $line) => $line->move->state === MoveState::POSTED)) {
                continue;
            }

            $account = $lines->first()->account;

            if (! $account->reconcile) {
                $account->update(['reconcile' => true]);
            }

            $reconciler->reconcile($lines);
        }
    }

    protected function bumpPartnerRanks(AccountMove $move): void
    {
        if ($move->isSaleDocument()) {
            $move->partner?->update(['customer_rank' => DB::raw('COALESCE(customer_rank, 0) + 1')]);

            return;
        }

        if ($move->isPurchaseDocument()) {
            $move->partner?->update(['supplier_rank' => DB::raw('COALESCE(supplier_rank, 0) + 1')]);

            return;
        }

        if ($move->move_type != MoveType::ENTRY) {
            return;
        }

        foreach ([
            [AccountType::ASSET_RECEIVABLE, 'customer_rank'],
            [AccountType::LIABILITY_PAYABLE, 'supplier_rank'],
        ] as [$accountType, $column]) {
            $move->lines
                ->filter(fn (MoveLine $line) => $line->partner_id && $line->account->account_type == $accountType)
                ->pluck('partner')
                ->unique()
                ->each(fn ($partner) => $partner?->update([$column => DB::raw("COALESCE({$column}, 0) + 1")]));
        }
    }
}

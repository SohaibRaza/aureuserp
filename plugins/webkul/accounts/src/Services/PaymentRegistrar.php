<?php

namespace Webkul\Account\Services;

use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\PaymentState;
use Webkul\Account\Enums\PaymentStatus;
use Webkul\Account\Enums\PaymentType;
use Webkul\Account\Events\MovePaid;
use Webkul\Account\Models\Move as AccountMove;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Models\Payment;
use Webkul\Account\Models\PaymentRegister;
use Webkul\Support\Services\CompanyContext;

class PaymentRegistrar
{
    public function register(PaymentRegister $register): array
    {
        $connection = (new Payment)->getConnection();

        if ($register->getConnection() !== $connection
            || $register->lines->contains(fn ($line) => $line->getConnection() !== $connection || $line->move->getConnection() !== $connection)) {
            throw new InvalidArgumentException(__('accounts::system.payment-registration.connection-mismatch'));
        }

        if (! $register->exists) {
            throw new InvalidArgumentException(__('accounts::system.payment-registration.unsaved-instruction'));
        }

        return $connection->transaction(function () use ($register, $connection): array {
            $stored = $connection->table($register->getTable())->where('id', $register->id)->lockForUpdate()->first();
            if (! $stored) {
                throw new InvalidArgumentException(__('accounts::system.payment-registration.missing-instruction'));
            }
            Gate::authorize('create', Payment::class);
            if (! in_array((int) $stored->company_id, app(CompanyContext::class)->activeIds(), true)) {
                throw new AuthorizationException(__('accounts::system.payment-registration.instruction-company'));
            }
            $lineIds = $register->lines()->orderBy('accounts_account_move_lines.id')->pluck('accounts_account_move_lines.id')->all();
            $fingerprint = $this->registrationFingerprint((array) $stored, $lineIds);
            if ($this->registrationFingerprint($register->getAttributes(), $lineIds) !== $fingerprint) {
                throw new InvalidArgumentException(__('accounts::system.payment-registration.changed-instruction'));
            }
            if ($stored->registration_state === 'completed') {
                if ($stored->registration_fingerprint !== $fingerprint) {
                    throw new InvalidArgumentException(__('accounts::system.payment-registration.completed-conflict'));
                }

                return json_decode($stored->registered_payment_ids, true, flags: JSON_THROW_ON_ERROR);
            }
            if ($stored->registration_state !== 'pending') {
                throw new InvalidArgumentException(__('accounts::system.payment-registration.unverified-instruction'));
            }
            if ($lineIds === []) {
                throw new InvalidArgumentException(__('accounts::system.payment-registration.missing-source-lines'));
            }
            $moveIds = MoveLine::query()->whereKey($lineIds)->pluck('move_id')->unique()->sort()->values()->all();
            $context = app(Reconciler::class)->lockReconciliationLines($lineIds);
            $lines = $context->whereIn('id', $lineIds)->values();
            $moves = $lines->pluck('move')->unique('id')->values();
            if ($lines->count() !== count($lineIds) || $moves->count() !== count($moveIds)
                || $moves->contains(fn (AccountMove $move): bool => $move->state !== MoveState::POSTED || (int) $move->company_id !== (int) $stored->company_id)
                || $lines->contains(fn (MoveLine $line): bool => (int) $line->company_id !== (int) $stored->company_id
                    || $line->reconciled || $line->parent_state !== MoveState::POSTED
                    || ! in_array($line->account->account_type, [AccountType::ASSET_RECEIVABLE, AccountType::LIABILITY_PAYABLE], true))) {
                throw new InvalidArgumentException(__('accounts::system.payment-registration.ineligible-sources'));
            }
            $register = new PaymentRegister;
            $register->setRawAttributes((array) $stored, true);
            $register->exists = true;
            $register->setRelation('lines', $lines);
            $register->computeBatches();
            $register->computeFromLines();
            $register->computeWriteoffIsExchangeAccount();
            $register->computeShowRequirePartnerBank();
            $remaining = $register->getTotalAmountsToPay($register->batches)['full_amount'];
            if (! is_finite((float) $register->amount) || (float) $register->amount <= 0
                || $register->currency->compareAmounts($register->amount, $remaining) > 0) {
                throw new InvalidArgumentException(__('accounts::system.payment-registration.invalid-amount'));
            }
            if ((int) $register->journal->company_id !== (int) $stored->company_id
                || (int) $register->paymentMethodLine->journal_id !== (int) $register->journal_id) {
                throw new InvalidArgumentException(__('accounts::system.payment-registration.invalid-journal-method'));
            }
            $batches = $this->payableBatches($register);

            $singleBatch = $batches[0];

            $editMode = $register->is_single_batch
                && (count($singleBatch['lines']) == 1 || $register->group_payment);

            if (! $editMode && $register->currency->compareAmounts($register->amount, $remaining) !== 0) {
                throw new InvalidArgumentException(__('accounts::system.payment-registration.invalid-batch-amount'));
            }

            $pending = $editMode
                ? [$this->singleBatchJob($register, $singleBatch)]
                : $this->perBatchJobs($register, $batches);

            if (collect($pending)->contains(fn (array $job): bool => (int) $job['create_vals']['currency_id'] !== (int) $register->currency_id)) {
                throw new InvalidArgumentException(__('accounts::system.payment-registration.mixed-batch-currencies'));
            }
            $plannedAmount = array_sum(array_column(array_column($pending, 'create_vals'), 'amount'));
            if ($register->currency->compareAmounts($plannedAmount, $register->amount) !== 0) {
                throw new InvalidArgumentException(__('accounts::system.payment-registration.invalid-batch-amount'));
            }

            $this->createPayments($register, $pending, $editMode);

            $this->postPayments($pending);

            $this->reconcilePayments($pending);

            $paymentIds = collect($pending)->pluck('payment.id')->map(fn ($id): int => (int) $id)->all();
            $connection->table($register->getTable())->where('id', $register->id)->update([
                'registration_state'       => 'completed',
                'registration_fingerprint' => $fingerprint,
                'registered_payment_ids'   => json_encode($paymentIds, JSON_THROW_ON_ERROR),
                'registered_at'            => now(),
            ]);

            $moves = $register->lines->map(fn ($line) => $line->move)->unique('id');

            $connection->afterCommit(function () use ($moves): void {
                foreach ($moves as $move) {
                    try {
                        MovePaid::dispatch($move->refresh());
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }
            });

            return $paymentIds;
        });
    }

    private function registrationFingerprint(array $attributes, array $lineIds): string
    {
        $intent = [];
        foreach (['company_id', 'partner_id', 'currency_id', 'journal_id', 'partner_bank_id', 'payment_method_line_id',
            'writeoff_account_id', 'payment_type', 'partner_type', 'payment_difference_handling', 'writeoff_label',
            'payment_date', 'amount', 'communication', 'installments_mode', 'group_payment', 'payment_token_id'] as $field) {
            $value = $attributes[$field] ?? null;
            if ($field === 'amount' && $value !== null && is_numeric($value)) {
                $value = bcadd((string) $value, '0', 4);
            } elseif ($field === 'group_payment' && $value !== null) {
                $value = (int) (bool) $value;
            }
            $intent[$field] = $value === null ? null : (string) $value;
        }
        $intent['lines'] = array_map('intval', $lineIds);
        sort($intent['lines']);

        return hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR));
    }

    public function replayRequest(AccountMove $move, ?string $key, array $intent): ?array
    {
        if ($key === null) {
            return null;
        }
        $this->validateRequestKey($key);
        Gate::authorize('create', Payment::class);
        if (! in_array((int) $move->company_id, app(CompanyContext::class)->activeIds(), true)) {
            throw new AuthorizationException(__('accounts::system.payment-registration.request-company'));
        }
        $register = PaymentRegister::query()->where('company_id', $move->company_id)->where('request_key', $key)->first();
        if (! $register) {
            return null;
        }
        if ($register->request_fingerprint !== $this->requestFingerprint($move, $intent)) {
            throw new InvalidArgumentException(__('accounts::system.payment-registration.request-key-conflict'));
        }

        return $this->register($register);
    }

    public function registerForMove(AccountMove $move, array $paymentData, array $lineIds, ?string $key, array $intent): array
    {
        $connection = (new PaymentRegister)->getConnection();

        return $connection->transaction(function () use ($connection, $move, $paymentData, $lineIds, $key, $intent): array {
            Gate::authorize('create', Payment::class);
            if (! in_array((int) $move->company_id, app(CompanyContext::class)->activeIds(), true)) {
                throw new AuthorizationException(__('accounts::system.payment-registration.request-company'));
            }
            $lineIds = array_values(array_unique(array_map('intval', $lineIds)));
            if ($lineIds === [] || MoveLine::query()->whereKey($lineIds)->where('move_id', $move->id)->where('company_id', $move->company_id)->count() !== count($lineIds)) {
                throw new InvalidArgumentException(__('accounts::system.payment-registration.invalid-request-lines'));
            }
            if ($key !== null) {
                $this->validateRequestKey($key);
                $connection->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['accounts.payment:'.$move->company_id.':'.$key]);
                $saved = $connection->table('accounts_payment_registers')->where('company_id', $move->company_id)->where('request_key', $key)->first();
                if ($saved && $this->registrationFingerprint([...((array) $saved), ...$paymentData], $lineIds) !== $saved->registration_fingerprint) {
                    throw new InvalidArgumentException(__('accounts::system.payment-registration.request-argument-conflict'));
                }
                $existing = $this->replayRequest($move, $key, $intent);
                if ($existing !== null) {
                    return $existing;
                }
            }
            $register = PaymentRegister::create([...$paymentData, 'company_id' => $move->company_id]);
            $register->lines()->sync($lineIds);
            $register->refresh();
            $register->computeFromLines();
            $register->save();
            if ($key !== null) {
                $register->forceFill([
                    'request_key'         => $key,
                    'request_fingerprint' => $this->requestFingerprint($move, $intent),
                ])->save();
            }

            return $this->register($register);
        });
    }

    private function validateRequestKey(string $key): void
    {
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/', $key)) {
            throw new InvalidArgumentException(__('accounts::system.payment-registration.invalid-request-key'));
        }
    }

    private function requestFingerprint(AccountMove $move, array $intent): string
    {
        unset($intent['idempotency_key']);
        ksort($intent);

        return hash('sha256', json_encode(['move_id' => $move->id, 'intent' => $intent], JSON_THROW_ON_ERROR));
    }

    protected function payableBatches(PaymentRegister $register): array
    {
        $batches = collect($register->batches)
            ->filter(function ($batch) use ($register) {
                $account = $register->getBatchAccount($batch);

                return ! $register->require_partner_bank_account || ($account && $account->can_send_money);
            })
            ->values()
            ->all();

        if ($batches === []) {
            throw new Exception(
                'To record payments with '.$register->paymentMethodLine->name.', the recipient bank account must be manually validated. You should go on the partner bank account in order to validate it.'
            );
        }

        return $batches;
    }

    protected function singleBatchJob(PaymentRegister $register, array $batch): array
    {
        $job = [
            'create_vals'  => $this->buildPaymentAttributesForSingleBatch($register, $batch),
            'to_reconcile' => $batch['lines'],
            'batch'        => $batch,
        ];

        if ($register->writeoff_is_exchange_account && $register->currency_id == $register->company_currency_id) {
            $residual = $batch['lines']->sum('amount_residual_currency');

            $job['rate'] = $register->amount ? abs($residual / $register->amount) : 0.0;
        }

        return $job;
    }

    protected function perBatchJobs(PaymentRegister $register, array $batches): array
    {
        if (! $register->group_payment) {
            $linesToPay = in_array($register->installments_mode, ['next', 'overdue', 'before_date'])
                ? $register->getTotalAmountsToPay($batches)['lines']
                : $register->lines;

            $batches = collect($batches)
                ->flatMap(fn ($batch) => collect($batch['lines'])
                    ->filter(fn ($line) => $linesToPay->contains($line))
                    ->map(fn ($line) => array_merge($batch, [
                        'payment_values' => array_merge($batch['payment_values'], [
                            'payment_type' => $line->balance > 0 ? PaymentType::RECEIVE : PaymentType::SEND,
                        ]),
                        'lines' => collect([$line]),
                    ])))
                ->all();
        }

        return array_map(fn ($batch) => [
            'create_vals'  => $this->buildPaymentAttributesForBatch($register, $batch),
            'to_reconcile' => $batch['lines'],
            'batch'        => $batch,
        ], $batches);
    }

    public function buildPaymentAttributesForSingleBatch(PaymentRegister $register, array $batch): array
    {
        $attributes = [
            'date'                   => $register->payment_date,
            'amount'                 => $register->amount,
            'payment_type'           => $register->payment_type,
            'partner_type'           => $register->partner_type,
            'memo'                   => $register->communication,
            'journal_id'             => $register->journal_id,
            'company_id'             => $register->company_id,
            'currency_id'            => $register->currency_id,
            'partner_id'             => $register->partner_id,
            'partner_bank_id'        => $register->partner_bank_id,
            'payment_method_line_id' => $register->payment_method_line_id,
            'destination_account_id' => $register->lines[0]->account_id,
            'write_off_line_vals'    => [],
        ];

        $handlesDifference = $register->payment_difference_handling == 'reconcile'
            && ! $register->currency->isZero($register->payment_difference);

        if (! $handlesDifference) {
            return $attributes;
        }

        if ($register->writeoff_is_exchange_account) {
            if ($register->currency_id != $register->company_currency_id) {
                $attributes['force_balance'] = $batch['lines']->sum('amount_residual');
            }

            return $attributes;
        }

        $writeOffAmountCurrency = $register->payment_type == PaymentType::RECEIVE
            ? $register->payment_difference
            : -$register->payment_difference;

        $attributes['write_off_line_vals'][] = [
            'name'            => 'Write Off',
            'account_id'      => $register->writeoff_account_id,
            'partner_id'      => $register->partner_id,
            'currency_id'     => $register->currency_id,
            'amount_currency' => $writeOffAmountCurrency,
            'balance'         => $register->currency->convert(
                $writeOffAmountCurrency,
                $register->company->currency,
                $register->company,
                $register->payment_date
            ),
        ];

        return $attributes;
    }

    public function buildPaymentAttributesForBatch(PaymentRegister $register, array $batch): array
    {
        $values = $register->getValuesFromBatch($batch);

        $partnerBankId = $values['payment_type'] == PaymentType::RECEIVE
            ? $register->journal->bank_account_id
            : data_get($batch, 'payment_values.partner_bank_id');

        $paymentMethodLine = $values['payment_type'] != $register->paymentMethodLine->payment_type
            ? $register->journal->getAvailablePaymentMethodLines($values['payment_type'])->first()
            : $register->paymentMethodLine;

        $attributes = [
            'date'                   => $register->payment_date,
            'amount'                 => $values['source_amount_currency'],
            'payment_type'           => $values['payment_type'],
            'partner_type'           => $values['partner_type'],
            'memo'                   => $register->getCommunication($batch['lines']),
            'journal_id'             => $register->journal_id,
            'company_id'             => $register->company_id,
            'currency_id'            => $values['source_currency_id'],
            'partner_id'             => $values['partner_id'],
            'payment_method_line_id' => $paymentMethodLine?->id,
            'destination_account_id' => $batch['lines'][0]->account_id,
            'write_off_line_vals'    => [],
        ];

        return array_filter($attributes + ['partner_bank_id' => $partnerBankId], fn ($value) => $value !== null);
    }

    public function createPayments(PaymentRegister $register, array &$jobs, bool $editMode = false): void
    {
        $accountingInstalled = (new AccountMove)->getInvoiceInPaymentState() == PaymentState::IN_PAYMENT;

        foreach ($jobs as $index => $job) {
            $attributes = $job['create_vals'];

            $writeOffLines = data_get($attributes, 'write_off_line_vals');

            $forceBalance = data_get($attributes, 'force_balance');

            $lines = data_get($attributes, 'lines');

            $attributes = Arr::except($attributes, ['write_off_line_vals', 'force_balance', 'lines']);

            $payment = Payment::create($attributes);

            if (! $accountingInstalled && ! $payment->outstanding_account_id) {
                $payment->update([
                    'outstanding_account_id' => $payment->getOutstandingAccount($payment->payment_type)->id,
                ]);
            }

            if ($writeOffLines !== null || $forceBalance !== null || $lines !== null) {
                $payment->generateJournalEntry($writeOffLines, $forceBalance, $lines);

                $payment->refresh();

                $moveAttributes = Arr::only($attributes, $payment->moveRelatedFields);

                if (filled($moveAttributes)) {
                    $payment->move->update($moveAttributes);
                }
            }

            $jobs[$index]['payment'] = $payment;

            if ($editMode && $payment->move_id) {
                $this->alignCurrencyRounding($register, $payment, $job['to_reconcile']);
            }
        }
    }

    protected function alignCurrencyRounding(PaymentRegister $register, Payment $payment, $lines): void
    {
        if ($payment->currency_id == $lines->first()->currency_id) {
            return;
        }

        [$liquidityLines, $counterpartLines] = $payment->seekForLines();

        $sourceBalance = abs($lines->sum('amount_residual'));

        $paymentRate = $liquidityLines[0]->balance
            ? $liquidityLines[0]->amount_currency / $liquidityLines[0]->balance
            : 0.0;

        $paymentAmountCurrency = abs($counterpartLines->sum('amount_currency'));

        if (! $payment->currency->isZero(abs($sourceBalance) * $paymentRate - $paymentAmountCurrency)) {
            return;
        }

        $deltaBalance = $sourceBalance - abs($counterpartLines->sum('balance'));

        if ($register->companyCurrency->isZero($deltaBalance)) {
            return;
        }

        $merged = $liquidityLines->merge($counterpartLines);

        $debitLines = $merged->filter(fn ($line) => $line->debit > 0);

        $creditLines = $merged->filter(fn ($line) => $line->credit > 0);

        if ($debitLines->isEmpty() || $creditLines->isEmpty()) {
            return;
        }

        $debitLines[0]->update(['debit' => $debitLines[0]->debit + $deltaBalance]);

        $creditLines[0]->update(['credit' => $creditLines[0]->credit + $deltaBalance]);
    }

    public function postPayments(array $jobs): void
    {
        foreach ($jobs as $job) {
            $this->postPayment($job['payment']);

            app(MoveWorkflow::class)->post($job['payment']->move);
        }
    }

    public function postPayment(Payment $payment): Payment
    {
        $stored = $payment->getConnection()->transaction(function () use ($payment): Payment {
            $stored = $this->lockedPayment($payment);
            if (! in_array($stored->state, [PaymentStatus::DRAFT, PaymentStatus::IN_PROCESS, PaymentStatus::PAID], true)) {
                throw ValidationException::withMessages(['state' => __('accounts::system.payment.post-draft-process-paid-only')]);
            }
            if (in_array($stored->paymentMethodLine->code, $stored->getMethodCodesNeedingBankAccount(), true) && ! $stored->partnerBank?->can_send_money) {
                throw ValidationException::withMessages(['partner_bank_id' => __('accounts::system.payment.bank-validation', [
                    'method'  => $stored->paymentMethodLine->name,
                    'partner' => $stored->partner->display_name,
                ])]);
            }

            if ($stored->outstandingAccount->account_type === AccountType::ASSET_CASH) {
                $stored->state = PaymentStatus::PAID;
                $stored->save();
            }

            if (in_array($stored->state, [PaymentStatus::DRAFT, PaymentStatus::IN_PROCESS], true)) {
                $stored->state = PaymentStatus::IN_PROCESS;
                $stored->save();
            }

            return $stored->refresh();
        });

        $payment->setRawAttributes($stored->getAttributes(), true);
        $payment->unsetRelations();

        return $payment;
    }

    public function cancelPayment(Payment $payment): Payment
    {
        return $payment->getConnection()->transaction(function () use ($payment): Payment {
            $stored = $this->lockedPayment($payment);
            Gate::authorize('update', $stored);
            if ($stored->state !== PaymentStatus::DRAFT) {
                throw ValidationException::withMessages(['state' => __('accounts::system.payment.cancel-draft-only')]);
            }
            if ($stored->move) {
                if (! in_array($stored->move->state, [MoveState::DRAFT, MoveState::CANCEL], true)) {
                    throw ValidationException::withMessages(['state' => __('accounts::system.move.cancel-draft-only')]);
                }
                if ($stored->move->state === MoveState::DRAFT) {
                    app(MoveWorkflow::class)->cancel($stored->move);
                }
            }
            $stored->unsetRelations();
            $stored->state = PaymentStatus::CANCELED;
            $stored->save();

            return $stored->refresh();
        });
    }

    public function resetToDraftPayment(Payment $payment): Payment
    {
        return $payment->getConnection()->transaction(function () use ($payment): Payment {
            $stored = $this->lockedPayment($payment);
            Gate::authorize('update', $stored);
            if ($stored->state === PaymentStatus::DRAFT) {
                throw ValidationException::withMessages(['state' => __('accounts::system.payment.reset-non-draft-only')]);
            }
            $stored->state = PaymentStatus::DRAFT;
            $stored->save();
            if ($stored->move) {
                app(MoveWorkflow::class)->resetToDraft($stored->move);
            }
            $stored->refresh();
            $stored->save();

            return $stored->refresh();
        });
    }

    private function lockedPayment(Payment $payment): Payment
    {
        if (! $payment->exists || $payment->isDirty($payment->getKeyName()) || $payment->getConnection() !== (new Payment)->getConnection()) {
            throw ValidationException::withMessages(['state' => __('accounts::system.payment.unavailable')]);
        }
        $snapshot = $payment->newQuery()->withoutGlobalScopes()->find($payment->getKey());
        if (! $snapshot) {
            throw ValidationException::withMessages(['state' => __('accounts::system.payment.unavailable')]);
        }
        if (! in_array((int) $snapshot->company_id, app(CompanyContext::class)->activeIds(), true)) {
            throw new AuthorizationException(__('accounts::system.payment.company'));
        }
        // Reconciliation owns journal roots before it updates related payments.
        if ($snapshot->move_id) {
            app(Reconciler::class)->lockReconciliationLines([], [(int) $snapshot->move_id]);
        }
        $stored = $payment->newQuery()->withoutGlobalScopes()->lockForUpdate()->find($payment->getKey());
        if (! $stored || (int) $stored->move_id !== (int) $snapshot->move_id || (int) $stored->company_id !== (int) $snapshot->company_id) {
            throw ValidationException::withMessages(['state' => __('accounts::system.payment.changed')]);
        }
        if ($stored->move_id && (! $stored->move || (int) $stored->move->company_id !== (int) $stored->company_id
            || (int) $stored->move->origin_payment_id !== (int) $stored->id)) {
            throw ValidationException::withMessages(['state' => __('accounts::system.payment.journal-mismatch')]);
        }

        return $stored;
    }

    public function reconcilePayments(array $jobs): void
    {
        $reconciler = app(Reconciler::class);

        foreach ($jobs as $job) {
            $payment = $job['payment']->refresh();

            $openLines = $payment->move->lines->filter(
                fn ($line) => $line->parent_state == MoveState::POSTED
                    && in_array($line->account->account_type, [AccountType::ASSET_RECEIVABLE, AccountType::LIABILITY_PAYABLE])
                    && ! $line->reconciled
            );

            foreach ($openLines->pluck('account_id')->unique() as $accountId) {
                $reconciler->reconcile(
                    $openLines->merge($job['to_reconcile'])->filter(
                        fn ($line) => $line->account_id == $accountId
                            && ! $line->reconciled
                            && $line->parent_state == MoveState::POSTED
                    )
                );
            }

            foreach ($job['to_reconcile'] as $line) {
                $line->move->matchedPayments()->attach($payment->id);
            }

            AccountMove::whereIn('id', $job['to_reconcile']->pluck('move_id')->unique())
                ->get()
                ->each(function (AccountMove $move) {
                    $move->refresh();

                    $move->computePaymentState();

                    $move->save();

                    $move->matchedPayments()
                        ->get()
                        ->each(fn (Payment $matchedPayment) => $matchedPayment->save());
                });
        }
    }
}

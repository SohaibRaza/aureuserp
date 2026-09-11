<?php

require_once __DIR__.'/ReconTestSupport.php';

use App\Models\Recon\BankReconciliation;
use App\Models\Recon\StatementLine;
use App\Services\Recon\AdjustmentService;
use App\Services\Recon\ReconciliationCalculator;
use Illuminate\Support\Facades\DB;
use Webkul\Account\Enums\PaymentStatus;
use Webkul\Account\Enums\PaymentType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\BankStatement;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Models\Payment;
use Webkul\Account\Models\PaymentMethod;
use Webkul\Account\Models\PaymentMethodLine;
use Webkul\Account\Services\MoveWorkflow;
use Webkul\Partner\Models\Partner;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;
use Webkul\Support\Models\CurrencyRate;

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('accounts');
    $this->company = Company::query()->firstOrFail();
    $this->actingAs(User::query()->firstOrFail());
    config()->set('reconciliation.financial_mutations_enabled', true);
    config()->set('reconciliation.require_company_rollout', false);
});

function adjustmentRecon(array $overrides = []): BankReconciliation
{
    return BankReconciliation::query()->create([
        ...validBankReconciliationAttributes(),
        'status'           => 'in_progress',
        'period_start'     => '2026-09-01',
        'period_end'       => '2026-09-30',
        'statement_date'   => '2026-09-30',
        'balance_start'    => '0.0000',
        'balance_end_real' => '0.0000',
        ...$overrides,
    ]);
}

function foreignAdjustmentRecon(Currency $currency): BankReconciliation
{
    $baseJournal = testBankJournal();
    $baseJournal->bankAccount->update(['currency_id' => $currency->id]);
    $baseJournal->defaultAccount->update(['currency_id' => $currency->id]);
    $journal = $baseJournal->replicate();
    $journal->forceFill([
        'name'        => 'Foreign bank '.fake()->unique()->numerify('####'),
        'code'        => fake()->unique()->bothify('FX###'),
        'sort'        => fake()->unique()->bothify('FX###'),
        'currency_id' => $currency->id,
    ])->save();

    return BankReconciliation::query()->create([
        ...validBankReconciliationAttributes($journal),
        'currency_id'      => $currency->id,
        'status'           => 'in_progress',
        'period_start'     => '2026-09-01',
        'period_end'       => '2026-09-30',
        'statement_date'   => '2026-09-30',
        'balance_start'    => '0.0000',
        'balance_end_real' => '0.0000',
    ]);
}

function adjustmentLine(BankReconciliation $reconciliation, string $amount, string $key): StatementLine
{
    $statement = $reconciliation->statement_id
        ? BankStatement::query()->findOrFail($reconciliation->statement_id)
        : BankStatement::query()->create([
            'company_id'       => $reconciliation->company_id,
            'journal_id'       => $reconciliation->journal_id,
            'date'             => '2026-09-30',
            'name'             => 'ADJ-'.$reconciliation->id.'-'.$key,
            'balance_start'    => 0,
            'balance_end'      => $amount,
            'balance_end_real' => $amount,
        ]);
    $reconciliation->update(['statement_id' => $statement->id]);

    return StatementLine::query()->create([
        'statement_id'       => $statement->id,
        'company_id'         => $reconciliation->company_id,
        'journal_id'         => $reconciliation->journal_id,
        'currency_id'        => $reconciliation->currency_id,
        'transaction_date'   => '2026-09-30',
        'amount'             => $amount,
        'amount_residual'    => $amount,
        'import_status'      => 'accepted',
        'source_fingerprint' => hash('sha256', $key),
    ]);
}

function adjustmentCounterpart(BankReconciliation $reconciliation, string $type = 'expense'): Account
{
    $account = Account::query()->withoutGlobalScopes()
        ->where(owned_by_company($reconciliation->company_id))
        ->where('id', '<>', $reconciliation->liquidity_account_id)
        ->where('deprecated', false)
        ->where('account_type', $type)
        ->first();

    if ($account) {
        return $account;
    }

    $id = (int) Account::query()->withoutGlobalScopes()->max('id') + 1;
    DB::table('accounts_accounts')->insert([
        'id'           => $id,
        'currency_id'  => $reconciliation->currency_id,
        'creator_id'   => auth()->id(),
        'account_type' => $type,
        'name'         => 'Recon '.$type.' '.fake()->unique()->numerify('####'),
        'code'         => fake()->unique()->numerify('9####'),
        'deprecated'   => false,
        'reconcile'    => false,
        'non_trade'    => false,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    $account = Account::query()->withoutGlobalScopes()->findOrFail($id);
    $account->companies()->syncWithoutDetaching([$reconciliation->company_id]);

    return $account;
}

function postedPaymentForReturn(BankReconciliation $reconciliation, PaymentType $type, string $amount): Payment
{
    $partner = Partner::query()->firstOrFail();
    $counterpart = adjustmentCounterpart(
        $reconciliation,
        $type === PaymentType::RECEIVE ? 'asset_receivable' : 'liability_payable',
    );
    $move = Move::query()->create([
        'journal_id'  => $reconciliation->journal_id,
        'company_id'  => $reconciliation->company_id,
        'currency_id' => $reconciliation->currency_id,
        'partner_id'  => $partner->id,
        'date'        => '2026-09-15',
        'move_type'   => 'entry',
        'state'       => 'draft',
        'reference'   => 'Payment return fixture',
        'creator_id'  => auth()->id(),
    ]);
    $inbound = $type === PaymentType::RECEIVE;
    foreach ([
        [$reconciliation->liquidity_account_id, $inbound ? $amount : '0.0000', $inbound ? '0.0000' : $amount, $inbound ? $amount : '-'.$amount],
        [$counterpart->id, $inbound ? '0.0000' : $amount, $inbound ? $amount : '0.0000', $inbound ? '-'.$amount : $amount],
    ] as [$accountId, $debit, $credit, $balance]) {
        MoveLine::query()->create([
            'move_id'             => $move->id,
            'journal_id'          => $reconciliation->journal_id,
            'company_id'          => $reconciliation->company_id,
            'company_currency_id' => $reconciliation->currency_id,
            'currency_id'         => $reconciliation->currency_id,
            'partner_id'          => $partner->id,
            'account_id'          => $accountId,
            'name'                => 'Payment return fixture',
            'date'                => '2026-09-15',
            'debit'               => $debit,
            'credit'              => $credit,
            'balance'             => $balance,
            'amount_currency'     => $balance,
            'creator_id'          => auth()->id(),
        ]);
    }
    $move = app(MoveWorkflow::class)->post($move->fresh('lines'));
    $paymentMethod = PaymentMethod::query()->firstOrFail();
    $paymentMethodLine = PaymentMethodLine::query()->create([
        'payment_method_id'  => $paymentMethod->id,
        'payment_account_id' => $counterpart->id,
        'journal_id'         => $reconciliation->journal_id,
        'name'               => 'Return test method',
    ]);

    return Payment::withoutEvents(fn () => Payment::query()->create([
        'company_id'             => $reconciliation->company_id,
        'journal_id'             => $reconciliation->journal_id,
        'currency_id'            => $reconciliation->currency_id,
        'partner_id'             => $partner->id,
        'move_id'                => $move->id,
        'payment_method_id'      => $paymentMethod->id,
        'payment_method_line_id' => $paymentMethodLine->id,
        'outstanding_account_id' => $counterpart->id,
        'destination_account_id' => $counterpart->id,
        'state'                  => PaymentStatus::PAID,
        'payment_type'           => $type,
        'partner_type'           => $type === PaymentType::RECEIVE ? 'customer' : 'supplier',
        'amount'                 => $amount,
        'payment_reference'      => 'RETURN-'.$move->id,
        'date'                   => '2026-09-15',
    ]));
}

it('posts bank fees and interest with posted balanced moves and statement linkage', function (): void {
    $reconciliation = adjustmentRecon();
    $feeLine = adjustmentLine($reconciliation, '-12.5000', 'fee-evidence');
    $interestLine = adjustmentLine($reconciliation, '3.2500', 'interest-evidence');
    $feeAccount = adjustmentCounterpart($reconciliation, 'expense');
    $incomeAccount = adjustmentCounterpart($reconciliation, 'income');
    $service = app(AdjustmentService::class);

    $fee = $service->post($reconciliation->refresh(), 'bank_fee', '-12.5000', $feeAccount->id, 'Monthly bank fee', $feeLine->id);
    $interest = $service->post($reconciliation->refresh(), 'interest', '3.2500', $incomeAccount->id, 'Credit interest', $interestLine->id);

    expect($fee->type)->toBe('bank_fee')
        ->and($interest->type)->toBe('interest')
        ->and(DB::table('accounts_account_moves')->whereIn('id', [$fee->move_id, $interest->move_id])->where('state', 'posted')->count())->toBe(2)
        ->and((string) DB::table('accounts_account_move_lines')->whereIn('move_id', [$fee->move_id, $interest->move_id])->sum('balance'))->toBe('0.0000')
        ->and($feeLine->refresh()->terminal_reference_id)->toBe($fee->id)
        ->and($interestLine->refresh()->terminal_reference_id)->toBe($interest->id)
        ->and($feeLine->amount_residual)->toBe('0.0000')
        ->and($interestLine->amount_residual)->toBe('0.0000');
});

it('rejects missing, zero, non-numeric, deprecated, bank, and wrong-company counterparts without side effects', function (): void {
    $reconciliation = adjustmentRecon();
    $line = adjustmentLine($reconciliation, '-5.0000', 'invalid-counterpart');
    $service = app(AdjustmentService::class);
    $beforeMoves = DB::table('accounts_account_moves')->count();

    $deprecated = adjustmentCounterpart($reconciliation);
    $deprecated->update(['deprecated' => true]);
    $foreign = adjustmentCounterpart($reconciliation);
    $foreignCompany = Company::factory()->create(['currency_id' => $reconciliation->currency_id]);
    $foreign->companies()->sync([$foreignCompany->id]);

    expect(fn () => $service->post($reconciliation, 'bank_fee', '-5.0000', null, statementLineId: $line->id))
        ->toThrow(InvalidArgumentException::class, 'counterpart account is required')
        ->and(fn () => $service->post($reconciliation, 'bank_fee', '0', $foreign->id, statementLineId: $line->id))
        ->toThrow(InvalidArgumentException::class, 'cannot be zero')
        ->and(fn () => $service->post($reconciliation, 'bank_fee', 'not-numeric', $foreign->id, statementLineId: $line->id))
        ->toThrow(InvalidArgumentException::class, 'must be numeric')
        ->and(fn () => $service->post($reconciliation, 'bank_fee', '-5.0000', $deprecated->id, statementLineId: $line->id))
        ->toThrow(InvalidArgumentException::class, 'unavailable, deprecated')
        ->and(fn () => $service->post($reconciliation, 'bank_fee', '-5.0000', $reconciliation->liquidity_account_id, statementLineId: $line->id))
        ->toThrow(InvalidArgumentException::class, 'must be different')
        ->and(fn () => $service->post($reconciliation, 'bank_fee', '-5.0000', $foreign->id, statementLineId: $line->id))
        ->toThrow(InvalidArgumentException::class, 'unavailable, deprecated')
        ->and(DB::table('accounts_account_moves')->count())->toBe($beforeMoves)
        ->and($line->refresh()->terminal_treatment)->toBeNull();
});

it('requires a statement line for source-backed adjustments and prevents a second terminal treatment', function (): void {
    $reconciliation = adjustmentRecon();
    $account = adjustmentCounterpart($reconciliation);
    $service = app(AdjustmentService::class);

    expect(fn () => $service->post($reconciliation, 'bank_fee', '-1.0000', $account->id))
        ->toThrow(InvalidArgumentException::class, 'statement line')
        ->and(fn () => $service->post($reconciliation, 'unsupported', '-1.0000', $account->id, statementLineId: null))
        ->toThrow(InvalidArgumentException::class, 'dedicated');
});

it('restores statement linkage and residual exactly once when an adjustment is reversed', function (): void {
    $reconciliation = adjustmentRecon();
    $line = adjustmentLine($reconciliation, '-8.0000', 'reversal-link');
    $account = adjustmentCounterpart($reconciliation);
    $adjustment = app(AdjustmentService::class)->post($reconciliation, 'bank_fee', '-8.0000', $account->id, 'Reversible fee', $line->id);

    $reversed = app(AdjustmentService::class)->reverse($adjustment, 'Bank refunded fee');

    expect($reversed->reversed_at)->not->toBeNull()
        ->and($line->refresh()->terminal_treatment)->toBeNull()
        ->and($line->amount_residual)->toBe('-8.0000')
        ->and($line->is_reconciled)->toBeFalse()
        ->and(fn () => app(AdjustmentService::class)->reverse($reversed, 'Duplicate reversal'))
        ->toThrow(InvalidArgumentException::class, 'already been reversed');
});

it('treats returned inbound and outbound payments with the correct bank direction', function (): void {
    $reconciliation = adjustmentRecon();
    $returnedReceipt = postedPaymentForReturn($reconciliation, PaymentType::RECEIVE, '40.0000');
    $returnedDisbursement = postedPaymentForReturn($reconciliation, PaymentType::SEND, '25.0000');
    $receiptLine = adjustmentLine($reconciliation, '-40.0000', 'returned-receipt');
    $disbursementLine = adjustmentLine($reconciliation, '25.0000', 'returned-disbursement');
    $service = app(AdjustmentService::class);

    $receiptAdjustment = $service->postReturnedPayment($reconciliation->refresh(), $returnedReceipt, $receiptLine->id, 'Customer payment failed');
    $disbursementAdjustment = $service->postReturnedPayment($reconciliation->refresh(), $returnedDisbursement, $disbursementLine->id, 'Vendor rejected payment');

    expect($receiptAdjustment->amount)->toBe('-40.0000')
        ->and($disbursementAdjustment->amount)->toBe('25.0000')
        ->and($receiptLine->refresh()->terminal_treatment)->toBe('adjustment')
        ->and($disbursementLine->refresh()->terminal_treatment)->toBe('adjustment')
        ->and(DB::table('accounts_account_moves')->whereIn('id', [$receiptAdjustment->move_id, $disbursementAdjustment->move_id])->where('state', 'posted')->count())->toBe(2);
});

it('rejects a returned payment in another currency before creating a reversal', function (): void {
    $reconciliation = adjustmentRecon();
    $payment = postedPaymentForReturn($reconciliation, PaymentType::RECEIVE, '10.0000');
    $foreignCurrency = Currency::factory()->create();
    $payment->update(['currency_id' => $foreignCurrency->id]);
    $line = adjustmentLine($reconciliation, '-10.0000', 'returned-wrong-currency');
    $beforeMoves = DB::table('accounts_account_moves')->count();

    expect(fn () => app(AdjustmentService::class)->postReturnedPayment($reconciliation, $payment->refresh(), $line->id, 'Wrong currency'))
        ->toThrow(InvalidArgumentException::class, 'currency must match')
        ->and(DB::table('accounts_account_moves')->count())->toBe($beforeMoves)
        ->and($line->refresh()->terminal_treatment)->toBeNull();
});

it('posts foreign-bank adjustments in transaction currency and company currency at the dated rate', function (): void {
    $foreignCurrency = Currency::factory()->create(['name' => fake()->unique()->currencyCode()]);
    CurrencyRate::factory()->create([
        'currency_id' => $this->company->currency_id,
        'company_id'  => $this->company->id,
        'name'        => '2026-09-30',
        'rate'        => '1.000000',
    ]);
    CurrencyRate::factory()->create([
        'currency_id' => $foreignCurrency->id,
        'company_id'  => $this->company->id,
        'name'        => '2026-09-30',
        'rate'        => '0.500000',
    ]);
    $reconciliation = foreignAdjustmentRecon($foreignCurrency);
    $line = adjustmentLine($reconciliation->refresh(), '-10.0000', 'foreign-fee');
    $counterpart = adjustmentCounterpart($reconciliation, 'expense');

    $adjustment = app(AdjustmentService::class)->post(
        $reconciliation->refresh(),
        'bank_fee',
        '-10.0000',
        $counterpart->id,
        'Foreign bank fee',
        $line->id,
    );
    $moveLines = MoveLine::query()->where('move_id', $adjustment->move_id)->orderBy('id')->get();
    $reconciliation->update(['balance_end_real' => '-10.0000']);
    $calculation = app(ReconciliationCalculator::class)->calculate($reconciliation->refresh());

    expect($moveLines)->toHaveCount(2)
        ->and($moveLines->pluck('company_currency_id')->unique()->all())->toBe([$this->company->currency_id])
        ->and($moveLines->pluck('currency_id')->unique()->all())->toBe([$foreignCurrency->id])
        ->and($moveLines->sum(fn (MoveLine $moveLine): float => (float) $moveLine->balance))->toBe(0.0)
        ->and($moveLines->sum(fn (MoveLine $moveLine): float => (float) $moveLine->amount_currency))->toBe(0.0)
        ->and($moveLines->firstWhere('account_id', $reconciliation->liquidity_account_id)->balance)->toBe('-20.0000')
        ->and($moveLines->firstWhere('account_id', $reconciliation->liquidity_account_id)->amount_currency)->toBe('-10.0000')
        ->and($calculation['gl_balance_end'])->toBe('-10.00')
        ->and($calculation['difference'])->toBe('0.00');
});

it('refuses a foreign-bank adjustment when the dated exchange rate is missing', function (): void {
    $foreignCurrency = Currency::factory()->create(['name' => fake()->unique()->currencyCode()]);
    $reconciliation = foreignAdjustmentRecon($foreignCurrency);
    $line = adjustmentLine($reconciliation->refresh(), '-10.0000', 'foreign-fee-no-rate');
    $counterpart = adjustmentCounterpart($reconciliation, 'expense');

    expect(fn () => app(AdjustmentService::class)->post(
        $reconciliation->refresh(),
        'bank_fee',
        '-10.0000',
        $counterpart->id,
        'Foreign bank fee',
        $line->id,
    ))->toThrow(InvalidArgumentException::class, 'exchange rate is required');
});

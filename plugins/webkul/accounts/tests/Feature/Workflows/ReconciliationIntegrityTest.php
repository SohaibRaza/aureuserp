<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Webkul\Account\Database\Factories\BankStatementFactory;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Events\MoveCancelled;
use Webkul\Account\Events\MoveDrafted;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\FullReconcile;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Models\PartialReconcile;
use Webkul\Account\Models\Payment;
use Webkul\Account\Services\MoveWorkflow;
use Webkul\Account\Services\Reconciler;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;

require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../Helpers/AccountHelper.php';

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('accounts');
    DB::table('plugins')->updateOrInsert(['name' => 'accounts'], ['is_installed' => true, 'is_active' => true, 'updated_at' => now()]);
    Package::$plugins = Plugin::all()->keyBy('name');
    URL::resolveMissingNamedRoutesUsing(fn () => '#');
    AccountHelper::actingAsAdmin();
    $this->partner = AccountHelper::partner();
    $this->income = AccountHelper::account('income');
    $this->document = function (MoveType $type, float $amount, bool $posted = true): Move {
        $move = AccountHelper::invoice($type, $this->partner);
        AccountHelper::productLine($move, $this->income, qty: 1, priceUnit: $amount);
        $posted ? AccountHelper::post($move) : AccountHelper::compute($move);

        return $move->fresh('paymentTermLines');
    };
});

it('uses persisted residuals when a preloaded invoice is partially settled by another call', function (): void {
    $invoice = ($this->document)(MoveType::OUT_INVOICE, 200);
    $first = ($this->document)(MoveType::OUT_REFUND, 80);
    $second = ($this->document)(MoveType::OUT_REFUND, 160);
    $preloaded = $invoice->paymentTermLines->merge($second->paymentTermLines);
    AccountHelper::reconcile($invoice, $first);

    app(Reconciler::class)->reconcile($preloaded);

    expect((float) $invoice->fresh()->amount_residual)->toBe(0.0)
        ->and((float) $second->fresh()->amount_residual)->toBe(40.0)
        ->and((float) PartialReconcile::where('debit_move_id', $invoice->paymentTermLines->first()->id)->sum('amount'))->toBe(200.0);
    expect(fn () => app(Reconciler::class)->reconcile($preloaded))
        ->toThrow(ValidationException::class, 'Some of these entries are already matched. Refresh the page and try again.');
});

it('bounds account relationship selects as a reconciliation plan grows', function (): void {
    $accountSelects = [];

    foreach ([2, 5] as $lineCount) {
        $invoice = ($this->document)(MoveType::OUT_INVOICE, 100);
        $lines = $invoice->paymentTermLines;

        for ($index = 1; $index < $lineCount; $index++) {
            $credit = ($this->document)(MoveType::OUT_REFUND, 10, false);
            $lines = $lines->merge($credit->paymentTermLines);
        }

        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        try {
            expect(fn () => app(Reconciler::class)->reconcile($lines))
                ->toThrow(ValidationException::class, 'You can only reconcile posted entries.');
            $accountSelects[] = collect($connection->getQueryLog())
                ->filter(fn (array $query): bool => (bool) preg_match('/\bfrom\s+"?accounts_accounts"?/i', $query['query']))
                ->count();
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }
    }

    expect($accountSelects[0])->toBeGreaterThan(0)
        ->and($accountSelects[1])->toBeLessThanOrEqual($accountSelects[0] + 1);
});

it('uses the first plan writes when a later plan overlaps its invoice line', function (): void {
    $invoice = ($this->document)(MoveType::OUT_INVOICE, 100);
    $firstCredit = ($this->document)(MoveType::OUT_REFUND, 40);
    $secondCredit = ($this->document)(MoveType::OUT_REFUND, 60);
    $invoiceLine = $invoice->paymentTermLines->sole();

    app(Reconciler::class)->reconcileGroups([
        collect([$invoiceLine, $firstCredit->paymentTermLines->sole()]),
        collect([$invoiceLine, $secondCredit->paymentTermLines->sole()]),
    ]);

    expect((float) $invoiceLine->fresh()->amount_residual)->toBe(0.0)
        ->and((float) PartialReconcile::where('debit_move_id', $invoiceLine->id)->sum('amount'))->toBe(100.0);
});

it('rolls back an earlier plan when a later overlapping plan is already settled', function (): void {
    $invoice = ($this->document)(MoveType::OUT_INVOICE, 100);
    $credit = ($this->document)(MoveType::OUT_REFUND, 100);
    $lines = $invoice->paymentTermLines->merge($credit->paymentTermLines);
    $before = $lines->sortBy('id')->map->getAttributes()->all();
    $partialCount = PartialReconcile::count();

    expect(fn () => app(Reconciler::class)->reconcileGroups([$lines, $lines]))
        ->toThrow(ValidationException::class, 'Some of these entries are already matched. Refresh the page and try again.');
    expect($lines->sortBy('id')->map(fn (MoveLine $line) => $line->fresh()->getAttributes())->all())->toBe($before)
        ->and(PartialReconcile::count())->toBe($partialCount);
});

it('enforces posted state through the public grouped reconciliation entry point', function (): void {
    $invoice = ($this->document)(MoveType::OUT_INVOICE, 100, false);
    $credit = ($this->document)(MoveType::OUT_REFUND, 100, false);
    $before = PartialReconcile::count();

    expect(fn () => app(Reconciler::class)->reconcileGroups([$invoice->paymentTermLines->merge($credit->paymentTermLines)]))
        ->toThrow(Exception::class, 'only reconcile posted');
    expect(PartialReconcile::count())->toBe($before)
        ->and((float) $invoice->fresh()->amount_residual)->toBe(100.0);
});

it('rolls back partials residuals and listener writes after late full reconciliation failure', function (): void {
    $invoice = ($this->document)(MoveType::OUT_INVOICE, 100);
    $credit = ($this->document)(MoveType::OUT_REFUND, 100);
    $lineIds = $invoice->paymentTermLines->merge($credit->paymentTermLines)->pluck('id')->all();
    $beforeLines = MoveLine::whereKey($lineIds)->orderBy('id')->get()->map->getAttributes()->all();
    $beforePartner = $this->partner->fresh()->getAttributes();
    $partialCount = PartialReconcile::count();
    $fullCount = FullReconcile::count();
    $dispatcher = Event::getFacadeRoot();
    Event::swap(clone $dispatcher);
    FullReconcile::created(function () use ($invoice): void {
        $invoice->partner()->update(['name' => 'Failed full reconciliation']);
        throw new RuntimeException('Full reconciliation callback failed.');
    });
    try {
        expect(fn () => AccountHelper::reconcile($invoice, $credit))->toThrow(RuntimeException::class, 'Full reconciliation callback failed.');
        expect(PartialReconcile::count())->toBe($partialCount)
            ->and(FullReconcile::count())->toBe($fullCount)
            ->and(MoveLine::whereKey($lineIds)->orderBy('id')->get()->map->getAttributes()->all())->toBe($beforeLines)
            ->and($this->partner->fresh()->getAttributes())->toBe($beforePartner)
            ->and((float) $invoice->fresh()->amount_residual)->toBe(100.0);
    } finally {
        Event::swap($dispatcher);
    }
});

it('removes one payment match from a fully matched component and safely repeats the removal', function (): void {
    $invoice = ($this->document)(MoveType::OUT_INVOICE, 200);
    AccountHelper::pay($invoice, 80);
    AccountHelper::pay($invoice, 120);
    $line = $invoice->fresh('paymentTermLines')->paymentTermLines->first();
    $partials = $line->matchedCredits->sortBy('id')->values();
    $removed = $partials->first();
    $fullId = $line->full_reconcile_id;
    $firstPayment = Payment::where('move_id', $removed->creditMove->move_id)->firstOrFail();
    $secondPaymentLine = $partials->last()->creditMove;

    app(Reconciler::class)->unReconcile($removed);
    app(Reconciler::class)->unReconcile($removed);

    expect((float) $invoice->fresh()->amount_residual)->toBe(80.0)
        ->and((float) $removed->creditMove->fresh()->amount_residual)->toBe(-80.0)
        ->and($firstPayment->fresh()->is_reconciled)->toBeFalse()
        ->and((float) $secondPaymentLine->fresh()->amount_residual)->toBe(0.0)
        ->and($secondPaymentLine->fresh()->full_reconcile_id)->toBeNull()
        ->and($secondPaymentLine->fresh()->matching_number)->toStartWith('P')
        ->and(PartialReconcile::whereKey($removed->id)->exists())->toBeFalse()
        ->and(PartialReconcile::whereKey($partials->last()->id)->exists())->toBeTrue()
        ->and(FullReconcile::whereKey($fullId)->exists())->toBeFalse();
});

it('rolls back cancel and reset including synchronous callback writes', function (string $action): void {
    $invoice = ($this->document)(MoveType::OUT_INVOICE, 200, $action === 'resetToDraft');
    if ($action === 'resetToDraft') {
        AccountHelper::pay($invoice, 80);
    }
    $beforeMove = $invoice->fresh()->getAttributes();
    $beforeLines = $invoice->lines()->orderBy('id')->get()->map->getAttributes()->all();
    $beforePartials = PartialReconcile::orderBy('id')->get()->map->getAttributes()->all();
    $beforePartner = $this->partner->fresh()->getAttributes();
    $dispatcher = Event::getFacadeRoot();
    Event::swap(clone $dispatcher);
    Event::listen($action === 'cancel' ? MoveCancelled::class : MoveDrafted::class, function () use ($invoice): void {
        $invoice->partner()->update(['name' => 'Failed lifecycle callback']);
        throw new RuntimeException('Lifecycle callback failed.');
    });
    try {
        expect(fn () => app(MoveWorkflow::class)->{$action}($invoice))->toThrow(RuntimeException::class, 'Lifecycle callback failed.');
        expect($invoice->fresh()->getAttributes())->toBe($beforeMove)
            ->and($invoice->lines()->orderBy('id')->get()->map->getAttributes()->all())->toBe($beforeLines)
            ->and(PartialReconcile::orderBy('id')->get()->map->getAttributes()->all())->toBe($beforePartials)
            ->and($this->partner->fresh()->getAttributes())->toBe($beforePartner);
    } finally {
        Event::swap($dispatcher);
    }
})->with(['cancel', 'resetToDraft']);

it('refuses stale cancellation after another caller posts the invoice', function (): void {
    $invoice = ($this->document)(MoveType::OUT_INVOICE, 100, false);
    $stale = $invoice->fresh('lines');
    AccountHelper::post($invoice);

    expect(fn () => app(MoveWorkflow::class)->cancel($stale))->toThrow(Exception::class, 'Only draft');
    expect($invoice->fresh()->state)->toBe(MoveState::POSTED);
});

it('rolls back full component removal when its deletion callback fails', function (): void {
    $invoice = ($this->document)(MoveType::OUT_INVOICE, 200);
    AccountHelper::pay($invoice, 80);
    AccountHelper::pay($invoice, 120);
    $partial = $invoice->fresh('paymentTermLines')->paymentTermLines->first()->matchedCredits->first();
    $moveIds = [$invoice->id, ...$invoice->matchedPayments->pluck('move_id')->all()];
    $beforeLines = MoveLine::whereIn('move_id', $moveIds)->orderBy('id')->get()->map->getAttributes()->all();
    $beforePartials = PartialReconcile::orderBy('id')->get()->map->getAttributes()->all();
    $beforeFulls = FullReconcile::orderBy('id')->get()->map->getAttributes()->all();
    $beforePartner = $this->partner->fresh()->getAttributes();
    $dispatcher = Event::getFacadeRoot();
    Event::swap(clone $dispatcher);
    FullReconcile::deleted(function () use ($invoice): void {
        $invoice->partner()->update(['name' => 'Failed removal callback']);
        throw new RuntimeException('Removal callback failed.');
    });
    try {
        expect(fn () => app(Reconciler::class)->unReconcile($partial))->toThrow(RuntimeException::class, 'Removal callback failed.');
        expect(MoveLine::whereIn('move_id', $moveIds)->orderBy('id')->get()->map->getAttributes()->all())->toBe($beforeLines)
            ->and(PartialReconcile::orderBy('id')->get()->map->getAttributes()->all())->toBe($beforePartials)
            ->and(FullReconcile::orderBy('id')->get()->map->getAttributes()->all())->toBe($beforeFulls)
            ->and($this->partner->fresh()->getAttributes())->toBe($beforePartner)
            ->and((float) $invoice->fresh()->amount_residual)->toBe(0.0);
    } finally {
        Event::swap($dispatcher);
    }
});

it('guards selected bank lines while leaving unrelated native AR allocation independent', function (): void {
    $invoice = ($this->document)(MoveType::OUT_INVOICE, 200);
    AccountHelper::pay($invoice, 80);
    $partial = $invoice->fresh('paymentTermLines')->paymentTermLines->first()->matchedCredits->first();
    $payment = Payment::where('move_id', $partial->creditMove->move_id)->firstOrFail();
    [$liquidityLines] = $payment->seekForLines();
    $liquidity = $liquidityLines->sole();
    $statement = BankStatementFactory::new()->create(['journal_id' => $payment->journal_id]);
    $statementLineId = DB::table('accounts_bank_statement_lines')->insertGetId(['statement_id' => $statement->id, 'journal_id' => $payment->journal_id,
        'company_id'                                                                           => $invoice->company_id, 'currency_id' => $invoice->currency_id, 'amount' => 80]);
    $reconciler = app(Reconciler::class);
    $before = ['statement_line_id' => $liquidity->statement_line_id, 'statement_id' => $liquidity->statement_id, 'matching_number' => $liquidity->matching_number];
    $stamp = MoveLine::bankMatchNumber($statementLineId);
    $reconciler->linkBankStatementLines(collect([$liquidity]), $statementLineId, $statement->id, $stamp);

    foreach ([fn () => app(MoveWorkflow::class)->resetToDraft($payment->move),
        fn () => app(MoveWorkflow::class)->reverse(collect([$payment->move])),
        fn () => $reconciler->reconcile(collect([$liquidity]))] as $action) {
        expect($action)->toThrow(ValidationException::class, 'Remove the bank reconciliation match');
    }
    $bankAttributes = $liquidity->fresh()->getAttributes();
    $reconciler->unReconcile($partial);
    $reconciler->reconcile($invoice->fresh('paymentTermLines')->paymentTermLines->push($partial->creditMove->fresh()));
    app(MoveWorkflow::class)->resetToDraft($invoice);
    expect($liquidity->fresh()->statement_id)->toBe($statement->id)
        ->and($liquidity->fresh()->statement_line_id)->toBe($statementLineId)
        ->and($liquidity->fresh()->matching_number)->toBe($stamp)
        ->and($liquidity->fresh()->getAttributes())->toBe($bankAttributes)
        ->and($invoice->fresh()->state)->toBe(MoveState::DRAFT)
        ->and(PartialReconcile::whereKey($partial->id)->exists())->toBeFalse();
    $reconciler->unlinkBankStatementLines(collect([$liquidity]), [$liquidity->id => $before], $stamp);
    $reconciler->unReconcile($partial);
    expect((float) $invoice->fresh()->amount_residual)->toBe(200.0);
});

it('keeps empty reconciliation inert and rejects forged posted state', function (): void {
    expect(app(Reconciler::class)->reconcileGroups([collect()]))->toBe(['partial_reconciles' => [], 'full_reconciles' => []]);
    $invoice = ($this->document)(MoveType::OUT_INVOICE, 100, false);
    $credit = ($this->document)(MoveType::OUT_REFUND, 100, false);
    $lines = $invoice->paymentTermLines->merge($credit->paymentTermLines);
    foreach ($lines as $line) {
        $line->parent_state = MoveState::POSTED;
        $line->move->state = MoveState::POSTED;
    }
    expect(fn () => app(Reconciler::class)->reconcile($lines))->toThrow(Exception::class, 'only reconcile posted');
});

it('compares reconciliation accounts by persisted identity rather than loaded model state', function (): void {
    $invoice = ($this->document)(MoveType::OUT_INVOICE, 100);
    $credit = ($this->document)(MoveType::OUT_REFUND, 100);
    $lines = $invoice->paymentTermLines->merge($credit->paymentTermLines);
    $accountId = $lines->first()->account_id;

    $firstAccount = Account::findOrFail($accountId);
    $staleAccount = Account::findOrFail($accountId)->setAttribute('name', 'Stale account snapshot');
    $firstCompany = $lines->first()->company;
    $staleCompany = (clone $firstCompany)->setAttribute('name', 'Stale company snapshot');

    $lines->first()->setRelation('account', $firstAccount);
    $lines->last()->setRelation('account', $staleAccount);
    $lines->first()->setRelation('company', $firstCompany);
    $lines->last()->setRelation('company', $staleCompany);

    expect($lines->pluck('account_id')->unique())->toHaveCount(1);

    app(Reconciler::class)->assertReconcilable($lines);

    $otherAccount = AccountHelper::account('income');
    $lines->last()->account_id = $otherAccount->id;
    $lines->last()->setRelation('account', $otherAccount);

    expect(fn () => app(Reconciler::class)->assertReconcilable($lines))
        ->toThrow(ValidationException::class, 'Entries are not from the same account');
});

it('unmatches foreign documents and reverses their recorded exchange difference coherently', function (): void {
    $foreign = AccountHelper::otherCurrency();
    $foreign->rates()->delete();
    AccountHelper::setCurrencyRate($foreign, 2.0);
    AccountHelper::setCurrencyRate($foreign, 4.0, now()->toDateString());
    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE, $this->partner, null, [
        'currency_id' => $foreign->id, 'date' => now()->subYear()->toDateString(), 'invoice_date' => now()->subYear()->toDateString(),
    ]);
    AccountHelper::productLine($invoice, $this->income, qty: 1, priceUnit: 200);
    AccountHelper::post($invoice);
    $credit = AccountHelper::invoice(MoveType::OUT_REFUND, $this->partner, null, ['currency_id' => $foreign->id]);
    AccountHelper::productLine($credit, $this->income, qty: 1, priceUnit: 200);
    AccountHelper::post($credit);
    AccountHelper::reconcile($invoice, $credit);
    $partial = PartialReconcile::where('debit_move_id', $invoice->fresh('paymentTermLines')->paymentTermLines->first()->id)
        ->whereNotNull('exchange_move_id')->firstOrFail();
    $exchangeId = $partial->exchange_move_id;
    $exchange = Move::findOrFail($exchangeId);
    $beforeExchange = $exchange->getAttributes();
    $beforeLines = $exchange->lines->map->getAttributes()->all();
    expect(fn () => app(MoveWorkflow::class)->resetToDraft($exchange))
        ->toThrow(ValidationException::class, 'This currency adjustment cannot be reset directly.');
    expect($exchange->fresh()->getAttributes())->toBe($beforeExchange)
        ->and($exchange->fresh('lines')->lines->map->getAttributes()->all())->toBe($beforeLines);

    app(Reconciler::class)->unReconcile($partial);

    expect((float) $invoice->fresh()->amount_residual)->toBe(200.0)
        ->and((float) $credit->fresh()->amount_residual)->toBe(200.0)
        ->and(Move::where('reversed_entry_id', $exchangeId)->where('state', MoveState::POSTED)->count())->toBe(1)
        ->and($invoice->fresh('paymentTermLines')->paymentTermLines->first()->reconciled)->toBeFalse()
        ->and($credit->fresh('paymentTermLines')->paymentTermLines->first()->reconciled)->toBeFalse();
});

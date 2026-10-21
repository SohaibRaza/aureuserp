<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Events\MoveConfirmed;
use Webkul\Account\Models\Move;
use Webkul\Account\Services\MoveCalculator;
use Webkul\Account\Services\MoveWorkflow;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;

require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../Helpers/AccountHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('accounts');

    DB::table('plugins')->updateOrInsert(
        ['name' => 'accounts'],
        ['is_installed' => true, 'is_active' => true, 'updated_at' => now()],
    );

    Package::$plugins = Plugin::all()->keyBy('name');

    URL::resolveMissingNamedRoutesUsing(fn () => '#');

    AccountHelper::actingAsAdmin();

    $this->income = AccountHelper::account('income');
    $this->expense = AccountHelper::account('expense');
    $this->partner = AccountHelper::partner();
});

it('cancels a draft invoice', function () {
    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE, $this->partner);
    AccountHelper::productLine($invoice, $this->income, qty: 2, priceUnit: 100);

    AccountHelper::cancel($invoice);

    expect($invoice->refresh()->state)->toBe(MoveState::CANCEL);
});

it('resets a posted invoice back to draft', function () {
    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE, $this->partner);
    AccountHelper::productLine($invoice, $this->income, qty: 2, priceUnit: 100);

    AccountHelper::post($invoice);
    AccountHelper::resetToDraft($invoice);

    expect($invoice->refresh()->state)->toBe(MoveState::DRAFT);
});

it('refuses to post a customer invoice without a partner', function () {
    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE, $this->partner, null, ['partner_id' => null]);
    AccountHelper::productLine($invoice, $this->income, qty: 2, priceUnit: 100);

    expect(fn () => AccountHelper::post($invoice))
        ->toThrow(Exception::class, __('accounts::account-manager.post-action-validate.customer-required'));
});

it('refuses to post a vendor bill without an invoice date', function () {
    $bill = AccountHelper::invoice(MoveType::IN_INVOICE, $this->partner, null, ['invoice_date' => null]);
    AccountHelper::productLine($bill, $this->expense, qty: 2, priceUnit: 100);

    expect(fn () => AccountHelper::post($bill))
        ->toThrow(Exception::class, __('accounts::account-manager.post-action-validate.date-required'));
});

it('refuses to post an invoice with no lines', function () {
    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE, $this->partner);

    expect(fn () => AccountHelper::post($invoice))
        ->toThrow(Exception::class, __('accounts::account-manager.post-action-validate.lines-required'));
});

it('refuses to post an already posted invoice', function () {
    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE, $this->partner);
    AccountHelper::productLine($invoice, $this->income, qty: 2, priceUnit: 100);

    AccountHelper::post($invoice);

    expect(fn () => AccountHelper::post($invoice))
        ->toThrow(Exception::class, __('accounts::account-manager.post-action-validate.draft-state-required'));
});

it('marks a posted invoice as checked', function () {
    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE, $this->partner);
    AccountHelper::productLine($invoice, $this->income, qty: 2, priceUnit: 100);
    AccountHelper::post($invoice);

    AccountHelper::setAsChecked($invoice);

    expect($invoice->refresh()->checked)->toBeTrue()
        ->and($invoice->state)->toBe(MoveState::POSTED);
});

it('rolls back posting when recomputation fails after changing invoice lines', function (): void {
    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE, $this->partner);
    AccountHelper::productLine($invoice, $this->income, qty: 2, priceUnit: 100);
    $beforeMove = $invoice->fresh()->getAttributes();
    $beforeLines = $invoice->lines()->orderBy('id')->get()->map->getAttributes()->all();
    $beforeRank = $this->partner->fresh()->customer_rank;
    Event::fake([MoveConfirmed::class]);
    app()->instance(MoveCalculator::class, new class extends MoveCalculator
    {
        public function recompute(Move $move): Move
        {
            parent::recompute($move);

            throw new RuntimeException('Recompute failed after writing lines.');
        }
    });

    expect(fn () => app(MoveWorkflow::class)->post($invoice))
        ->toThrow(RuntimeException::class, 'Recompute failed after writing lines.');

    expect($invoice->refresh()->getAttributes())->toBe($beforeMove)
        ->and($invoice->lines()->orderBy('id')->get()->map->getAttributes()->all())->toBe($beforeLines)
        ->and($this->partner->fresh()->customer_rank)->toBe($beforeRank);
    Event::assertNotDispatched(MoveConfirmed::class);
});

it('rolls back posting and listener writes when a confirmation callback fails', function (): void {
    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE, $this->partner);
    AccountHelper::productLine($invoice, $this->income, qty: 2, priceUnit: 100);
    $beforeMove = $invoice->fresh()->getAttributes();
    $beforeLines = $invoice->lines()->orderBy('id')->get()->map->getAttributes()->all();
    $beforePartner = $this->partner->fresh()->getAttributes();
    $invoice->checked = true;
    $dispatcher = Event::getFacadeRoot();
    Event::swap(clone $dispatcher);
    Event::listen(MoveConfirmed::class, function (MoveConfirmed $event): void {
        $event->move->partner()->update(['name' => 'Failed confirmation callback']);

        throw new RuntimeException('Confirmation callback failed.');
    });

    try {
        expect(fn () => app(MoveWorkflow::class)->post($invoice))
            ->toThrow(RuntimeException::class, 'Confirmation callback failed.');

        expect($invoice->state)->toBe(MoveState::DRAFT)
            ->and($invoice->checked)->toBeTrue()
            ->and($invoice->fresh()->getAttributes())->toBe($beforeMove)
            ->and($invoice->lines()->orderBy('id')->get()->map->getAttributes()->all())->toBe($beforeLines)
            ->and($this->partner->fresh()->getAttributes())->toBe($beforePartner);
    } finally {
        Event::swap($dispatcher);
    }

    app(MoveWorkflow::class)->post($invoice);

    expect($invoice->fresh()->state)->toBe(MoveState::POSTED)
        ->and($invoice->fresh()->checked)->toBeTrue()
        ->and((int) $this->partner->fresh()->customer_rank)->toBe((int) $beforePartner['customer_rank'] + 1);
});

it('rejects a stale draft after another caller has posted without repeating ranks or events', function (): void {
    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE, $this->partner);
    AccountHelper::productLine($invoice, $this->income, qty: 2, priceUnit: 100);
    $stale = $invoice->fresh('lines');
    $beforeRank = (int) $this->partner->fresh()->customer_rank;
    Event::fake([MoveConfirmed::class]);
    app(MoveWorkflow::class)->post($invoice);

    expect(fn () => app(MoveWorkflow::class)->post($stale))
        ->toThrow(ValidationException::class, __('accounts::account-manager.post-action-validate.draft-state-required'));

    expect($invoice->fresh()->state)->toBe(MoveState::POSTED)
        ->and((int) $this->partner->fresh()->customer_rank)->toBe($beforeRank + 1);
    Event::assertDispatchedTimes(MoveConfirmed::class, 1);
});

it('validates persisted state before applying an unsaved draft state', function (): void {
    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE, $this->partner);
    AccountHelper::productLine($invoice, $this->income, qty: 2, priceUnit: 100);
    Event::fake([MoveConfirmed::class]);
    app(MoveWorkflow::class)->post($invoice);
    $invoice->state = MoveState::DRAFT;
    $rank = $this->partner->fresh()->customer_rank;

    expect(fn () => app(MoveWorkflow::class)->post($invoice))
        ->toThrow(ValidationException::class, __('accounts::account-manager.post-action-validate.draft-state-required'));

    expect($invoice->fresh()->state)->toBe(MoveState::POSTED)
        ->and($this->partner->fresh()->customer_rank)->toBe($rank);
    Event::assertDispatchedTimes(MoveConfirmed::class, 1);
});

it('rejects unsaved identity changes before posting either journal entry', function (string $attribute): void {
    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE, $this->partner);
    AccountHelper::productLine($invoice, $this->income, qty: 2, priceUnit: 100);
    $other = AccountHelper::invoice(MoveType::OUT_INVOICE, $this->partner);
    $invoiceId = $invoice->id;
    $invoice->{$attribute} = $other->id;
    Event::fake([MoveConfirmed::class]);

    expect(fn () => app(MoveWorkflow::class)->post($invoice))
        ->toThrow(Exception::class, __('accounts::system.move.posting-identity-changed'));

    expect(Move::findOrFail($invoiceId)->state)->toBe(MoveState::DRAFT)
        ->and($other->fresh()->state)->toBe(MoveState::DRAFT);
    Event::assertNotDispatched(MoveConfirmed::class);
})->with(['id', 'reversed_entry_id']);

it('preserves unsaved checked and invoice date input while posting fresh persisted lines', function (): void {
    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE, $this->partner, null, ['checked' => false]);
    $invoice->load('lines');
    AccountHelper::productLine($invoice, $this->income, qty: 2, priceUnit: 100);
    $invoice->checked = true;
    $invoice->invoice_date = now()->subDay();

    app(MoveWorkflow::class)->post($invoice);

    expect($invoice->refresh()->state)->toBe(MoveState::POSTED)
        ->and($invoice->checked)->toBeTrue()
        ->and($invoice->invoice_date->toDateString())->toBe(now()->subDay()->toDateString())
        ->and((float) $invoice->amount_total)->toBe(200.0);
});

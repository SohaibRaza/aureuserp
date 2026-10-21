<?php

use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Enums\PaymentState;
use Webkul\Account\Enums\PaymentStatus;
use Webkul\Account\Events\MoveCancelled;
use Webkul\Account\Events\MoveDrafted;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Filament\Resources\InvoiceResource\Actions\CancelAction;
use Webkul\Account\Filament\Resources\InvoiceResource\Actions\ConfirmAction;
use Webkul\Account\Filament\Resources\InvoiceResource\Actions\PayAction;
use Webkul\Account\Filament\Resources\InvoiceResource\Actions\ResetToDraftAction;
use Webkul\Account\Filament\Resources\InvoiceResource\Actions\ReverseAction;
use Webkul\Account\Filament\Resources\InvoiceResource\Actions\SetAsCheckedAction;
use Webkul\Account\Filament\Resources\InvoiceResource\Pages\CreateInvoice;
use Webkul\Account\Filament\Resources\InvoiceResource\Pages\EditInvoice;
use Webkul\Account\Filament\Resources\InvoiceResource\Pages\ListInvoices;
use Webkul\Account\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use Webkul\Account\Models\Move;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;

require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../../../support/tests/Helpers/FilamentHelper.php';
require_once __DIR__.'/../../Helpers/AccountHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('accounts');

    DB::table('plugins')->updateOrInsert(
        ['name' => 'accounts'],
        ['is_installed' => true, 'is_active' => true, 'updated_at' => now()],
    );

    Package::$plugins = Plugin::all()->keyBy('name');

    URL::resolveMissingNamedRoutesUsing(fn () => '#');
});

it('forbids listing invoices without permission', function () {
    FilamentHelper::actingAs([]);

    Livewire::test(ListInvoices::class)->assertForbidden();
});

it('lists invoices with their key columns for authorized users', function () {
    FilamentHelper::actingAs(['view_any_account_invoice']);

    Livewire::test(ListInvoices::class)
        ->assertOk()
        ->assertCanRenderTableColumn('name')
        ->assertCanRenderTableColumn('state');
});

it('renders the invoice create page', function () {
    FilamentHelper::actingAs(['view_any_account_invoice', 'create_account_invoice']);

    Livewire::test(CreateInvoice::class)->assertOk();
});

it('posts a draft invoice through the confirm action', function () {
    FilamentHelper::actingAs(['view_any_account_invoice', 'update_account_invoice']);

    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE);
    AccountHelper::productLine($invoice, AccountHelper::account('income'), qty: 2, priceUnit: 100);
    AccountHelper::compute($invoice);

    Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->assertOk()
        ->assertActionExists(ConfirmAction::class)
        ->callAction(ConfirmAction::class);

    expect($invoice->refresh()->state)->toBe(MoveState::POSTED);
});

it('cancels a draft invoice through the cancel action', function () {
    FilamentHelper::actingAs(['view_any_account_invoice', 'update_account_invoice']);

    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE);

    Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->assertOk()
        ->callAction(CancelAction::class);

    expect($invoice->refresh()->state)->toBe(MoveState::CANCEL);
});

function postedInvoiceRecord(): Move
{
    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE);

    AccountHelper::productLine($invoice, AccountHelper::account('income'), qty: 2, priceUnit: 100);

    return AccountHelper::post($invoice);
}

it('reverses a posted invoice into a credit note through the action', function () {
    FilamentHelper::actingAs(['view_any_account_invoice', 'update_account_invoice']);

    $invoice = postedInvoiceRecord();

    Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->assertOk()
        ->callAction(ReverseAction::class, data: [
            'reason'     => 'Test reversal',
            'journal_id' => $invoice->journal_id,
            'date'       => now(),
        ]);

    expect(
        Move::query()
            ->where('reversed_entry_id', $invoice->id)
            ->where('move_type', MoveType::OUT_REFUND)
            ->exists()
    )->toBeTrue();
});

it('resets a posted invoice to draft through the action', function () {
    FilamentHelper::actingAs(['view_any_account_invoice', 'update_account_invoice']);

    $invoice = postedInvoiceRecord();

    Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->assertOk()
        ->callAction(ResetToDraftAction::class);

    expect($invoice->refresh()->state)->toBe(MoveState::DRAFT);
});

it('marks a posted invoice as checked through the action', function () {
    FilamentHelper::actingAs(['view_any_account_invoice', 'update_account_invoice']);

    $invoice = postedInvoiceRecord();

    Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->assertOk()
        ->callAction(SetAsCheckedAction::class);

    expect($invoice->refresh()->checked)->toBeTrue();
});

it('registers a full payment and marks the invoice paid through the action', function () {
    FilamentHelper::actingAs(['view_any_account_invoice', 'update_account_invoice', 'create_account_payment']);

    AccountHelper::bankJournal();

    $invoice = postedInvoiceRecord();

    Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->assertOk()
        ->assertActionVisible(PayAction::class)
        ->callAction(PayAction::class)
        ->assertActionHidden(PayAction::class);

    expect($invoice->refresh()->payment_state)->toBe(PaymentState::PAID);

    expect($invoice->matchedPayments)->toHaveCount(1);

    expect($invoice->matchedPayments->first()->state)->toBe(PaymentStatus::PAID);
});

it('marks all payments paid once the invoice is fully paid in installments', function () {
    FilamentHelper::actingAs(['view_any_account_invoice', 'update_account_invoice', 'create_account_payment']);

    AccountHelper::bankJournal();

    $invoice = postedInvoiceRecord();

    Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->assertOk()
        ->callAction(PayAction::class, data: ['amount' => 100]);

    $invoice->refresh();

    expect($invoice->payment_state)->toBe(PaymentState::PARTIAL);

    expect($invoice->matchedPayments->first()->state)->toBe(PaymentStatus::IN_PROCESS);

    Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->assertOk()
        ->callAction(PayAction::class, data: ['amount' => 100]);

    $invoice->refresh();

    expect($invoice->payment_state)->toBe(PaymentState::PAID);

    expect($invoice->matchedPayments)->toHaveCount(2);

    $invoice->matchedPayments->each(
        fn ($payment) => expect($payment->state)->toBe(PaymentStatus::PAID)
    );
});

it('hides correction actions from invoice viewers without update permission', function (string $action, bool $posted) {
    FilamentHelper::actingAs(['view_any_account_invoice', 'view_account_invoice']);
    $invoice = $posted ? postedInvoiceRecord() : AccountHelper::invoice();
    Livewire::test(ViewInvoice::class, ['record' => $invoice->id])->assertActionHidden($action);
})->with([[CancelAction::class, false], [ResetToDraftAction::class, true]]);

it('enforces update permission inside invoice correction callbacks', function (string $action, bool $posted) {
    FilamentHelper::actingAs(['view_any_account_invoice', 'view_account_invoice']);
    $invoice = $posted ? postedInvoiceRecord() : AccountHelper::invoice();
    $component = Livewire::test(ViewInvoice::class, ['record' => $invoice->id])->instance();
    $record = $component->getRecord();
    $state = $record->state;
    expect(fn () => ($action::make()->getActionFunction())($record, $component))->toThrow(AuthorizationException::class);
    expect($record->refresh()->state)->toBe($state);
})->with([[CancelAction::class, false], [ResetToDraftAction::class, true]]);

it('allows invoice editors to correct invoices from the view page', function (string $action, bool $posted, MoveState $expected) {
    FilamentHelper::actingAs(['view_any_account_invoice', 'view_account_invoice', 'update_account_invoice']);
    $invoice = $posted ? postedInvoiceRecord() : AccountHelper::invoice();
    Livewire::test(ViewInvoice::class, ['record' => $invoice->id])->callAction($action);
    expect($invoice->refresh()->state)->toBe($expected);
})->with([[CancelAction::class, false, MoveState::CANCEL], [ResetToDraftAction::class, true, MoveState::DRAFT]]);

it('hides payment registration from invoice editors without payment creation permission', function () {
    FilamentHelper::actingAs(['view_any_account_invoice', 'update_account_invoice']);
    AccountHelper::bankJournal();
    $invoice = postedInvoiceRecord();
    Livewire::test(EditInvoice::class, ['record' => $invoice->id])->assertActionHidden(PayAction::class);
});

it('shows the first safe validation message when an invoice correction is blocked', function (string $action, bool $posted, string $operation) {
    FilamentHelper::actingAs(['view_any_account_invoice', 'view_account_invoice', 'update_account_invoice']);
    $invoice = $posted ? postedInvoiceRecord() : AccountHelper::invoice();
    $state = $invoice->state;
    $page = Livewire::test(ViewInvoice::class, ['record' => $invoice->id]);
    $message = __('accounts::system.reconciliation.bank-match');
    AccountFacade::shouldReceive($operation)->once()->andThrow(ValidationException::withMessages([
        'state' => [$message, __('accounts::system.reconciliation.changed')],
    ]));

    $page->mountAction($action)
        ->assertNotified(Notification::make()->warning()->body($message))
        ->assertNotDispatched('refreshInvoiceSummary');

    expect($invoice->refresh()->state)->toBe($state);
})->with([[CancelAction::class, false, 'cancelMove'], [ResetToDraftAction::class, true, 'resetToDraftMove']]);

it('propagates unexpected invoice correction callback errors and rolls back writes', function (string $action, bool $posted, string $event) {
    FilamentHelper::actingAs(['view_any_account_invoice', 'view_account_invoice', 'update_account_invoice']);
    $invoice = $posted ? postedInvoiceRecord() : AccountHelper::invoice();
    $state = $invoice->state;
    $lineStates = $invoice->lines()->orderBy('id')->pluck('parent_state', 'id')->all();
    $page = Livewire::test(ViewInvoice::class, ['record' => $invoice->id]);
    $transactionLevel = DB::connection()->transactionLevel();
    Event::listen($event, function (): never {
        throw new RuntimeException('Unexpected correction callback failure');
    });

    expect(fn () => $page->callAction($action))->toThrow(RuntimeException::class, 'Unexpected correction callback failure');
    Notification::assertNotNotified();
    expect($invoice->refresh()->state)->toBe($state)
        ->and($invoice->lines()->orderBy('id')->pluck('parent_state', 'id')->all())->toBe($lineStates)
        ->and(DB::connection()->transactionLevel())->toBe($transactionLevel);
})->with([[CancelAction::class, false, MoveCancelled::class], [ResetToDraftAction::class, true, MoveDrafted::class]]);

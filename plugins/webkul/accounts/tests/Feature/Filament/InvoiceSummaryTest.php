<?php

use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Livewire\InvoiceSummary;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Models\PartialReconcile;
use Webkul\Invoice\Livewire\InvoiceSummary as InvoiceModuleSummary;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;
use Webkul\Security\PermissionRegistrar;

require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../../../support/tests/Helpers/FilamentHelper.php';
require_once __DIR__.'/../../Helpers/AccountHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('accounts');
    TestBootstrapHelper::ensurePluginInstalled('invoices');
    DB::table('plugins')->updateOrInsert(['name' => 'accounts'], ['is_installed' => true, 'is_active' => true, 'updated_at' => now()]);
    Package::$plugins = Plugin::all()->keyBy('name');
    URL::resolveMissingNamedRoutesUsing(fn () => '#');
    FilamentHelper::actingAs(['update_account_invoice']);
});

function summaryInvoicePair(): array
{
    $partner = AccountHelper::partner();
    $invoice = AccountHelper::invoice(partner: $partner);
    AccountHelper::productLine($invoice, AccountHelper::account('income'), qty: 1, priceUnit: 100);
    AccountHelper::post($invoice);
    $credit = AccountHelper::invoice(MoveType::OUT_REFUND, $partner);
    AccountHelper::productLine($credit, AccountHelper::account('income'), qty: 1, priceUnit: 100);
    AccountHelper::post($credit);

    return [$invoice->refresh(), $credit->refresh()];
}

function summaryPartialsFor(Move $invoice): Builder
{
    $lineIds = $invoice->lines()->pluck('id');

    return PartialReconcile::query()->where(function (Builder $query) use ($lineIds): void {
        $query->whereIn('debit_move_id', $lineIds)->orWhereIn('credit_move_id', $lineIds);
    });
}

it('authorizes the concrete document policy inside both summary callbacks', function (MoveType $type, string $permission) {
    $record = AccountHelper::invoice($type);
    FilamentHelper::actingAs([]);
    $component = Livewire::test(InvoiceSummary::class, ['record' => $record])->instance();

    foreach (['reconcileAction' => ['lineId' => 0], 'unReconcileAction' => ['partial_id' => 0]] as $method => $arguments) {
        $action = $component->$method();
        expect(fn () => ($action->getActionFunction())($arguments, $action))->toThrow(AuthorizationException::class);
    }

    FilamentHelper::actingAs([$permission]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect(Livewire::test(InvoiceSummary::class, ['record' => $record])->instance()->reconcileAction()->isAuthorized())->toBeTrue();
})->with([
    [MoveType::OUT_INVOICE, 'update_account_invoice'],
    [MoveType::IN_INVOICE, 'update_account_bill'],
    [MoveType::OUT_REFUND, 'update_account_credit::note'],
    [MoveType::IN_REFUND, 'update_account_refund'],
]);

it('rejects missing or unrelated payment selections with a warning', function (bool $unrelated) {
    [$invoice] = summaryInvoicePair();
    [$other] = summaryInvoicePair();
    $lineId = $unrelated ? $other->getReconcilablePayments()['lines'][0]['id'] : 0;

    $component = Livewire::test(InvoiceSummary::class, ['record' => $invoice])
        ->callAction('reconcile', arguments: ['lineId' => $lineId])
        ->assertNotified(Notification::make()->warning()->title(__('accounts::filament/resources/invoice.summary.notifications.match-failed'))
            ->body(__('accounts::filament/resources/invoice.summary.notifications.payment-unavailable')));

    expect(summaryPartialsFor($invoice)->count())->toBe(0)
        ->and(summaryPartialsFor($other)->count())->toBe(0)
        ->and($component->effects['js'] ?? [])->toBeEmpty();
})->with([false, true]);

it('matches and removes a legitimate payment through summary actions', function () {
    [$unrelated, $unrelatedCredit] = summaryInvoicePair();
    AccountHelper::reconcile($unrelated, $unrelatedCredit);
    $earlierPartial = summaryPartialsFor($unrelated)->sole();
    $beforeEarlierPartial = $earlierPartial->getAttributes();
    [$invoice] = summaryInvoicePair();
    $lineId = $invoice->getReconcilablePayments()['lines'][0]['id'];
    Livewire::test(InvoiceSummary::class, ['record' => $invoice])->callAction('reconcile', arguments: ['lineId' => $lineId]);
    $partial = summaryPartialsFor($invoice)->sole();
    expect((float) $invoice->refresh()->amount_residual)->toBe(0.0);

    Livewire::test(InvoiceSummary::class, ['record' => $invoice])->callAction('unReconcile', arguments: ['partial_id' => $partial->id]);
    expect(PartialReconcile::find($partial->id))->toBeNull()
        ->and((float) $invoice->refresh()->amount_residual)->toBe(100.0)
        ->and($earlierPartial->fresh()->getAttributes())->toBe($beforeEarlierPartial)
        ->and((float) $unrelated->refresh()->amount_residual)->toBe(0.0);
});

it('authorizes invoice module callbacks with the matching invoice module permission', function (MoveType $type, string $permission) {
    $record = AccountHelper::invoice($type);
    FilamentHelper::actingAs([$permission]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Livewire::test(InvoiceModuleSummary::class, ['record' => $record])
        ->callAction('reconcile', arguments: ['lineId' => 0])
        ->assertNotified(Notification::make()->warning()->title(__('accounts::filament/resources/invoice.summary.notifications.match-failed'))
            ->body(__('accounts::filament/resources/invoice.summary.notifications.payment-unavailable')));

    Livewire::test(InvoiceModuleSummary::class, ['record' => $record])
        ->callAction('unReconcile', arguments: ['partial_id' => 0])
        ->assertNotified(Notification::make()->warning()->title(__('accounts::filament/resources/invoice.summary.notifications.remove-failed'))
            ->body(__('accounts::filament/resources/invoice.summary.notifications.match-unavailable')));
})->with([
    [MoveType::OUT_INVOICE, 'update_invoice_invoice'],
    [MoveType::IN_INVOICE, 'update_invoice_bill'],
    [MoveType::OUT_REFUND, 'update_invoice_credit::note'],
    [MoveType::IN_REFUND, 'update_invoice_refund'],
]);

it('rejects direct invoice module callbacks with accounts-only permissions', function () {
    $record = AccountHelper::invoice(MoveType::OUT_INVOICE);
    FilamentHelper::actingAs(['update_account_invoice']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $component = Livewire::test(InvoiceModuleSummary::class, ['record' => $record])->instance();

    foreach (['reconcileAction' => ['lineId' => 0], 'unReconcileAction' => ['partial_id' => 0]] as $method => $arguments) {
        $action = $component->$method();
        expect(fn () => ($action->getActionFunction())($arguments, $action))->toThrow(AuthorizationException::class);
    }
});

it('matches and removes a payment with invoice module-only permission', function () {
    [$invoice] = summaryInvoicePair();
    FilamentHelper::actingAs(['update_invoice_invoice']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $lineId = $invoice->getReconcilablePayments()['lines'][0]['id'];

    Livewire::test(InvoiceModuleSummary::class, ['record' => $invoice])
        ->callAction('reconcile', arguments: ['lineId' => $lineId]);
    $partial = summaryPartialsFor($invoice)->sole();

    expect((float) $invoice->refresh()->amount_residual)->toBe(0.0);

    Livewire::test(InvoiceModuleSummary::class, ['record' => $invoice])
        ->callAction('unReconcile', arguments: ['partial_id' => $partial->id]);

    expect(PartialReconcile::find($partial->id))->toBeNull()
        ->and((float) $invoice->refresh()->amount_residual)->toBe(100.0);
});

it('cannot remove a payment match belonging to another invoice', function () {
    [$invoice] = summaryInvoicePair();
    [$other] = summaryInvoicePair();
    $lineId = $other->getReconcilablePayments()['lines'][0]['id'];
    Livewire::test(InvoiceSummary::class, ['record' => $other])->callAction('reconcile', arguments: ['lineId' => $lineId]);
    $partial = summaryPartialsFor($other)->sole();

    $component = Livewire::test(InvoiceSummary::class, ['record' => $invoice])
        ->callAction('unReconcile', arguments: ['partial_id' => $partial->id])
        ->assertNotified(Notification::make()->warning()->title(__('accounts::filament/resources/invoice.summary.notifications.remove-failed'))
            ->body(__('accounts::filament/resources/invoice.summary.notifications.match-unavailable')));

    expect(PartialReconcile::find($partial->id))->not->toBeNull()
        ->and($component->effects['js'] ?? [])->toBeEmpty();
});

it('shows a service conflict and does not reload on failure', function () {
    [$invoice] = summaryInvoicePair();
    $lineId = $invoice->getReconcilablePayments()['lines'][0]['id'];
    DB::table('accounts_account_move_lines')->where('id', $lineId)->update(['matching_number' => 'RB-test-provenance']);

    $component = Livewire::test(InvoiceSummary::class, ['record' => $invoice])
        ->callAction('reconcile', arguments: ['lineId' => $lineId])
        ->assertNotified(Notification::make()->warning()->title(__('accounts::filament/resources/invoice.summary.notifications.match-failed'))
            ->body(__('accounts::system.reconciliation.bank-match')));

    expect(summaryPartialsFor($invoice)->count())->toBe(0)
        ->and($component->effects['js'] ?? [])->toBeEmpty();
});

it('rejects a payment selection when the displayed invoice is no longer posted', function () {
    [$invoice] = summaryInvoicePair();
    $lineId = $invoice->getReconcilablePayments()['lines'][0]['id'];
    $component = Livewire::test(InvoiceSummary::class, ['record' => $invoice]);
    DB::table($invoice->getTable())->where('id', $invoice->id)->update(['state' => 'draft']);
    $component->callAction('reconcile', arguments: ['lineId' => $lineId])
        ->assertNotified(Notification::make()->warning()->title(__('accounts::filament/resources/invoice.summary.notifications.match-failed'))
            ->body(__('accounts::filament/resources/invoice.summary.notifications.payment-unavailable')));
    expect(summaryPartialsFor($invoice)->count())->toBe(0)
        ->and($component->effects['js'] ?? [])->toBeEmpty();
});

it('rejects a missing payment match without reloading', function () {
    [$invoice] = summaryInvoicePair();
    $component = Livewire::test(InvoiceSummary::class, ['record' => $invoice])
        ->callAction('unReconcile', arguments: ['partial_id' => 0])
        ->assertNotified(Notification::make()->warning()->title(__('accounts::filament/resources/invoice.summary.notifications.remove-failed'))
            ->body(__('accounts::filament/resources/invoice.summary.notifications.match-unavailable')));
    expect($component->effects['js'] ?? [])->toBeEmpty();
});

it('shows an accounting configuration conflict without reloading', function () {
    [$invoice] = summaryInvoicePair();
    $lineId = $invoice->getReconcilablePayments()['lines'][0]['id'];
    $account = MoveLine::findOrFail($lineId)->account;
    $account->update(['reconcile' => false]);

    $component = Livewire::test(InvoiceSummary::class, ['record' => $invoice])
        ->callAction('reconcile', arguments: ['lineId' => $lineId])
        ->assertNotified(Notification::make()->warning()->title(__('accounts::filament/resources/invoice.summary.notifications.match-failed'))
            ->body(__('Account :account does not allow reconciliation. First change the configuration of this account to allow it.', ['account' => $account->display_name])));

    expect(summaryPartialsFor($invoice)->count())->toBe(0)
        ->and($component->effects['js'] ?? [])->toBeEmpty();
});

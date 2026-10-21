<?php

use App\Models\Recon\BankReconciliation;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Enums\PaymentStatus;
use Webkul\Account\Enums\PaymentType;
use Webkul\Account\Events\MoveDrafted;
use Webkul\Account\Filament\Resources\PaymentResource;
use Webkul\Account\Filament\Resources\PaymentResource\Actions\CancelAction;
use Webkul\Account\Filament\Resources\PaymentResource\Actions\ConfirmAction;
use Webkul\Account\Filament\Resources\PaymentResource\Actions\ResetToDraftAction;
use Webkul\Account\Filament\Resources\PaymentResource\Pages\EditPayment;
use Webkul\Account\Filament\Resources\PaymentResource\Pages\ViewPayment;
use Webkul\Account\Models\FullReconcile;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\PartialReconcile;
use Webkul\Account\Models\Payment;
use Webkul\Account\Services\MoveWorkflow;
use Webkul\Partner\Models\BankAccount;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;
use Webkul\Security\PermissionRegistrar;

require_once __DIR__.'/../../../../support/tests/Helpers/FilamentHelper.php';
require_once __DIR__.'/../../Helpers/AccountHelper.php';
require_once __DIR__.'/../../../../../../tests/Feature/Recon/ReconTestSupport.php';

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('accounts');
    DB::table('plugins')->updateOrInsert(['name' => 'accounts'], ['is_installed' => true, 'is_active' => true, 'updated_at' => now()]);
    Package::$plugins = Plugin::all()->keyBy('name');
    URL::resolveMissingNamedRoutesUsing(fn () => '#');
    AccountHelper::actingAsAdmin();
    FilamentHelper::bootAdminPanel();
    $this->invoice = AccountHelper::invoice(MoveType::OUT_INVOICE, AccountHelper::partner());
    AccountHelper::productLine($this->invoice, AccountHelper::account('income'), qty: 2, priceUnit: 100);
    AccountHelper::post($this->invoice);
    AccountHelper::pay($this->invoice);
    $this->payment = $this->invoice->matchedPayments()->sole();
});

it('preserves the payment and accounting matches when native reset is blocked by bank provenance', function (): void {
    [$liquidity] = $this->payment->seekForLines();
    $liquidity->sole()->updateQuietly(['matching_number' => 'RB000001']);
    $beforePayment = $this->payment->fresh()->getAttributes();
    $beforeMove = $this->payment->move->fresh()->getAttributes();
    $beforeLines = $this->payment->move->lines()->orderBy('id')->get()->map->getAttributes()->all();
    $beforePartials = PartialReconcile::orderBy('id')->get()->map->getAttributes()->all();
    $beforeFulls = FullReconcile::orderBy('id')->get()->map->getAttributes()->all();

    $page = Livewire::test(EditPayment::class, ['record' => $this->payment->id])
        ->assertOk()
        ->callAction(ResetToDraftAction::class);

    expect($this->payment->fresh()->getAttributes())->toBe($beforePayment)
        ->and($this->payment->move->fresh()->getAttributes())->toBe($beforeMove)
        ->and($this->payment->move->lines()->orderBy('id')->get()->map->getAttributes()->all())->toBe($beforeLines)
        ->and(PartialReconcile::orderBy('id')->get()->map->getAttributes()->all())->toBe($beforePartials)
        ->and(FullReconcile::orderBy('id')->get()->map->getAttributes()->all())->toBe($beforeFulls);
    $page->assertNotified(Notification::make()->warning()->body(__('accounts::system.reconciliation.bank-match')));
});

it('refuses native draft payment cancellation when its linked journal is posted', function (): void {
    $this->payment->updateQuietly(['state' => PaymentStatus::DRAFT]);
    $moveId = $this->payment->move_id;
    $partials = PartialReconcile::count();

    Livewire::test(EditPayment::class, ['record' => $this->payment->id])
        ->assertOk()
        ->callAction(CancelAction::class);

    expect(Move::whereKey($moveId)->exists())->toBeTrue()
        ->and(Move::findOrFail($moveId)->state)->toBe(MoveState::POSTED)
        ->and($this->payment->fresh()->state)->toBe(PaymentStatus::DRAFT)
        ->and(PartialReconcile::count())->toBe($partials);
});

it('resets and then cancels a payment while retaining its previously posted journal history', function (): void {
    $this->payment->journal->update(['default_account_id' => $this->payment->outstanding_account_id]);
    $moveId = $this->payment->move_id;
    Livewire::test(EditPayment::class, ['record' => $this->payment->id])
        ->callAction(ResetToDraftAction::class);

    expect($this->payment->refresh()->state)->toBe(PaymentStatus::DRAFT)
        ->and($this->payment->is_matched)->toBeFalse()
        ->and($this->payment->is_reconciled)->toBeFalse()
        ->and(Move::findOrFail($moveId)->state)->toBe(MoveState::DRAFT)
        ->and((float) $this->invoice->fresh()->amount_residual)->toBe(200.0);

    Livewire::test(EditPayment::class, ['record' => $this->payment->id])
        ->callAction(CancelAction::class);

    expect($this->payment->fresh()->state)->toBe(PaymentStatus::CANCELED)
        ->and($this->payment->move_id)->toBe($moveId)
        ->and(Move::findOrFail($moveId)->state)->toBe(MoveState::CANCEL)
        ->and((bool) Move::findOrFail($moveId)->posted_before)->toBeTrue();
});

it('shows a usable warning and preserves the payment when its bank period is completed', function (): void {
    $this->company = AccountHelper::company();
    $journal = testBankJournal(true);
    $method = $this->payment->paymentMethodLine->replicate();
    $method->journal_id = $journal->id;
    $method->save();
    $payment = Payment::create([
        'company_id'             => $this->company->id,
        'journal_id'             => $journal->id,
        'payment_method_line_id' => $method->id,
        'currency_id'            => $this->company->currency_id,
        'partner_id'             => $this->invoice->partner_id,
        'outstanding_account_id' => $journal->default_account_id,
        'payment_type'           => PaymentType::RECEIVE,
        'amount'                 => 80,
        'date'                   => now()->toDateString(),
    ]);
    $payment->generateJournalEntry();
    app(MoveWorkflow::class)->post($payment->fresh()->move);
    $payment->refresh()->update(['state' => PaymentStatus::PAID]);
    BankReconciliation::create([
        ...validBankReconciliationAttributes($journal),
        'status'         => 'completed',
        'period_start'   => now()->startOfMonth()->toDateString(),
        'period_end'     => now()->endOfMonth()->toDateString(),
        'statement_date' => now()->endOfMonth()->toDateString(),
    ]);
    $before = $payment->fresh()->getAttributes();

    Livewire::test(EditPayment::class, ['record' => $payment->id])
        ->callAction(ResetToDraftAction::class)
        ->assertNotified(Notification::make()->warning()->body(__('accounts::system.move.completed-bank-period')));

    expect($payment->fresh()->getAttributes())->toBe($before)
        ->and($payment->move->fresh()->state)->toBe(MoveState::POSTED);
});

it('hides payment mutations from viewers without update permission', function (string $action, bool $draft): void {
    if ($draft) {
        $this->payment->updateQuietly(['state' => PaymentStatus::DRAFT]);
    }
    FilamentHelper::actingAs(['view_any_account_payment', 'view_account_payment']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect(PaymentResource::canView($this->payment))->toBeTrue();

    Livewire::test(ViewPayment::class, ['record' => $this->payment->id])->assertOk()->assertActionHidden($action);
})->with([[CancelAction::class, true], [ResetToDraftAction::class, false], [ConfirmAction::class, true]]);

it('enforces payment update permission inside mutation callbacks', function (string $action, bool $draft): void {
    if ($draft) {
        $this->payment->updateQuietly(['state' => PaymentStatus::DRAFT]);
    }
    FilamentHelper::actingAs(['view_any_account_payment', 'view_account_payment']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $component = Livewire::test(ViewPayment::class, ['record' => $this->payment->id])->assertOk()->instance();
    $record = $component->getRecord();
    $before = $record->getAttributes();

    expect(fn () => ($action::make()->getActionFunction())($record, $component))->toThrow(AuthorizationException::class);
    expect($record->fresh()->getAttributes())->toBe($before);
})->with([[CancelAction::class, true], [ResetToDraftAction::class, false], [ConfirmAction::class, true]]);

it('rolls back native payment reset when the journal completion callback fails', function (): void {
    $beforePayment = $this->payment->fresh()->getAttributes();
    $beforeMove = $this->payment->move->fresh()->getAttributes();
    $beforePartials = PartialReconcile::orderBy('id')->get()->map->getAttributes()->all();
    $beforeFulls = FullReconcile::orderBy('id')->get()->map->getAttributes()->all();
    $dispatcher = Event::getFacadeRoot();
    Event::swap(clone $dispatcher);
    Event::listen(MoveDrafted::class, function (): void {
        throw new RuntimeException('Payment reset callback failed.');
    });
    try {
        expect(fn () => Livewire::test(EditPayment::class, ['record' => $this->payment->id])->callAction(ResetToDraftAction::class))
            ->toThrow(RuntimeException::class, 'Payment reset callback failed.');
        expect($this->payment->fresh()->getAttributes())->toBe($beforePayment)
            ->and($this->payment->move->fresh()->getAttributes())->toBe($beforeMove)
            ->and(PartialReconcile::orderBy('id')->get()->map->getAttributes()->all())->toBe($beforePartials)
            ->and(FullReconcile::orderBy('id')->get()->map->getAttributes()->all())->toBe($beforeFulls);
    } finally {
        Event::swap($dispatcher);
    }
});

it('confirms a reset payment through the native action using its retained journal', function (): void {
    $moveId = $this->payment->move_id;
    Livewire::test(EditPayment::class, ['record' => $this->payment->id])
        ->callAction(ResetToDraftAction::class)
        ->callAction(ConfirmAction::class);

    expect($this->payment->fresh()->move_id)->toBe($moveId)
        ->and($this->payment->move->fresh()->state)->toBe(MoveState::POSTED);
});

it('warns when native confirmation finds its retained journal already posted and preserves history', function (): void {
    $page = Livewire::test(EditPayment::class, ['record' => $this->payment->id])
        ->callAction(ResetToDraftAction::class)
        ->mountAction(ConfirmAction::class);
    app(MoveWorkflow::class)->post($this->payment->fresh()->move);
    $beforePayment = $this->payment->fresh()->getAttributes();
    $beforeMove = $this->payment->move->fresh()->getAttributes();
    $beforeLines = $this->payment->move->lines()->orderBy('id')->get()->map->getAttributes()->all();
    $beforePartials = PartialReconcile::orderBy('id')->get()->map->getAttributes()->all();
    $beforeFulls = FullReconcile::orderBy('id')->get()->map->getAttributes()->all();

    $page->callMountedAction()
        ->assertNotified(Notification::make()->warning()->body(__('accounts::account-manager.post-action-validate.draft-state-required')));

    expect($this->payment->fresh()->getAttributes())->toBe($beforePayment)
        ->and($this->payment->move->fresh()->getAttributes())->toBe($beforeMove)
        ->and($this->payment->move->lines()->orderBy('id')->get()->map->getAttributes()->all())->toBe($beforeLines)
        ->and(PartialReconcile::orderBy('id')->get()->map->getAttributes()->all())->toBe($beforePartials)
        ->and(FullReconcile::orderBy('id')->get()->map->getAttributes()->all())->toBe($beforeFulls);
});

it('warns and preserves a payment when its recipient bank was archived before confirming a journal', function (bool $retained): void {
    $bank = BankAccount::factory()->create(['partner_id' => $this->invoice->partner_id]);

    if ($retained) {
        Livewire::test(EditPayment::class, ['record' => $this->payment->id])
            ->callAction(ResetToDraftAction::class);
        $payment = $this->payment->fresh();
        $payment->move->update(['partner_bank_id' => $bank->id]);
        $payment->updateQuietly(['partner_bank_id' => $bank->id]);
    } else {
        $payment = Payment::create([
            'company_id'             => $this->payment->company_id,
            'journal_id'             => $this->payment->journal_id,
            'payment_method_line_id' => $this->payment->payment_method_line_id,
            'currency_id'            => $this->payment->currency_id,
            'partner_id'             => $this->invoice->partner_id,
            'partner_bank_id'        => $bank->id,
            'outstanding_account_id' => $this->payment->outstanding_account_id,
            'payment_type'           => PaymentType::RECEIVE,
            'amount'                 => 80,
            'date'                   => now()->toDateString(),
        ]);
    }

    $bank->delete();
    $beforePayment = $payment->fresh()->getAttributes();
    $beforeMove = $payment->move?->fresh()->getAttributes();
    $beforeLines = $payment->move?->lines()->orderBy('id')->get()->map->getAttributes()->all();
    $beforeMoveCount = Move::where('origin_payment_id', $payment->id)->count();
    $beforePartials = PartialReconcile::orderBy('id')->get()->map->getAttributes()->all();
    $beforeFulls = FullReconcile::orderBy('id')->get()->map->getAttributes()->all();

    Livewire::test(EditPayment::class, ['record' => $payment->id])
        ->callAction(ConfirmAction::class)
        ->assertNotified(Notification::make()->warning()->body(__('accounts::account-manager.post-action-validate.bank-archived')));

    expect($payment->fresh()->getAttributes())->toBe($beforePayment)
        ->and($payment->move?->fresh()->getAttributes())->toBe($beforeMove)
        ->and($payment->move?->lines()->orderBy('id')->get()->map->getAttributes()->all())->toBe($beforeLines)
        ->and(Move::where('origin_payment_id', $payment->id)->count())->toBe($beforeMoveCount)
        ->and(PartialReconcile::orderBy('id')->get()->map->getAttributes()->all())->toBe($beforePartials)
        ->and(FullReconcile::orderBy('id')->get()->map->getAttributes()->all())->toBe($beforeFulls);
})->with([false, true]);

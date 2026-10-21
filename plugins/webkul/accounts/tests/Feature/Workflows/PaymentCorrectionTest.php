<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\PaymentStatus;
use Webkul\Account\Enums\PaymentType;
use Webkul\Account\Events\MoveCancelled;
use Webkul\Account\Facades\Account as AccountFacade;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\Payment;
use Webkul\Account\Services\PaymentRegistrar;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;
use Webkul\Security\PermissionRegistrar;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../Helpers/AccountHelper.php';
require_once __DIR__.'/../../../../support/tests/Helpers/FilamentHelper.php';

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('accounts');
    DB::table('plugins')->updateOrInsert(['name' => 'accounts'], ['is_installed' => true, 'is_active' => true, 'updated_at' => now()]);
    Package::$plugins = Plugin::all()->keyBy('name');
    URL::resolveMissingNamedRoutesUsing(fn () => '#');
    AccountHelper::actingAsAdmin();
    $journal = AccountHelper::bankJournal();
    $this->payment = Payment::create([
        'company_id'             => AccountHelper::company()->id,
        'journal_id'             => $journal->id,
        'payment_method_line_id' => $journal->inboundPaymentMethodLines()->firstOrFail()->id,
        'currency_id'            => AccountHelper::currency()->id,
        'partner_id'             => AccountHelper::partner()->id,
        'outstanding_account_id' => $journal->default_account_id,
        'payment_type'           => PaymentType::RECEIVE,
        'amount'                 => 80,
        'date'                   => now()->toDateString(),
    ]);
});

it('cancels and resets a payment with no journal through the shared owner', function (): void {
    $cancelled = AccountFacade::cancelPayment($this->payment);
    expect($cancelled->state)->toBe(PaymentStatus::CANCELED)
        ->and($cancelled->move_id)->toBeNull();

    $draft = AccountFacade::resetToDraftPayment($cancelled);
    expect($draft->state)->toBe(PaymentStatus::DRAFT)
        ->and($draft->is_matched)->toBeFalse()
        ->and($draft->is_reconciled)->toBeFalse();
});

it('does not confirm a stale draft after the payment has been cancelled', function (): void {
    $stale = $this->payment->fresh();
    app(PaymentRegistrar::class)->cancelPayment($this->payment);

    expect(fn () => app(PaymentRegistrar::class)->postPayment($stale))->toThrow(ValidationException::class);
    expect($this->payment->fresh()->state)->toBe(PaymentStatus::CANCELED)
        ->and($this->payment->move_id)->toBeNull();
});

it('keeps direct payment confirmation state-only for create-only users', function (): void {
    FilamentHelper::actingAs(['create_account_payment']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $moves = Move::count();

    $posted = app(PaymentRegistrar::class)->postPayment($this->payment);

    expect($posted)->toBe($this->payment)
        ->and($posted->state)->toBe(PaymentStatus::PAID)
        ->and($posted->move_id)->toBeNull()
        ->and(Move::count())->toBe($moves);
});

it('reauthorizes payment correction inside the shared owner', function (string $operation): void {
    FilamentHelper::actingAs(['view_any_account_payment', 'view_account_payment']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $before = $this->payment->fresh()->getAttributes();

    expect(fn () => app(PaymentRegistrar::class)->{$operation}($this->payment))->toThrow(AuthorizationException::class);
    expect($this->payment->fresh()->getAttributes())->toBe($before);
})->with(['cancelPayment', 'resetToDraftPayment']);

it('denies correction outside the current company even for an editor', function (string $operation): void {
    $before = $this->payment->fresh()->getAttributes();
    $this->partialMock(CompanyContext::class, function ($mock): void {
        $mock->shouldReceive('activeIds')->andReturn([]);
    });

    expect(fn () => app(PaymentRegistrar::class)->{$operation}($this->payment))
        ->toThrow(AuthorizationException::class, __('accounts::system.payment.company'));
    expect(Payment::withoutGlobalScopes()->findOrFail($this->payment->id)->getAttributes())->toBe($before);
})->with(['cancelPayment', 'resetToDraftPayment']);

it('rejects payment correction on another connection without changing the payment', function (): void {
    config(['database.connections.payment_correction_other' => config('database.connections.'.DB::getDefaultConnection())]);
    $other = $this->payment->fresh()->setConnection('payment_correction_other');

    expect(fn () => app(PaymentRegistrar::class)->cancelPayment($other))->toThrow(ValidationException::class);
    expect($this->payment->fresh()->state)->toBe(PaymentStatus::DRAFT);
});

it('rejects a journal linked to a different payment before correction', function (): void {
    $this->payment->generateJournalEntry();
    $this->payment->refresh()->updateQuietly(['state' => PaymentStatus::DRAFT]);
    $this->payment->move->updateQuietly(['origin_payment_id' => null]);

    expect(fn () => app(PaymentRegistrar::class)->cancelPayment($this->payment))->toThrow(ValidationException::class);
    expect($this->payment->fresh()->state)->toBe(PaymentStatus::DRAFT)
        ->and($this->payment->move->fresh()->state)->toBe(MoveState::DRAFT);
});

it('rolls back payment cancellation and callback writes when journal cancellation fails late', function (): void {
    $this->payment->generateJournalEntry();
    $this->payment->refresh()->updateQuietly(['state' => PaymentStatus::DRAFT]);
    $beforePayment = $this->payment->fresh()->getAttributes();
    $beforeMove = $this->payment->move->fresh()->getAttributes();
    $beforePartner = $this->payment->partner->getAttributes();
    $dispatcher = Event::getFacadeRoot();
    Event::swap(clone $dispatcher);
    Event::listen(MoveCancelled::class, function (): void {
        $this->payment->partner()->update(['name' => 'Failed payment cancellation']);
        throw new RuntimeException('Payment cancel callback failed.');
    });
    try {
        expect(fn () => app(PaymentRegistrar::class)->cancelPayment($this->payment))
            ->toThrow(RuntimeException::class, 'Payment cancel callback failed.');
        expect($this->payment->fresh()->getAttributes())->toBe($beforePayment)
            ->and($this->payment->move->fresh()->getAttributes())->toBe($beforeMove)
            ->and($this->payment->partner->fresh()->getAttributes())->toBe($beforePartner);
    } finally {
        Event::swap($dispatcher);
    }
});

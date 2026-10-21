<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\URL;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Enums\PaymentState;
use Webkul\Account\Events\MovePaid;
use Webkul\Account\Models\FullReconcile;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Models\PartialReconcile;
use Webkul\Account\Models\Payment;
use Webkul\Account\Models\PaymentRegister;
use Webkul\Account\Services\PaymentRegistrar;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\PermissionRegistrar;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../Helpers/AccountHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('accounts');
    DB::table('plugins')->updateOrInsert(['name' => 'accounts'], ['is_installed' => true, 'is_active' => true, 'updated_at' => now()]);
    Package::$plugins = Plugin::all()->keyBy('name');
    URL::resolveMissingNamedRoutesUsing(fn () => '#');
    AccountHelper::actingAsAdmin();
    $this->invoice = AccountHelper::invoice(MoveType::OUT_INVOICE, AccountHelper::partner());
    AccountHelper::productLine($this->invoice, AccountHelper::account('income'), qty: 2, priceUnit: 100);
    AccountHelper::post($this->invoice);
    Event::fake([MovePaid::class]);
});

it('rolls back payment, journal, matching and residual effects when direct registration fails late', function () {
    $counts = [Payment::count(), Move::count(), MoveLine::count(), PartialReconcile::count(), FullReconcile::count()];
    $invoiceId = $this->invoice->id;
    $lateFailureReached = false;
    Payment::saved(function (Payment $payment) use ($invoiceId, &$lateFailureReached) {
        if (Move::find($invoiceId)->matchedPayments()->whereKey($payment->id)->exists()) {
            $lateFailureReached = true;
            throw new RuntimeException('Payment finalization failed');
        }
    });

    expect(fn () => AccountHelper::pay($this->invoice))->toThrow(RuntimeException::class, 'Payment finalization failed');
    expect($lateFailureReached)->toBeTrue()
        ->and([Payment::count(), Move::count(), MoveLine::count(), PartialReconcile::count(), FullReconcile::count()])->toBe($counts)
        ->and($this->invoice->matchedPayments()->count())->toBe(0)
        ->and((float) $this->invoice->refresh()->amount_residual)->toBe(200.0)
        ->and($this->invoice->payment_state)->toBe(PaymentState::NOT_PAID)
        ->and((float) $this->invoice->paymentTermLines->first()->amount_residual)->toBe(200.0);
    Event::assertNotDispatched(MovePaid::class);
});

it('delivers the paid event only after its enclosing caller transaction commits', function () {
    DB::transaction(function () {
        AccountHelper::pay($this->invoice);
        expect((float) $this->invoice->refresh()->amount_residual)->toBe(0.0);
        Event::assertNotDispatched(MovePaid::class);
    });

    Event::assertDispatchedTimes(MovePaid::class, 1);
    expect($this->invoice->matchedPayments()->count())->toBe(1);
});

it('discards the paid event and payment when the enclosing caller rolls back', function () {
    expect(fn () => DB::transaction(function () {
        AccountHelper::pay($this->invoice);
        throw new RuntimeException('Caller failed');
    }))->toThrow(RuntimeException::class, 'Caller failed');

    Event::assertNotDispatched(MovePaid::class);
    expect($this->invoice->matchedPayments()->count())->toBe(0)
        ->and((float) $this->invoice->refresh()->amount_residual)->toBe(200.0);
});

it('rejects records on a separate connection before creating payment effects', function (string $record) {
    config(['database.connections.payment_atomicity_other' => config('database.connections.'.DB::getDefaultConnection())]);
    $register = new PaymentRegister;
    if ($record === 'register') {
        $register->setConnection('payment_atomicity_other');
    } else {
        $register->setRelation('lines', collect([(new MoveLine)->setConnection('payment_atomicity_other')]));
    }
    $payments = Payment::count();

    expect(fn () => app(PaymentRegistrar::class)->register($register))
        ->toThrow(InvalidArgumentException::class, 'Payment registration requires all records to use the Accounts database connection.');
    expect(Payment::count())->toBe($payments);
    Event::assertNotDispatched(MovePaid::class);
})->with(['register', 'line']);

it('completes a direct registration and dispatches its paid event once', function () {
    AccountHelper::pay($this->invoice);

    Event::assertDispatchedTimes(MovePaid::class, 1);
    Event::assertDispatched(MovePaid::class, fn (MovePaid $event) => $event->move->id === $this->invoice->id
        && $event->move->payment_state === PaymentState::PAID);
    expect((float) $this->invoice->refresh()->amount_residual)->toBe(0.0)
        ->and($this->invoice->matchedPayments()->count())->toBe(1);
});

it('reports a notification failure without presenting a committed payment as failed', function () {
    Exceptions::fake();
    Event::getFacadeRoot()->except([MovePaid::class]);
    Event::listen(MovePaid::class, function () {
        throw new RuntimeException('Payment notification unavailable');
    });

    AccountHelper::pay($this->invoice);

    expect((float) $this->invoice->refresh()->amount_residual)->toBe(0.0)
        ->and($this->invoice->matchedPayments()->count())->toBe(1);
    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'Payment notification unavailable');
});

it('replays a persisted register without another payment or notification', function (): void {
    AccountHelper::pay($this->invoice, 60);
    $register = PaymentRegister::query()->latest('id')->firstOrFail();
    $payments = Payment::count();

    app(PaymentRegistrar::class)->register($register);

    expect(Payment::count())->toBe($payments)
        ->and((float) $this->invoice->refresh()->amount_residual)->toBe(140.0);
    Event::assertDispatchedTimes(MovePaid::class, 1);
});

it('rejects a changed persisted payment instruction without additional effects', function (): void {
    AccountHelper::pay($this->invoice, 60);
    $register = PaymentRegister::query()->latest('id')->firstOrFail();
    $register->amount = 70;
    $payments = Payment::count();

    expect(fn () => app(PaymentRegistrar::class)->register($register))->toThrow(InvalidArgumentException::class);
    expect(Payment::count())->toBe($payments);
});

it('rejects a new payment exceeding the remaining invoice amount', function (): void {
    AccountHelper::pay($this->invoice, 120);
    $payments = Payment::count();

    expect(fn () => AccountHelper::pay($this->invoice, 120))->toThrow(InvalidArgumentException::class);
    expect(Payment::count())->toBe($payments)
        ->and((float) $this->invoice->refresh()->amount_residual)->toBe(80.0);
});

it('permits separate partial payment instructions within the remaining amount', function (): void {
    AccountHelper::pay($this->invoice, 60);
    AccountHelper::pay($this->invoice, 140);

    expect((float) $this->invoice->refresh()->amount_residual)->toBe(0.0)
        ->and($this->invoice->matchedPayments()->count())->toBe(2);
});

it('returns the original request result and rejects a changed request payload', function (): void {
    AccountHelper::pay($this->invoice, 20);
    $template = PaymentRegister::query()->latest('id')->firstOrFail();
    $data = [...Arr::only($template->getAttributes(), $template->getFillable()), 'amount' => 60];
    $lineIds = $template->lines()->pluck('accounts_account_move_lines.id')->all();
    $service = app(PaymentRegistrar::class);
    $key = 'request-'.fake()->uuid();
    $intent = ['amount' => 60];
    $ids = $service->registerForMove($this->invoice, $data, $lineIds, $key, $intent);
    $payments = Payment::count();

    expect($service->registerForMove($this->invoice, $data, $lineIds, $key, $intent))->toBe($ids)
        ->and($service->replayRequest($this->invoice, $key, $intent))->toBe($ids)
        ->and(fn () => $service->registerForMove($this->invoice, [...$data, 'amount' => 70], $lineIds, $key, $intent))->toThrow(InvalidArgumentException::class, 'arguments conflict')
        ->and(fn () => $service->registerForMove($this->invoice, $data, [], $key, $intent))->toThrow(InvalidArgumentException::class, 'source lines')
        ->and(fn () => $service->replayRequest($this->invoice, $key, ['amount' => 70]))->toThrow(InvalidArgumentException::class, 'different instruction')
        ->and(Payment::count())->toBe($payments)
        ->and((float) $this->invoice->refresh()->amount_residual)->toBe(120.0);
});

it('reauthorizes a completed instruction before replaying its result', function (): void {
    AccountHelper::pay($this->invoice, 60);
    $register = PaymentRegister::query()->latest('id')->firstOrFail();
    $payments = Payment::count();
    auth()->logout();

    expect(fn () => app(PaymentRegistrar::class)->register($register))->toThrow(AuthorizationException::class);
    expect(Payment::withoutGlobalScopes()->count())->toBe($payments);
});

it('does not guess an unverified historical registration outcome', function (): void {
    AccountHelper::pay($this->invoice, 60);
    $register = PaymentRegister::query()->latest('id')->firstOrFail();
    DB::table('accounts_payment_registers')->where('id', $register->id)->update(['registration_state' => 'legacy_unverified']);
    $payments = Payment::count();

    expect(fn () => app(PaymentRegistrar::class)->register($register))->toThrow(InvalidArgumentException::class, 'Historical payment instruction');
    expect(Payment::count())->toBe($payments);
});

it('replays payment API requests after the source is fully paid', function (MoveType $moveType, string $route, string $permission): void {
    if ($moveType !== MoveType::OUT_INVOICE) {
        $this->invoice = AccountHelper::invoice($moveType, AccountHelper::partner());
        AccountHelper::productLine($this->invoice, AccountHelper::account(in_array($moveType, [MoveType::IN_INVOICE, MoveType::IN_REFUND]) ? 'expense' : 'income'), qty: 2, priceUnit: 100);
        AccountHelper::post($this->invoice);
    }
    require_once base_path('plugins/webkul/support/tests/Helpers/SecurityHelper.php');
    $apiUser = SecurityHelper::authenticateWithPermissions([$permission, 'create_account_payment']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $apiUser->forceFill(['resource_permission' => PermissionType::GLOBAL])->saveQuietly();
    AccountHelper::bankJournal();
    $payload = ['amount' => 200, 'idempotency_key' => 'api-'.fake()->uuid()];
    $url = route('admin.api.v1.accounts.'.$route.'.pay', ['id' => $this->invoice->id]);

    $first = $this->postJson($url, $payload)->assertOk();
    $payments = Payment::count();
    $second = $this->postJson($url, $payload)->assertOk();

    expect($second->json('payment_ids'))->toBe($first->json('payment_ids'))
        ->and($first->json('payment_ids'))->toHaveCount(1)
        ->and(Payment::count())->toBe($payments)
        ->and((float) $this->invoice->refresh()->amount_residual)->toBe(0.0);
    $this->postJson($url, [...$payload, 'amount' => 150])->assertUnprocessable();
    expect(Payment::count())->toBe($payments);
    $apiUser->syncPermissions([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->postJson($url, $payload)->assertForbidden();
})->with([
    'invoice'     => [MoveType::OUT_INVOICE, 'invoices', 'update_account_invoice'],
    'bill'        => [MoveType::IN_INVOICE, 'bills', 'update_account_bill'],
    'credit note' => [MoveType::OUT_REFUND, 'credit-notes', 'update_account_refund'],
    'refund'      => [MoveType::IN_REFUND, 'refunds', 'update_account_refund'],
]);

it('rejects source lines from another move even within the same company', function (): void {
    AccountHelper::pay($this->invoice, 20);
    $template = PaymentRegister::query()->latest('id')->firstOrFail();
    $data = [...Arr::only($template->getAttributes(), $template->getFillable()), 'amount' => 60];
    $other = AccountHelper::invoice(MoveType::OUT_INVOICE, AccountHelper::partner());
    $lineIds = $template->lines()->pluck('accounts_account_move_lines.id')->all();
    $payments = Payment::count();
    expect(fn () => app(PaymentRegistrar::class)->registerForMove($other, $data, $lineIds, null, ['amount' => 60]))
        ->toThrow(InvalidArgumentException::class, 'source lines');
    expect(Payment::count())->toBe($payments);
});

it('rejects a partial instruction when batch construction would post a larger amount', function (): void {
    AccountHelper::pay($this->invoice, 20);
    $template = PaymentRegister::query()->latest('id')->firstOrFail();
    $other = AccountHelper::invoice(MoveType::OUT_INVOICE, AccountHelper::partner());
    AccountHelper::productLine($other, AccountHelper::account('income'), qty: 1, priceUnit: 50);
    AccountHelper::post($other);
    $register = PaymentRegister::create([...Arr::only($template->getAttributes(), $template->getFillable()), 'amount' => 60]);
    $lineIds = [...$template->lines()->pluck('accounts_account_move_lines.id')->all(), ...$other->paymentTermLines->pluck('id')->all()];
    $register->lines()->sync($lineIds);
    $register->refresh();
    $payments = Payment::count();
    expect(fn () => app(PaymentRegistrar::class)->register($register))->toThrow(InvalidArgumentException::class, 'batches must total');
    expect(Payment::count())->toBe($payments)
        ->and((float) $this->invoice->refresh()->amount_residual)->toBe(180.0)
        ->and((float) $other->refresh()->amount_residual)->toBe(50.0);
});

it('rejects completed registration replay outside the active company', function (): void {
    AccountHelper::pay($this->invoice, 60);
    $register = PaymentRegister::query()->latest('id')->firstOrFail();
    $payments = Payment::count();
    $this->partialMock(CompanyContext::class, function ($mock): void {
        $mock->shouldReceive('activeIds')->andReturn([]);
    });
    expect(fn () => app(PaymentRegistrar::class)->register($register))->toThrow(AuthorizationException::class, 'active company');
    expect(Payment::withoutGlobalScopes()->count())->toBe($payments);
});

it('registers full same-currency batches for different partners and replays their result', function (): void {
    AccountHelper::pay($this->invoice, 20);
    $template = PaymentRegister::query()->latest('id')->firstOrFail();
    $other = AccountHelper::invoice(MoveType::OUT_INVOICE, AccountHelper::partner());
    AccountHelper::productLine($other, AccountHelper::account('income'), qty: 1, priceUnit: 50);
    AccountHelper::post($other);
    $register = PaymentRegister::create([...Arr::only($template->getAttributes(), $template->getFillable()), 'amount' => 230]);
    $register->lines()->sync([...$template->lines()->pluck('accounts_account_move_lines.id')->all(), ...$other->paymentTermLines->pluck('id')->all()]);
    $register->refresh();
    $service = app(PaymentRegistrar::class);
    $ids = $service->register($register);
    expect($ids)->toHaveCount(2)
        ->and((float) Payment::whereKey($ids)->sum('amount'))->toBe(230.0)
        ->and((float) $this->invoice->refresh()->amount_residual)->toBe(0.0)
        ->and((float) $other->refresh()->amount_residual)->toBe(0.0)
        ->and($service->register($register->fresh()))->toBe($ids);
});

it('keeps request keys case sensitive on both engines', function (): void {
    AccountHelper::pay($this->invoice, 20);
    $template = PaymentRegister::query()->latest('id')->firstOrFail();
    $data = [...Arr::only($template->getAttributes(), $template->getFillable()), 'amount' => 60];
    $lineIds = $template->lines()->pluck('accounts_account_move_lines.id')->all();
    $prefix = 'case-'.fake()->uuid();
    $service = app(PaymentRegistrar::class);

    $first = $service->registerForMove($this->invoice, $data, $lineIds, $prefix.'A', ['amount' => 60]);
    $second = $service->registerForMove($this->invoice, $data, $lineIds, $prefix.'a', ['amount' => 60]);

    expect($second)->not->toBe($first)
        ->and($service->replayRequest($this->invoice, $prefix.'A', ['amount' => 60]))->toBe($first)
        ->and($service->replayRequest($this->invoice, $prefix.'a', ['amount' => 60]))->toBe($second)
        ->and((float) $this->invoice->fresh()->amount_residual)->toBe(60.0);
});

it('removes a keyed payment outcome on outer rollback and permits a clean retry', function (): void {
    AccountHelper::pay($this->invoice, 20);
    $template = PaymentRegister::query()->latest('id')->firstOrFail();
    $data = [...Arr::only($template->getAttributes(), $template->getFillable()), 'amount' => 60];
    $lineIds = $template->lines()->pluck('accounts_account_move_lines.id')->all();
    $key = 'rollback-'.fake()->uuid();
    $service = app(PaymentRegistrar::class);
    $payments = Payment::count();

    expect(fn () => DB::transaction(function () use ($service, $data, $lineIds, $key): void {
        $service->registerForMove($this->invoice, $data, $lineIds, $key, ['amount' => 60]);
        throw new RuntimeException('Caller rejected payment');
    }))->toThrow(RuntimeException::class, 'Caller rejected payment');
    expect(Payment::count())->toBe($payments)
        ->and($service->replayRequest($this->invoice, $key, ['amount' => 60]))->toBeNull()
        ->and((float) $this->invoice->fresh()->amount_residual)->toBe(180.0);
    if (DB::getDriverName() === 'mysql') {
        expect(DB::table('accounts_payment_request_locks')->where('request_hash', hash('sha256', 'accounts.payment:'.$this->invoice->company_id.':'.$key))->exists())->toBeFalse();
    }

    $ids = $service->registerForMove($this->invoice, $data, $lineIds, $key, ['amount' => 60]);
    expect($ids)->toHaveCount(1)
        ->and(Payment::count())->toBe($payments + 1)
        ->and($service->replayRequest($this->invoice, $key, ['amount' => 60]))->toBe($ids)
        ->and((float) $this->invoice->fresh()->amount_residual)->toBe(120.0);
});

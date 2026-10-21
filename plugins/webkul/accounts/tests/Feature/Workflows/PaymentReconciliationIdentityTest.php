<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Models\Payment;
use Webkul\Account\Services\MoveWorkflow;
use Webkul\Account\Services\Reconciler;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;
use Webkul\Security\Models\User;

require_once __DIR__.'/../../Helpers/AccountHelper.php';

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('accounts');
    DB::table('plugins')->updateOrInsert(['name' => 'accounts'], ['is_installed' => true, 'is_active' => true, 'updated_at' => now()]);
    Package::$plugins = Plugin::all()->keyBy('name');
    URL::resolveMissingNamedRoutesUsing(fn () => '#');
    $this->creator = AccountHelper::actingAsAdmin();
    $this->invoice = AccountHelper::invoice();
    AccountHelper::productLine($this->invoice, AccountHelper::account('income'), qty: 1, priceUnit: 100);
    AccountHelper::post($this->invoice);
    AccountHelper::pay($this->invoice);
    $this->payment = $this->invoice->fresh()->matchedPayments()->sole();
    $this->originalPayment = $this->payment->getAttributes();
    $this->ledger = $this->payment->move->lines()->orderBy('id')->get(['id', 'account_id', 'debit', 'credit', 'balance'])->toArray();
    $this->payment->partner()->update(['property_account_receivable_id' => AccountHelper::account('receivable')->id]);
    Auth::login(User::factory()->create(['default_company_id' => AccountHelper::company()->id]));
});

it('preserves payment author and posted accounts when another user removes a match', function (): void {
    $partial = $this->invoice->fresh()->paymentTermLines->sole()->matchedCredits->sole();

    app(Reconciler::class)->unReconcile($partial);

    $payment = $this->payment->fresh();
    expect($payment->creator_id)->toBe($this->creator->id)
        ->and($payment->destination_account_id)->toBe($this->originalPayment['destination_account_id'])
        ->and($payment->is_reconciled)->toBeFalse()
        ->and((float) $this->invoice->fresh()->amount_residual)->toBe(100.0)
        ->and($payment->move->state)->toBe(MoveState::POSTED)
        ->and($payment->move->lines()->orderBy('id')->get(['id', 'account_id', 'debit', 'credit', 'balance'])->toArray())->toBe($this->ledger);
});

it('does not rewrite posted payment metadata when saving a nonfinancial change', function (): void {
    $this->payment->refresh()->update(['memo' => 'Reviewed by another user']);

    $payment = $this->payment->fresh();
    expect($payment->creator_id)->toBe($this->creator->id)
        ->and($payment->destination_account_id)->toBe($this->originalPayment['destination_account_id'])
        ->and($payment->memo)->toBe('Reviewed by another user')
        ->and($payment->move->lines()->orderBy('id')->get(['id', 'account_id', 'debit', 'credit', 'balance'])->toArray())->toBe($this->ledger);
});

it('uses the persisted payment account when its preloaded draft journal has since been posted', function (): void {
    Auth::login($this->creator);
    $payment = Payment::create($this->payment->only([
        'journal_id', 'company_id', 'payment_method_line_id', 'currency_id',
        'partner_id', 'outstanding_account_id', 'payment_type', 'amount', 'date',
    ]));
    $payment->generateJournalEntry();
    $stale = $payment->fresh('move');
    expect($stale->move->state)->toBe(MoveState::DRAFT);

    app(MoveWorkflow::class)->post($payment->fresh()->move);
    $accountId = $payment->fresh()->destination_account_id;
    $payment->partner()->update(['property_account_receivable_id' => AccountHelper::account('receivable')->id]);
    Auth::login(User::factory()->create(['default_company_id' => AccountHelper::company()->id]));
    $stale->update(['memo' => 'Reviewed after posting']);

    expect($payment->fresh()->destination_account_id)->toBe($accountId)
        ->and($payment->fresh()->creator_id)->toBe($this->creator->id)
        ->and($payment->fresh()->move->state)->toBe(MoveState::POSTED);
});

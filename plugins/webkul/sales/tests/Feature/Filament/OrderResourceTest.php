<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Webkul\Account\Models\PaymentTerm;
use Webkul\Inventory\Enums\DeliveryStep;
use Webkul\Inventory\Enums\MoveState;
use Webkul\Inventory\Enums\ReceptionStep;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;
use Webkul\Sale\Enums\AdvancedPayment;
use Webkul\Sale\Enums\InvoiceStatus;
use Webkul\Sale\Enums\OrderState;
use Webkul\Sale\Filament\Clusters\Orders\Resources\OrderResource\Pages\CreateOrder;
use Webkul\Sale\Filament\Clusters\Orders\Resources\OrderResource\Pages\EditOrder;
use Webkul\Sale\Filament\Clusters\Orders\Resources\OrderResource\Pages\ListOrders;
use Webkul\Sale\Filament\Clusters\Orders\Resources\OrderResource\Pages\ViewOrder;
use Webkul\Sale\Filament\Clusters\Orders\Resources\QuotationResource\Actions\ConfirmAction;
use Webkul\Sale\Filament\Clusters\Orders\Resources\QuotationResource\Actions\CreateInvoiceAction;
use Webkul\Sale\Filament\Clusters\Orders\Resources\QuotationResource\Actions\LockAndUnlockAction;
use Webkul\Sale\Filament\Clusters\Orders\Resources\QuotationResource\Pages\EditQuotation;
use Webkul\Sale\Models\Order;
use Webkul\Sale\Models\Partner;
use Webkul\Sale\Services\OrderSourceRevision;
use Webkul\Security\Models\User;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../../../support/tests/Helpers/FilamentHelper.php';
require_once __DIR__.'/../../../../support/tests/Helpers/CompanyHelper.php';
require_once __DIR__.'/../../Helpers/SaleHelper.php';
require_once __DIR__.'/../../../../inventories/tests/Helpers/InventoryHelper.php';

beforeEach(function () {
    foreach (['inventories', 'sales', 'accounts'] as $plugin) {
        TestBootstrapHelper::ensurePluginInstalled($plugin);

        DB::table('plugins')->updateOrInsert(
            ['name' => $plugin],
            ['is_installed' => true, 'is_active' => true, 'updated_at' => now()],
        );
    }

    Package::$plugins = Plugin::all()->keyBy('name');

    URL::resolveMissingNamedRoutesUsing(fn () => '#');
});

function saleOrderRecord(?int $userId = null): Order
{
    $actor = auth()->user();
    $guard = auth()->getDefaultDriver();
    auth()->shouldUse('web');
    $admin = User::query()->firstOrFail();
    auth()->guard('web')->setUser($admin);
    auth()->guard('sanctum')->setUser($admin);
    app()->forgetInstance(CompanyContext::class);
    $product = SaleHelper::product();

    $order = SaleHelper::order([
        'state'          => OrderState::SALE,
        'invoice_status' => InvoiceStatus::TO_INVOICE,
        'user_id'        => $admin->id,
    ]);

    SaleHelper::line($order, $product, qty: 2, priceUnit: 100);
    $order->update(['user_id' => $userId ?? $actor->id]);
    auth()->guard('web')->setUser($actor);
    auth()->guard('sanctum')->setUser($actor);
    auth()->shouldUse($guard);
    app()->forgetInstance(CompanyContext::class);

    return $order->refresh();
}

it('forbids listing sale orders without permission', function () {
    FilamentHelper::actingAs([]);

    Livewire::test(ListOrders::class)->assertForbidden();
});

it('lists sale orders with their key columns for authorized users', function () {
    $user = FilamentHelper::actingAs(['view_any_sale_order']);

    $order = saleOrderRecord($user->id);

    Livewire::test(ListOrders::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$order])
        ->assertCanRenderTableColumn('name')
        ->assertCanRenderTableColumn('partner.name')
        ->assertCanRenderTableColumn('state')
        ->assertCanRenderTableColumn('amount_total');
});

it('finds a sale order by searching its number', function () {
    $user = FilamentHelper::actingAs(['view_any_sale_order']);

    $order = saleOrderRecord($user->id);

    Livewire::test(ListOrders::class)
        ->searchTable($order->name)
        ->assertCanSeeTableRecords([$order]);
});

it('renders the sale order create page', function () {
    FilamentHelper::actingAs(['view_any_sale_order', 'create_sale_order']);

    Livewire::test(CreateOrder::class)->assertOk();
});

it('creates and confirms a sales order through the create form', function () {
    $user = FilamentHelper::actingAs(['view_any_sale_order', 'create_sale_order']);

    $partner = Partner::factory()->create(['customer_rank' => 1]);
    $company = $user->defaultCompany;
    $product = SaleHelper::product();

    Livewire::test(CreateOrder::class)
        ->fillForm([
            'partner_id'      => $partner->id,
            'company_id'      => $company->id,
            'currency_id'     => $company->currency_id,
            'date_order'      => now(),
            'validity_date'   => now()->addDays(30),
            'payment_term_id' => PaymentTerm::query()->value('id'),
            'products'        => [[
                'product_id'  => $product->id, 'name' => $product->name, 'product_uom_id' => $product->uom_id,
                'product_qty' => 2, 'product_uom_qty' => 2, 'price_unit' => 100, 'customer_lead' => 0,
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Order::query()
        ->where('partner_id', $partner->id)
        ->where('company_id', $company->id)
        ->where('state', OrderState::SALE)
        ->exists())->toBeTrue();
});

it('only offers customer parties in the sales order customer dropdown', function () {
    FilamentHelper::actingAs(['view_any_sale_order', 'create_sale_order']);

    $customer = Partner::factory()->create([
        'customer_rank' => 1,
        'parent_id'     => null,
    ]);

    $internalUserPartner = Partner::factory()->create([
        'customer_rank' => 0,
        'parent_id'     => null,
    ]);

    $contact = Partner::factory()->create([
        'customer_rank' => 1,
        'parent_id'     => $customer->id,
    ]);

    $options = Livewire::test(CreateOrder::class)
        ->instance()
        ->getSchemaComponent('form.partner_id')
        ->getOptions();

    expect(array_keys($options))->toContain($customer->id)
        ->and(array_keys($options))->not->toContain($internalUserPartner->id)
        ->and(array_keys($options))->not->toContain($contact->id);
});

it('confirms a draft quotation into a sale order through the action', function () {
    $user = FilamentHelper::actingAs(['view_any_sale_quotation', 'update_sale_quotation']);

    $product = SaleHelper::product();
    $order = SaleHelper::order(['user_id' => $user->id]);
    SaleHelper::line($order, $product, qty: 2, priceUnit: 100);

    Livewire::test(EditQuotation::class, ['record' => $order->id])
        ->assertOk()
        ->callAction(ConfirmAction::class);

    expect($order->refresh()->state)->toBe(OrderState::SALE);
});

it('creates an invoice from a confirmed sale order through the action', function () {
    $user = FilamentHelper::actingAs(['view_any_sale_order', 'view_sale_order']);

    $order = saleOrderRecord($user->id);

    Livewire::test(ViewOrder::class, ['record' => $order->id])
        ->assertOk()
        ->assertActionExists(CreateInvoiceAction::class)
        ->callAction(CreateInvoiceAction::class, data: [
            'advance_payment_method' => AdvancedPayment::DELIVERED->value,
        ]);

    expect($order->refresh()->accountMoves()->count())->toBeGreaterThan(0);
});

it('requires a customer to create a sale order', function () {
    FilamentHelper::actingAs(['view_any_sale_order', 'create_sale_order']);

    Livewire::test(CreateOrder::class)
        ->fillForm([
            'partner_id' => null,
            'date_order' => now(),
        ])
        ->call('create')
        ->assertHasFormErrors(['partner_id']);
});

it('lists the invoice status column of a sale order', function () {
    $user = FilamentHelper::actingAs(['view_any_sale_order']);

    $order = saleOrderRecord($user->id);

    Livewire::test(ListOrders::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$order])
        ->assertCanRenderTableColumn('amount_total')
        ->assertTableColumnExists('invoice_status');
});

it('offers the lock action on a confirmed sale order', function () {
    $user = FilamentHelper::actingAs(['view_any_sale_order', 'view_sale_order']);

    $order = saleOrderRecord($user->id);

    Livewire::test(ViewOrder::class, ['record' => $order->id])
        ->assertOk()
        ->assertActionVisible(LockAndUnlockAction::class)
        ->assertActionHidden(ConfirmAction::class);
});

it('lists a sale order to a user of the owning company', function () {
    FilamentHelper::actingAsCompanyUser(SaleHelper::company(), ['view_any_sale_order']);

    $order = saleOrderRecord();

    Livewire::test(ListOrders::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$order]);
});

it('hides a sale order owned by another company from the list', function () {
    FilamentHelper::actingAsCompanyUser(SaleHelper::company(), ['view_any_sale_order']);

    $order = saleOrderRecord();

    FilamentHelper::actingAsCompanyUser(CompanyHelper::company(), ['view_any_sale_order']);

    Livewire::test(ListOrders::class)
        ->assertOk()
        ->assertCanNotSeeTableRecords([$order]);
});

it('refuses to open a sale order owned by another company', function () {
    FilamentHelper::actingAsCompanyUser(SaleHelper::company(), ['view_any_sale_order']);

    $order = saleOrderRecord();

    FilamentHelper::actingAsCompanyUser(CompanyHelper::company(), [
        'view_any_sale_order',
        'view_sale_order',
    ]);

    expect(Order::query()->whereKey($order->id)->exists())->toBeFalse();
    Livewire::test(ViewOrder::class, ['record' => $order->id])->assertNotFound();
});

it('keeps a second editors typed changes when its original source revision is stale', function (): void {
    $user = FilamentHelper::actingAs(['view_any_sale_order', 'view_sale_order', 'update_sale_order']);
    $order = saleOrderRecord($user->id);
    $order->partner->update(['customer_rank' => 1]);
    $order->update(['payment_term_id' => PaymentTerm::query()->value('id')]);
    $line = $order->lines()->sole();
    $first = Livewire::test(EditOrder::class, ['record' => $order->id])->assertOk();
    $second = Livewire::test(EditOrder::class, ['record' => $order->id])->assertOk();
    $originalToken = $second->get('expectedSourceRevision');
    $firstProducts = $first->get('data.products');
    $key = array_key_first($firstProducts);
    $first->set("data.products.{$key}.product_qty", 3)
        ->call('save')->assertHasNoFormErrors();
    $savedNote = $order->fresh()->note;
    $savedMoves = $line->inventoryMoves()->get()->toArray();
    $secondProducts = $second->get('data.products');
    $secondKey = array_key_first($secondProducts);
    $second->set("data.products.{$secondKey}.product_qty", 4)
        ->set('data.note', 'Preserve this unsaved note')
        ->call('save')->assertHasFormErrors(['products']);
    expect((float) $line->fresh()->product_qty)->toBe(3.0)
        ->and($line->quantityCommitments()->count())->toBe(2)
        ->and($order->fresh()->note)->toBe($savedNote)
        ->and($line->inventoryMoves()->get()->toArray())->toBe($savedMoves)
        ->and((float) $second->get("data.products.{$secondKey}.product_qty"))->toBe(4.0)
        ->and(data_get($second->get('data.note'), 'content.0.content.0.text'))->toBe('Preserve this unsaved note')
        ->and($second->get('expectedSourceRevision'))->toBe($originalToken)
        ->and($first->get('expectedSourceRevision'))->not->toBe($originalToken);
    $firstProducts = $first->get('data.products');
    $key = array_key_first($firstProducts);
    $first->set("data.products.{$key}.product_qty", 5)
        ->call('save')->assertHasNoFormErrors();
    expect((float) $line->fresh()->product_qty)->toBe(5.0)
        ->and($line->quantityCommitments()->count())->toBe(3);
});

class SalesQuantityFeedbackEditOrder extends EditOrder
{
    public static function getResourcePageName(): string
    {
        return 'edit';
    }

    public function saveProductsComponent(): void
    {
        $this->saveFormComponentOnly($this->getSchemaComponent('form.products'));
    }
}

it('shows issued quantity errors beside the products and preserves unsaved edits', function (string $method): void {
    SaleHelper::actingAsAdmin();
    $warehouse = InventoryHelper::warehouse(ReceptionStep::ONE_STEP, DeliveryStep::ONE_STEP);
    $order = SaleHelper::confirmedOrder($warehouse, SaleHelper::product(), 10);
    $line = $order->lines()->sole();
    $user = FilamentHelper::actingAs(['view_any_sale_order', 'view_sale_order', 'update_sale_order']);
    $order->partner->update(['customer_rank' => 1]);
    $order->update(['user_id' => $user->id, 'payment_term_id' => PaymentTerm::query()->value('id')]);
    auth()->guard('web')->setUser($user);
    auth()->shouldUse('web');
    $component = Livewire::test(SalesQuantityFeedbackEditOrder::class, ['record' => $order->id])->assertOk();
    $token = $component->get('expectedSourceRevision');
    // The warehouse can issue goods while an editor still has the earlier delivery count on screen.
    SaleHelper::actingAsAdmin();
    SaleHelper::partialDeliver($order, 6);
    auth()->guard('web')->setUser($user);
    auth()->guard('sanctum')->setUser($user);
    auth()->shouldUse('web');
    app()->forgetInstance(CompanyContext::class);
    expect(app(OrderSourceRevision::class)->token($order->fresh()))->toBe($token)
        ->and((float) $line->inventoryMoves()->where('state', MoveState::DONE)->sum('product_qty'))->toBe(6.0)
        ->and((float) $line->inventoryMoves()->whereNotIn('state', [MoveState::DONE, MoveState::CANCELED])->sum('product_qty'))->toBe(4.0);
    $key = array_key_first($component->get('data.products'));
    $effects = fn (): array => collect([
        'sales_orders', 'sales_order_lines', 'sales_quantity_commitments', 'inventories_operations',
        'inventories_moves', 'inventories_move_lines', 'inventories_product_quantities',
    ])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    $before = $effects();

    $component->set("data.products.{$key}.product_qty", 5)
        ->set('data.note', 'Keep this unsaved explanation')
        ->call($method)
        ->assertHasFormErrors(['products'])
        ->assertHasErrors(['data.products'])
        ->assertSee(__('sales::models/quantity-commitment.issued-quantity'));

    expect($effects())->toBe($before)
        ->and((float) $component->get("data.products.{$key}.product_qty"))->toBe(5.0)
        ->and(data_get($component->get('data.note'), 'content.0.content.0.text'))->toBe('Keep this unsaved explanation')
        ->and($component->get('expectedSourceRevision'))->toBe($token);
})->with(['whole form' => 'save', 'products only' => 'saveProductsComponent']);

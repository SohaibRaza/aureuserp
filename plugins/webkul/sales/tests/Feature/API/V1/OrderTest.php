<?php

use Illuminate\Testing\TestResponse;
use Webkul\Product\Enums\PriceRuleApplyTo;
use Webkul\Product\Enums\PriceRuleType;
use Webkul\Product\Models\PriceList;
use Webkul\Product\Models\Product;
use Webkul\Sale\Enums\OrderState;
use Webkul\Sale\Facades\SaleOrder;
use Webkul\Sale\Models\Order;
use Webkul\Sale\Models\OrderLine;
use Webkul\Sale\Services\OrderSourceRevision;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\User;
use Webkul\Support\Enums\UOMType;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;
use Webkul\Support\Models\UOM;

require_once __DIR__.'/../../../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../../../support/tests/Helpers/TestBootstrapHelper.php';

const SALES_ORDER_JSON_STRUCTURE = [
    'id',
    'partner_id',
    'payment_term_id',
    'company_id',
    'currency_id',
    'date_order',
    'state',
    'lines',
];

const SALES_ORDER_REQUIRED_FIELDS = [
    'partner_id',
    'payment_term_id',
    'date_order',
    'lines',
];

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('sales');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

function actingAsSalesOrderApiUser(array $permissions = []): User
{
    $user = SecurityHelper::authenticateWithPermissions($permissions);

    $user->forceFill([
        'resource_permission' => PermissionType::GLOBAL,
    ])->saveQuietly();

    return $user;
}

function salesOrderRoute(string $action, mixed $order = null): string
{
    $name = "admin.api.v1.sales.orders.{$action}";

    return $order ? route($name, $order) : route($name);
}

function makeLinePayload(array $context, array $overrides = []): array
{
    $line = OrderLine::factory()->make([
        'company_id'       => $context['company_id'],
        'currency_id'      => $context['currency_id'],
        'order_partner_id' => $context['partner_id'],
        'salesman_id'      => $context['user_id'],
    ])->toArray();

    return array_merge([
        'product_id'     => $line['product_id'],
        'product_qty'    => $line['product_qty'],
        'price_unit'     => $line['price_unit'],
        'product_uom_id' => $line['product_uom_id'],
    ], $overrides);
}

function salesOrderPayload(int $lineCount = 2, array $overrides = []): array
{
    $companyId = current_company_id() ?? Company::factory()->create(['currency_id' => 1])->id;

    $order = Order::factory()
        ->withPaymentTerms()
        ->make([
            'company_id'  => $companyId,
            'currency_id' => 1,
        ])
        ->toArray();

    $order['lines'] = collect(range(1, $lineCount))
        ->map(fn () => makeLinePayload([
            'company_id'  => $order['company_id'],
            'currency_id' => $order['currency_id'],
            'partner_id'  => $order['partner_id'],
            'user_id'     => $order['user_id'],
        ]))
        ->all();

    return array_replace_recursive($order, $overrides);
}

function createOrderViaApi(array $overrides = []): TestResponse
{
    return test()
        ->postJson(salesOrderRoute('store'), salesOrderPayload(overrides: $overrides))
        ->assertCreated();
}

it('requires authentication to list orders', function () {
    test()->getJson(salesOrderRoute('index'))
        ->assertUnauthorized();
});

it('forbids listing orders without permission', function () {
    actingAsSalesOrderApiUser();

    test()->getJson(salesOrderRoute('index'))
        ->assertForbidden();
});

it('creates an order with lines in a single API call', function () {
    actingAsSalesOrderApiUser(['create_sale_order']);

    $payload = salesOrderPayload(lineCount: 2);
    $response = test()->postJson(salesOrderRoute('store'), $payload);

    $response
        ->assertCreated()
        ->assertJsonPath('message', 'Order created successfully.')
        ->assertJsonPath('data.partner_id', $payload['partner_id'])
        ->assertJsonCount(2, 'data.lines')
        ->assertJsonStructure(['data' => SALES_ORDER_JSON_STRUCTURE]);

    $orderId = $response->json('data.id');

    test()->assertDatabaseHas('sales_orders', [
        'id'              => $orderId,
        'partner_id'      => $payload['partner_id'],
        'payment_term_id' => $payload['payment_term_id'],
    ]);

    expect(OrderLine::query()->where('order_id', $orderId)->count())->toBe(2);
});

it('validates required fields when creating an order', function (string $field) {
    actingAsSalesOrderApiUser(['create_sale_order']);

    $payload = salesOrderPayload();
    unset($payload[$field]);

    test()->postJson(salesOrderRoute('store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with(SALES_ORDER_REQUIRED_FIELDS);

it('shows an order for authorized users', function () {
    actingAsSalesOrderApiUser(['view_sale_order']);

    $order = Order::factory()->create();

    test()->getJson(salesOrderRoute('show', $order).'?include=lines')
        ->assertOk()
        ->assertJsonPath('data.id', $order->id)
        ->assertJsonStructure(['data' => SALES_ORDER_JSON_STRUCTURE]);
});

it('returns 404 for a non-existent order', function () {
    actingAsSalesOrderApiUser(['view_sale_order']);

    test()->getJson(salesOrderRoute('show', 999999))
        ->assertNotFound();
});

it('updates an order and syncs its lines via API payload', function () {
    actingAsSalesOrderApiUser(['create_sale_order', 'update_sale_order']);

    $order = Order::factory()->create();

    [$lineToKeepId, $lineToDeleteId] = OrderLine::factory()->count(2)->create([
        'order_id'         => $order->id,
        'company_id'       => $order->company_id,
        'currency_id'      => $order->currency_id,
        'order_partner_id' => $order->partner_id,
        'salesman_id'      => $order->user_id,
        'state'            => $order->state,
    ])->pluck('id')->values()->all();

    $updatePayload = [
        'lines' => [
            [
                'id'          => $lineToKeepId,
                'product_id'  => OrderLine::query()->find($lineToKeepId)->product_id,
                'product_qty' => 5,
                'price_unit'  => 125,
            ],
        ],
    ];

    test()->patchJson(salesOrderRoute('update', $order), $updatePayload)
        ->assertOk()
        ->assertJsonPath('message', 'Order updated successfully.')
        ->assertJsonCount(1, 'data.lines');

    test()->assertDatabaseHas('sales_order_lines', [
        'id'          => $lineToKeepId,
        'product_qty' => 5,
        'price_unit'  => 125,
    ]);

    test()->assertDatabaseMissing('sales_order_lines', ['id' => $lineToDeleteId]);

    expect(OrderLine::query()->where('order_id', $order->id)->count())->toBe(1);
});

it('forbids updating an order without permission', function () {
    actingAsSalesOrderApiUser();

    $order = Order::factory()->create();

    test()->patchJson(salesOrderRoute('update', $order), [])
        ->assertForbidden();
});

it('rejects confirming cancelled orders', function () {
    actingAsSalesOrderApiUser(['update_sale_order']);

    $order = Order::factory()->create(['state' => OrderState::CANCEL]);

    test()->postJson(salesOrderRoute('confirm', $order->id))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Only draft or sent orders can be confirmed.');
});

it('returns the original confirmed order when confirmation is replayed', function () {
    actingAsSalesOrderApiUser(['update_sale_order']);

    $order = Order::factory()->create(['state' => OrderState::SALE]);

    test()->postJson(salesOrderRoute('confirm', $order->id))
        ->assertOk()
        ->assertJsonPath('data.id', $order->id)
        ->assertJsonPath('data.state', OrderState::SALE->value);
});

it('soft deletes an order for authorized users', function () {
    actingAsSalesOrderApiUser(['delete_sale_order']);

    $order = Order::factory()->create();

    test()->deleteJson(salesOrderRoute('destroy', $order))
        ->assertOk()
        ->assertJsonPath('message', 'Order deleted successfully.');

    test()->assertSoftDeleted('sales_orders', ['id' => $order->id]);
});

it('forbids deleting an order without permission', function () {
    actingAsSalesOrderApiUser();

    $order = Order::factory()->create();

    test()->deleteJson(salesOrderRoute('destroy', $order))
        ->assertForbidden();
});

it('restores a soft deleted order for authorized users', function () {
    actingAsSalesOrderApiUser(['restore_sale_order']);

    $order = Order::factory()->create();
    $order->delete();

    test()->postJson(salesOrderRoute('restore', $order->id))
        ->assertOk()
        ->assertJsonPath('message', 'Order restored successfully.');

    test()->assertNotSoftDeleted('sales_orders', ['id' => $order->id]);
});

it('force deletes an order for authorized users', function () {
    actingAsSalesOrderApiUser(['force_delete_sale_order']);

    $order = Order::factory()->create();
    $order->delete();

    test()->deleteJson(salesOrderRoute('force-destroy', $order->id))
        ->assertOk()
        ->assertJsonPath('message', 'Order permanently deleted.');

    test()->assertDatabaseMissing('sales_orders', ['id' => $order->id]);
});

it('takes the order currency from the price list sent in the payload', function () {
    actingAsSalesOrderApiUser(['create_sale_order']);

    $otherCurrency = Currency::query()->whereKeyNot(1)->first();

    $priceList = PriceList::factory()->create(['currency_id' => $otherCurrency->id]);

    $response = createOrderViaApi(['price_list_id' => $priceList->id]);

    $response->assertJsonPath('data.price_list_id', $priceList->id)
        ->assertJsonPath('data.currency_id', $otherCurrency->id);

    expect(Order::query()->find($response->json('data.id'))->currency_id)->toBe($otherCurrency->id);
});

it('prices an order line from the price list when no unit price is sent', function () {
    actingAsSalesOrderApiUser(['create_sale_order']);

    $priceList = PriceList::factory()->create(['currency_id' => 1]);

    $priceList->items()->create([
        'apply_to'    => PriceRuleApplyTo::GLOBAL,
        'type'        => PriceRuleType::FIXED,
        'fixed_price' => 77,
    ]);

    $payload = salesOrderPayload(lineCount: 1, overrides: ['price_list_id' => $priceList->id]);

    unset($payload['lines'][0]['price_unit']);

    $response = test()
        ->postJson(salesOrderRoute('store'), $payload)
        ->assertCreated();

    expect((float) OrderLine::query()->where('order_id', $response->json('data.id'))->value('price_unit'))->toBe(77.0);
});

it('rejects removing a confirmed item through order synchronization without partial changes', function (): void {
    actingAsSalesOrderApiUser(['update_sale_order']);
    $order = Order::factory()->create(['state' => OrderState::DRAFT]);
    $product = Product::factory()->create(['company_id' => $order->company_id]);
    $first = OrderLine::factory()->create([
        'order_id'    => $order->id, 'company_id' => $order->company_id,
        'product_id'  => $product->id, 'product_uom_id' => $product->uom_id,
        'product_qty' => 10, 'product_uom_qty' => 10,
    ]);
    $omitted = OrderLine::factory()->create([
        'order_id'    => $order->id, 'company_id' => $order->company_id,
        'product_id'  => $product->id, 'product_uom_id' => $product->uom_id,
        'product_qty' => 5, 'product_uom_qty' => 5,
    ]);
    SaleOrder::confirmSaleOrder($order);

    test()->patchJson(salesOrderRoute('update', $order), [
        'expected_source_revision' => app(OrderSourceRevision::class)->token($order->fresh()),
        'note'                     => 'This change must roll back',
        'lines'                    => [[
            'id'          => $first->id, 'product_id' => $product->id,
            'product_qty' => 12, 'product_uom_id' => $product->uom_id, 'price_unit' => 100,
        ]],
    ])->assertUnprocessable()->assertJsonValidationErrors('products');

    expect($order->refresh()->note)->not->toBe('This change must roll back')
        ->and((float) $first->fresh()->product_qty)->toBe(10.0)
        ->and($omitted->fresh())->not->toBeNull()
        ->and($first->quantityCommitments()->count())->toBe(1)
        ->and($omitted->quantityCommitments()->count())->toBe(1);
});

it('preserves an unchanged carton line when a confirmed API order is resubmitted', function (bool $locked): void {
    TestBootstrapHelper::ensurePluginInstalled('inventories');
    actingAsSalesOrderApiUser(['update_sale_order', 'view_sale_order']);
    $order = Order::factory()->create(['state' => OrderState::DRAFT]);
    $product = Product::factory()->create(['company_id' => $order->company_id]);
    $stock = $product->uom;
    $carton = UOM::factory()->create([
        'category_id' => $stock->category_id, 'type' => UOMType::BIGGER,
        'factor'      => $stock->factor / 12, 'rounding' => 1,
    ]);
    $line = OrderLine::factory()->create([
        'order_id'       => $order->id, 'company_id' => $order->company_id, 'product_id' => $product->id,
        'product_uom_id' => $carton->id, 'product_qty' => 6, 'product_uom_qty' => 72,
    ]);
    SaleOrder::confirmSaleOrder($order);
    $order->refresh()->update(['locked' => $locked]);
    $moves = $line->inventoryMoves()->get()->toArray();
    test()->patchJson(salesOrderRoute('update', $order), ['lines' => [[
        'id'          => $line->id, 'product_id' => $product->id, 'product_uom_id' => $carton->id,
        'product_qty' => 6, 'price_unit' => (float) $line->price_unit,
    ]]])->assertOk();
    test()->patchJson(salesOrderRoute('update', $order), ['lines' => [[
        'id'          => $line->id, 'product_id' => $product->id,
        'product_qty' => 6, 'price_unit' => (float) $line->price_unit,
    ]]])->assertOk();
    expect((float) $line->fresh()->product_uom_qty)->toBe(72.0)
        ->and($line->fresh()->product_uom_id)->toBe($carton->id)
        ->and($line->quantityCommitments()->count())->toBe(1)
        ->and($line->inventoryMoves()->get()->toArray())->toBe($moves);
})->with(['unlocked' => false, 'locked' => true]);

it('rejects a second API client with a stale source revision before any writes', function (): void {
    actingAsSalesOrderApiUser(['update_sale_order', 'view_sale_order']);
    $order = Order::factory()->create(['state' => OrderState::DRAFT]);
    $product = Product::factory()->create(['company_id' => $order->company_id]);
    $line = OrderLine::factory()->create([
        'order_id'       => $order->id, 'company_id' => $order->company_id, 'product_id' => $product->id,
        'product_uom_id' => $product->uom_id, 'product_qty' => 10, 'product_uom_qty' => 10,
    ]);
    SaleOrder::confirmSaleOrder($order);
    $token = test()->getJson(salesOrderRoute('show', $order))->assertOk()->json('data.source_revision');
    expect($token)->toBeString();
    $payload = ['expected_source_revision' => $token, 'lines' => [[
        'id'          => $line->id, 'product_id' => $product->id, 'product_uom_id' => $product->uom_id,
        'product_qty' => 12, 'price_unit' => 100,
    ]]];
    test()->patchJson(salesOrderRoute('update', $order), $payload)->assertOk();
    $payload['lines'][0]['product_qty'] = 14;
    $payload['note'] = 'Stale edit must not persist';
    test()->patchJson(salesOrderRoute('update', $order), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('products');
    expect((float) $line->fresh()->product_qty)->toBe(12.0)
        ->and($line->quantityCommitments()->count())->toBe(2)
        ->and($order->fresh()->note)->not->toBe('Stale edit must not persist');
});

it('requires the original source revision before adding a confirmed item', function (): void {
    actingAsSalesOrderApiUser(['update_sale_order', 'view_sale_order']);
    $order = Order::factory()->create(['state' => OrderState::DRAFT]);
    $product = Product::factory()->create(['company_id' => $order->company_id]);
    $line = OrderLine::factory()->create([
        'order_id'       => $order->id, 'company_id' => $order->company_id, 'product_id' => $product->id,
        'product_uom_id' => $product->uom_id, 'product_qty' => 10, 'product_uom_qty' => 10,
    ]);
    SaleOrder::confirmSaleOrder($order);
    $payload = ['note' => 'Not accepted without a source check', 'lines' => [
        ['id' => $line->id, 'product_id' => $product->id, 'product_uom_id' => $product->uom_id, 'product_qty' => 10, 'price_unit' => 100],
        ['product_id' => $product->id, 'product_uom_id' => $product->uom_id, 'product_qty' => 2, 'price_unit' => 100],
    ]];
    test()->patchJson(salesOrderRoute('update', $order), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('products');
    expect($order->lines()->count())->toBe(1)
        ->and($line->quantityCommitments()->count())->toBe(1)
        ->and($order->fresh()->note)->not->toBe($payload['note']);
    $payload['expected_source_revision'] = test()->getJson(salesOrderRoute('show', $order))->json('data.source_revision');
    test()->patchJson(salesOrderRoute('update', $order), $payload)->assertOk();
    expect($order->lines()->count())->toBe(2)
        ->and($order->lines()->latest('id')->firstOrFail()->quantityCommitments()->count())->toBe(1);
});

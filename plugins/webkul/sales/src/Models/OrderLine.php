<?php

namespace Webkul\Sale\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Models\Tax;
use Webkul\Inventory\Enums as InventoryEnums;
use Webkul\Inventory\Models\Location;
use Webkul\Inventory\Models\Move as InventoryMove;
use Webkul\Inventory\Models\Route;
use Webkul\Inventory\Models\Rule;
use Webkul\Inventory\Models\Warehouse;
use Webkul\Partner\Models\Partner;
use Webkul\PluginManager\Package;
use Webkul\Product\Models\Packaging;
use Webkul\Product\Models\Product;
use Webkul\Sale\Database\Factories\OrderLineFactory;
use Webkul\Sale\Enums\OrderState;
use Webkul\Sale\Enums\QtyDeliveredMethod;
use Webkul\Sale\Facades\SaleOrder as SaleOrderFacade;
use Webkul\Sale\Services\QuantityCommitmentRecorder;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;
use Webkul\Support\Models\UOM;
use Webkul\Support\Traits\BelongsToCompany;
use Webkul\Support\Traits\ChecksCompanyConsistency;

class OrderLine extends Model implements Sortable
{
    use BelongsToCompany;
    use ChecksCompanyConsistency;
    use HasFactory, SortableTrait;

    protected $table = 'sales_order_lines';

    private bool $quietPersistence = false;

    protected $fillable = [
        'sort',
        'order_id',
        'company_id',
        'currency_id',
        'order_partner_id',
        'salesman_id',
        'product_id',
        'product_uom_id',
        'linked_sale_order_sale_id',
        'creator_id',
        'state',
        'display_type',
        'virtual_id',
        'linked_virtual_id',
        'qty_delivered_method',
        'invoice_status',
        'analytic_distribution',
        'name',
        'product_uom_qty',
        'price_unit',
        'discount',
        'price_subtotal',
        'price_total',
        'price_reduce_taxexcl',
        'price_reduce_taxinc',
        'qty_delivered',
        'qty_invoiced',
        'qty_to_invoice',
        'untaxed_amount_invoiced',
        'untaxed_amount_to_invoice',
        'is_downpayment',
        'is_expense',
        'create_date',
        'write_date',
        'technical_price_unit',
        'price_tax',
        'product_qty',
        'product_packaging_qty',
        'product_packaging_id',
        'customer_lead',
        'purchase_price',
        'margin',
        'margin_percent',
        'warehouse_id',
    ];

    protected $casts = [
        'state'                => OrderState::class,
        'qty_delivered_method' => QtyDeliveredMethod::class,
        'customer_lead'        => 'float',
    ];

    public $sortable = [
        'order_column_name'  => 'sort',
        'sort_when_creating' => true,
    ];

    public function quantityCommitments(): HasMany
    {
        return $this->hasMany(QuantityCommitment::class);
    }

    public function currentQuantityCommitment(): HasOne
    {
        return $this->hasOne(QuantityCommitment::class)->ofMany('revision', 'max');
    }

    public function save(array $options = []): bool
    {
        if (! $this->order_id) {
            return parent::save($options);
        }

        $attributes = $this->attributes;
        $original = $this->original;
        $changes = $this->changes;
        $existed = $this->exists;
        try {
            return $this->getConnection()->transaction(function () use ($options) {
                $order = Order::query()->lockForUpdate()->findOrFail($this->order_id);
                $stored = $this->exists ? static::query()->lockForUpdate()->findOrFail($this->getKey()) : null;
                $recorder = app(QuantityCommitmentRecorder::class);
                $significant = ! $stored || $recorder->sourceChanged($this, $stored);
                $confirmed = $order->state === OrderState::SALE;

                if ($stored && $significant && $confirmed) {
                    foreach (QuantityCommitmentRecorder::LINE_FIELDS as $field) {
                        if ($this->getRawOriginal($field) != $stored->getRawOriginal($field)) {
                            throw ValidationException::withMessages(['products' => __('sales::models/quantity-commitment.conflict')]);
                        }
                    }
                }
                if ($stored && $this->isDirty(['order_id', 'company_id'])
                    && $stored->quantityCommitments()->exists()) {
                    throw ValidationException::withMessages(['products' => __('sales::models/quantity-commitment.source')]);
                }
                if ($confirmed && $significant) {
                    Gate::authorize('update', $order);
                    if ($stored && ! $recorder->applicable($this) && $stored->quantityCommitments()->exists()) {
                        throw ValidationException::withMessages(['products' => __('sales::models/quantity-commitment.eligibility')]);
                    }
                    if ($stored && ($recorder->applicable($stored) || $recorder->applicable($this)) && ! $stored->quantityCommitments()->exists()) {
                        throw ValidationException::withMessages(['products' => __('sales::models/quantity-commitment.unverified')]);
                    }
                    $recorder->validateQuantity($this->product_qty);
                    if ($order->locked) {
                        throw ValidationException::withMessages(['products' => __('sales::models/quantity-commitment.locked')]);
                    }
                    if ($stored && $this->isDirty(['product_id', 'product_uom_id', 'product_packaging_id', 'warehouse_id', 'route_id'])
                        && ($stored->inventoryMoves()->where('state', '!=', InventoryEnums\MoveState::CANCELED)->exists() || $stored->accountMoveLines()->exists())) {
                        throw ValidationException::withMessages(['products' => __('sales::models/quantity-commitment.delivery')]);
                    }
                }
                $this->setRelation('order', $order);
                $this->state = $confirmed ? $order->state : ($this->state ?? $order->state);
                $saved = $this->quietPersistence
                    ? static::withoutEvents(fn () => parent::save($options))
                    : parent::save($options);
                if ($saved && $confirmed && $significant) {
                    app(QuantityCommitmentRecorder::class)->record($this, $order);
                    SaleOrderFacade::applyInventoryRules(collect([$this]));
                }

                return $saved;
            });
        } catch (\Throwable $error) {
            $this->attributes = $attributes;
            $this->original = $original;
            $this->changes = $changes;
            $this->exists = $existed;
            $this->unsetRelation('currentQuantityCommitment');
            throw $error;
        }
    }

    public function saveQuietly(array $options = []): bool
    {
        $this->quietPersistence = true;
        try {
            return $this->save($options);
        } finally {
            $this->quietPersistence = false;
        }
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        if (in_array($column, QuantityCommitmentRecorder::LINE_FIELDS, true)
            || array_intersect(array_keys($extra), QuantityCommitmentRecorder::LINE_FIELDS)) {
            $this->{$column} = $this->{$column} + ($method === 'increment' ? $amount : -$amount);
            $this->forceFill($extra);

            return $this->save() ? 1 : 0;
        }

        return parent::incrementOrDecrement($column, $amount, $extra, $method);
    }

    public function delete()
    {
        return $this->getConnection()->transaction(function () {
            $order = Order::query()->lockForUpdate()->findOrFail($this->order_id);
            if ($order->state === OrderState::SALE || $this->quantityCommitments()->exists()) {
                throw ValidationException::withMessages(['products' => __('sales::models/quantity-commitment.delete')]);
            }

            return parent::delete();
        });
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function orderPartner()
    {
        return $this->belongsTo(Partner::class, 'order_partner_id');
    }

    public function salesman()
    {
        return $this->belongsTo(User::class, 'salesman_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function uom()
    {
        return $this->belongsTo(UOM::class, 'product_uom_id');
    }

    public function taxes(): BelongsToMany
    {
        return $this->belongsToMany(Tax::class, 'sales_order_line_taxes', 'order_line_id', 'tax_id');
    }

    public function accountMoveLines()
    {
        return $this->belongsToMany(MoveLine::class, 'sales_order_line_invoices', 'order_line_id', 'invoice_line_id');
    }

    public function inventoryMoves(): HasMany
    {
        return $this->hasMany(InventoryMove::class, 'sale_order_line_id');
    }

    public function productPackaging()
    {
        return $this->belongsTo(Packaging::class);
    }

    public function linkedSaleOrderSale()
    {
        return $this->belongsTo(self::class, 'linked_sale_order_sale_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class, 'route_id');
    }

    public function getExpectedDateAttribute()
    {
        if ($this->state == OrderState::SALE && $this->order->date_order) {
            $orderDate = $this->order->date_order;
        } else {
            $orderDate = now();
        }

        return $orderDate->addDays($this->customer_lead ?? 0);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($orderLine) {
            $orderLine->state ??= $orderLine->order->state;

            $orderLine->creator_id ??= Auth::id();
        });

        static::saving(function ($orderLine) {
            $orderLine->computeWarehouseId();
        });

        static::created(function ($orderLine) {
            $orderLine->linkMatchingOptions();

        });

        static::updated(function ($orderLine) {
            if ($orderLine->wasChanged('product_id')) {
                OrderOption::where('line_id', $orderLine->id)->update(['line_id' => null]);

                $orderLine->linkMatchingOptions();
            }

        });
    }

    public function linkMatchingOptions(): void
    {
        if (! $this->product_id || $this->display_type) {
            return;
        }

        OrderOption::where('order_id', $this->order_id)
            ->where('product_id', $this->product_id)
            ->whereNull('line_id')
            ->update(['line_id' => $this->id]);
    }

    public function computeWarehouseId()
    {
        if ($this->exists && $this->state === OrderState::SALE && ! $this->isDirty(['route_id', 'order_id'])) {
            return;
        }

        if (! Package::isPluginInstalled('inventories')) {
            return;
        }

        if (! $this->order) {
            return;
        }

        $this->warehouse_id = $this->order->warehouse_id;

        if (! $this->route_id) {
            return;
        }

        $customerLocation = Location::where('type', InventoryEnums\LocationType::CUSTOMER)->first();

        $rules = Rule::where([
            ['destination_location_id', $customerLocation->id],
            ['action', '!=', 'push'],
            ['route_id', $this->route_id],
        ])
            ->orderBy('route_sort')
            ->orderBy('sort')
            ->get()
            ->sortBy(fn ($rule) => (! $rule->source_location_id || $rule->sourceLocation->warehouse_id === $this->order->warehouse_id) ? 0 : 1);

        if ($rules->isNotEmpty()) {
            $this->warehouse_id = $rules->first()->sourceLocation->warehouse_id;
        }
    }

    protected static function newFactory()
    {
        return OrderLineFactory::new();
    }

    public function companyConsistentFields(): array
    {
        return [
            'product_id'   => Product::class,
            'warehouse_id' => Warehouse::class,
        ];
    }
}

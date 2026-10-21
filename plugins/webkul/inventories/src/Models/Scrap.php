<?php

namespace Webkul\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Webkul\Chatter\Traits\HasChatter;
use Webkul\Chatter\Traits\HasLogActivity;
use Webkul\Field\Traits\HasCustomFields;
use Webkul\Inventory\Database\Factories\ScrapFactory;
use Webkul\Inventory\Enums\MoveState;
use Webkul\Inventory\Enums\ScrapState;
use Webkul\Inventory\Models\Concerns\ChecksCrossCompanyTransfer;
use Webkul\Inventory\Support\CrossCompanyTransferGuard;
use Webkul\Partner\Models\Partner;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\UOM;
use Webkul\Support\Services\SequenceService;
use Webkul\Support\Traits\BelongsToCompany;

class Scrap extends Model
{
    use BelongsToCompany;
    use ChecksCrossCompanyTransfer;
    use HasChatter, HasCustomFields, HasFactory, HasLogActivity;

    public const ACTIVITY_PLAN_PLUGIN = 'inventories';

    protected $table = 'inventories_scraps';

    public function save(array $options = []): bool
    {
        return $this->getConnection()->transaction(function () use ($options): bool {
            $this->lockDraftMutation();

            return parent::save($options);
        });
    }

    public function delete(): ?bool
    {
        return $this->getConnection()->transaction(function (): ?bool {
            $this->lockDraftMutation(deleting: true);

            return parent::delete();
        });
    }

    protected function lockDraftMutation(bool $deleting = false): void
    {
        if (! $this->exists) {
            return;
        }

        // Validation locks products before the scrap; keep that order for edits and deletion.
        Product::on($this->getConnectionName())
            ->withoutGlobalScopes()
            ->whereKey(array_filter([$this->product_id, $this->getRawOriginal('product_id')]))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $current = $this->newQueryWithoutScopes()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

        if ($current->state === ScrapState::DONE && ($deleting || $this->isDirty())) {
            throw ValidationException::withMessages([
                'state' => __($deleting ? 'inventories::system.scrap.done-delete' : 'inventories::system.scrap.done-update'),
            ]);
        }
    }

    protected $fillable = [
        'name',
        'origin',
        'state',
        'qty',
        'should_replenish',
        'closed_at',
        'product_id',
        'uom_id',
        'lot_id',
        'package_id',
        'partner_id',
        'operation_id',
        'source_location_id',
        'destination_location_id',
        'company_id',
        'creator_id',
    ];

    public function getModelTitle(): string
    {
        return __('inventories::models/scrap.title');
    }

    protected function getLogAttributeLabels(): array
    {
        return [
            'name'                                      => __('inventories::models/scrap.log-attributes.name'),
            'origin'                                    => __('inventories::models/scrap.log-attributes.origin'),
            'state'                                     => __('inventories::models/scrap.log-attributes.state'),
            'qty'                                       => __('inventories::models/scrap.log-attributes.qty'),
            'should_replenish'                          => __('inventories::models/scrap.log-attributes.should_replenish'),
            'closed_at'                                 => __('inventories::models/scrap.log-attributes.closed_at'),
            'product.name'                              => __('inventories::models/scrap.log-attributes.product'),
            'uom.name'                                  => __('inventories::models/scrap.log-attributes.uom'),
            'lot.name'                                  => __('inventories::models/scrap.log-attributes.lot'),
            'package.name'                              => __('inventories::models/scrap.log-attributes.package'),
            'partner.name'                              => __('inventories::models/scrap.log-attributes.partner'),
            'operation.name'                            => __('inventories::models/scrap.log-attributes.operation'),
            'sourceLocation.full_name'                  => __('inventories::models/scrap.log-attributes.source-location'),
            'destinationLocation.full_name'             => __('inventories::models/scrap.log-attributes.destination-location'),
            'company.name'                              => __('inventories::models/scrap.log-attributes.company'),
            'creator.name'                              => __('inventories::models/scrap.log-attributes.creator'),
        ];
    }

    protected $casts = [
        'state'            => ScrapState::class,
        'should_replenish' => 'boolean',
        'closed_at'        => 'datetime',
        'qty'              => 'decimal:4',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(UOM::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function sourceLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'inventories_scrap_tags', 'scrap_id', 'tag_id');
    }

    public function moves(): HasMany
    {
        return $this->hasMany(Move::class);
    }

    public function moveLines(): HasManyThrough
    {
        return $this->hasManyThrough(MoveLine::class, Move::class);
    }

    public function updateName()
    {
        if (filled($this->name)) {
            return;
        }

        $this->name = SequenceService::next('inventories.scrap', $this->company_id, [
            'name'         => 'Scrap',
            'prefix'       => 'SP/',
            'initial_from' => static::withoutGlobalScopes(),
        ]);
    }

    protected static function newFactory(): ScrapFactory
    {
        return ScrapFactory::new();
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($scrap) {
            $scrap->creator_id ??= Auth::id();
        });

        static::saving(function ($scrap) {
            $scrap->updateName();
        });
    }

    public function validate(): bool
    {
        return $this->getConnection()->transaction(function (): bool {
            Product::query()->withoutGlobalScopes()->whereKey($this->product_id)->lockForUpdate()->firstOrFail();

            $scrap = static::query()->whereKey($this->id)->lockForUpdate()->firstOrFail();

            if ($scrap->state !== ScrapState::DRAFT || $scrap->product_id !== $this->product_id) {
                return false;
            }

            CrossCompanyTransferGuard::assert($scrap->source_location_id, $scrap->destination_location_id);

            if ($scrap->sourceLocation?->company_id !== $scrap->company_id
                || $scrap->destinationLocation?->company_id !== $scrap->company_id) {
                return false;
            }

            $this->setRawAttributes($scrap->getAttributes(), true);
            $this->unsetRelations();

            $baseQty = $this->uom && $this->product?->uom
                ? $this->uom->computeQuantity($this->qty, $this->product->uom, false)
                : $this->qty;

            if (float_compare((float) $baseQty, 0.0, precisionRounding: $this->product->uom->rounding) <= 0) {
                return false;
            }

            $locationQuantity = ProductQuantity::where('company_id', $this->company_id)
                ->where('location_id', $this->source_location_id)
                ->where('product_id', $this->product_id)
                ->where('package_id', $this->package_id ?? null)
                ->where('lot_id', $this->lot_id ?? null)
                ->where('partner_id', $this->partner_id ?? null)
                ->lockForUpdate()
                ->first();

            if (! $locationQuantity || float_compare($locationQuantity->available_quantity, (float) $baseQty, precisionRounding: $this->product->uom->rounding) < 0) {
                return false;
            }

            $locationQuantity->update([
                'quantity' => $locationQuantity->quantity - $baseQty,
            ]);

            $destinationQuantity = ProductQuantity::where('company_id', $this->company_id)
                ->where('product_id', $this->product_id)
                ->where('location_id', $this->destination_location_id)
                ->where('lot_id', $this->lot_id ?? null)
                ->where('package_id', $this->package_id ?? null)
                ->where('partner_id', $this->partner_id ?? null)
                ->lockForUpdate()
                ->first();

            if ($destinationQuantity) {
                $destinationQuantity->update([
                    'quantity' => $destinationQuantity->quantity + $baseQty,
                ]);
            } else {
                ProductQuantity::create([
                    'product_id'  => $this->product_id,
                    'location_id' => $this->destination_location_id,
                    'lot_id'      => $this->lot_id,
                    'package_id'  => $this->package_id,
                    'partner_id'  => $this->partner_id,
                    'quantity'    => $baseQty,
                    'company_id'  => $this->company_id,
                ]);
            }

            $this->update([
                'state'     => ScrapState::DONE,
                'closed_at' => now(),
            ]);

            $moveValues = $this->getInventoryMoveValues();

            $move = Move::create($moveValues);

            foreach ($moveValues['lines'] as $lineValues) {
                MoveLine::create(array_merge($lineValues, [
                    'move_id' => $move->id,
                ]));
            }

            return true;
        });
    }

    public function getInventoryMoveValues()
    {
        return [
            'name'                    => $this->name,
            'state'                   => MoveState::DONE,
            'quantity'                => $this->qty,
            'product_uom_qty'         => $this->uom->computeQuantity($this->qty, $this->product->uom),
            'is_picked'               => true,
            'product_id'              => $this->product_id,
            'partner_id'              => $this->partner_id,
            'uom_id'                  => $this->uom_id,
            'source_location_id'      => $this->source_location_id,
            'destination_location_id' => $this->destination_location_id,
            'company_id'              => $this->company->id ?? current_company_id(),
            'scrap_id'                => $this->id,
            'lines'                   => [[
                'reference'               => $this->name,
                'qty'                     => $this->qty,
                'uom_qty'                 => $this->uom->computeQuantity($this->qty, $this->product->uom),
                'product_id'              => $this->product_id,
                'partner_id'              => $this->partner_id,
                'uom_id'                  => $this->uom_id,
                'source_location_id'      => $this->source_location_id,
                'destination_location_id' => $this->destination_location_id,
                'lot_id'                  => $this->lot_id,
                'package_id'              => $this->package_id,
                'company_id'              => $this->company->id ?? current_company_id(),
            ]],
        ];
    }
}

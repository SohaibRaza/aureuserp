<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Inventory\Enums\MoveState;
use Webkul\Inventory\Models\MoveLine;
use Webkul\Inventory\Models\Package;
use Webkul\Inventory\Models\ProductQuantity;
use Webkul\Partner\Models\Partner;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../Helpers/InventoryHelper.php';

function applyStockIdentityMigration(Migration $migration): void
{
    if (DB::connection()->getDriverName() === 'pgsql') {
        DB::transaction(fn () => $migration->up());
    } else {
        $migration->up();
    }
}

function withStockIdentityMigrationDatabase(Closure $test): void
{
    $originalConnection = DB::getDefaultConnection();
    $configuration = DB::connection()->getConfig();
    $databaseName = 'aureuserp_identity_'.bin2hex(random_bytes(6)).'_testing';
    $connectionName = 'stock_identity_migration';
    $admin = DB::build([...$configuration, 'name' => 'stock_identity_admin']);
    $databaseIdentifier = $admin->getQueryGrammar()->wrap($databaseName);
    $admin->statement('CREATE DATABASE '.$databaseIdentifier);

    try {
        config(['database.connections.'.$connectionName => [...$configuration, 'url' => null, 'database' => $databaseName]]);
        DB::setDefaultConnection($connectionName);
        Schema::clearResolvedInstance('db.schema');

        foreach (['products_products', 'inventories_locations', 'inventories_storage_categories', 'inventories_lots', 'inventories_packages', 'partners_partners', 'users', 'companies'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
            });
            DB::table($tableName)->insert([['id' => 0], ['id' => 1], ['id' => 2]]);
        }

        $tableMigration = require __DIR__.'/../../../database/migrations/2025_01_14_113233_create_inventories_product_quantities_table.php';
        $tableMigration->up();
        $migration = require __DIR__.'/../../../database/migrations/2026_09_24_010000_enforce_product_quantity_identity.php';

        $test($migration);
    } finally {
        DB::purge($connectionName);
        DB::setDefaultConnection($originalConnection);
        Schema::clearResolvedInstance('db.schema');
        $admin->statement('DROP DATABASE '.$databaseIdentifier);
        $admin->disconnect();
    }
}

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('inventories');
    InventoryHelper::actingAsAdmin();
    $this->warehouse = InventoryHelper::warehouse();
    $this->product = InventoryHelper::product();
    $this->stock = InventoryHelper::stockUp($this->product, $this->warehouse->lotStockLocation, 100);
});

it('rejects duplicate nullable balance identities at the database boundary', function (): void {
    $attributes = $this->stock->getAttributes();
    unset($attributes['id']);

    expect(fn () => DB::transaction(fn () => DB::table('inventories_product_quantities')->insert($attributes)))
        ->toThrow(QueryException::class);
    expect(ProductQuantity::where('product_id', $this->product->id)->count())->toBe(1);
});

it('enforces every nullable identity combination through direct SQL', function (int $mask): void {
    $attributes = $this->stock->getAttributes();
    unset($attributes['id']);
    DB::table('inventories_product_quantities')->where('id', $this->stock->id)->delete();

    $optionalIds = [
        'company_id' => $this->warehouse->company_id,
        'lot_id'     => InventoryHelper::lot($this->product, 'Identity lot')->id,
        'package_id' => Package::factory()->create()->id,
        'partner_id' => Partner::factory()->create()->id,
    ];

    foreach (array_keys($optionalIds) as $bit => $column) {
        $attributes[$column] = ($mask & (1 << $bit)) ? $optionalIds[$column] : null;
    }

    DB::table('inventories_product_quantities')->insert($attributes);

    expect(fn () => DB::transaction(fn () => DB::table('inventories_product_quantities')->insert($attributes)))
        ->toThrow(QueryException::class);
    expect(DB::table('inventories_product_quantities')->where('product_id', $this->product->id)->count())->toBe(1);
})->with(range(0, 15));

it('keeps each identity dimension separate from null and other records', function (): void {
    $attributes = $this->stock->getAttributes();
    unset($attributes['id']);

    $variants = [
        ['company_id' => null],
        ['company_id'  => Company::factory()->create(['currency_id' => InventoryHelper::company()->currency_id])->id],
        ['product_id'  => InventoryHelper::product()->id],
        ['location_id' => InventoryHelper::sublocation($this->warehouse->lotStockLocation)->id],
        ['lot_id'      => InventoryHelper::lot($this->product, 'Separate lot')->id],
        ['package_id'  => Package::factory()->create()->id],
        ['partner_id'  => Partner::factory()->create()->id],
        ['partner_id'  => Partner::factory()->create()->id],
    ];

    foreach ($variants as $variant) {
        $identity = [...$attributes, ...$variant];
        DB::table('inventories_product_quantities')->insert($identity);

        expect(fn () => DB::transaction(fn () => DB::table('inventories_product_quantities')->insert($identity)))
            ->toThrow(QueryException::class);
    }

    expect(DB::table('inventories_product_quantities')->where('product_id', $this->product->id)->count())->toBe(8);
});

it('refuses migration over duplicate identities without merging their quantities', function (): void {
    withStockIdentityMigrationDatabase(function (Migration $migration): void {
        $attributes = ['product_id' => 1, 'location_id' => 1, 'quantity' => 100, 'incoming_at' => now()];
        DB::table('inventories_product_quantities')->insert([$attributes, $attributes]);

        expect(fn () => applyStockIdentityMigration($migration))->toThrow(RuntimeException::class, 'Duplicate Inventory balance identities');
        expect(DB::table('inventories_product_quantities')->count())->toBe(2)
            ->and((float) DB::table('inventories_product_quantities')->sum('quantity'))->toBe(200.0);

        DB::table('inventories_product_quantities')->where('id', DB::table('inventories_product_quantities')->max('id'))->delete();
        applyStockIdentityMigration($migration);
        expect(fn () => DB::transaction(fn () => DB::table('inventories_product_quantities')->insert($attributes)))
            ->toThrow(QueryException::class);

        if (DB::getDriverName() === 'mysql') {
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward recovery');
                expect(DB::getQueryLog())->toBe([]);
            } finally {
                DB::disableQueryLog();
            }
            expect(fn () => DB::transaction(fn () => DB::table('inventories_product_quantities')->insert($attributes)))
                ->toThrow(QueryException::class);
            expect(DB::table('inventories_product_quantities')->count())->toBe(1)
                ->and((float) DB::table('inventories_product_quantities')->sum('quantity'))->toBe(100.0);
        } else {
            $migration->down();
            DB::table('inventories_product_quantities')->insert($attributes);
            expect(DB::table('inventories_product_quantities')->count())->toBe(2)
                ->and((float) DB::table('inventories_product_quantities')->sum('quantity'))->toBe(200.0);
        }
    });
});

it('keeps nullable foreign key identity behavior and zero IDs after installation', function (): void {
    withStockIdentityMigrationDatabase(function (Migration $migration): void {
        applyStockIdentityMigration($migration);
        $attributes = ['product_id' => 1, 'location_id' => 1, 'quantity' => 100, 'incoming_at' => now()];
        DB::table('inventories_product_quantities')->insert($attributes);

        foreach (['company_id', 'lot_id', 'package_id', 'partner_id'] as $column) {
            $identity = [...$attributes, $column => 0];
            DB::table('inventories_product_quantities')->insert($identity);

            expect(fn () => DB::transaction(fn () => DB::table('inventories_product_quantities')->insert($identity)))
                ->toThrow(QueryException::class);
        }

        foreach (['company_id' => 'companies', 'partner_id' => 'partners_partners'] as $column => $parentTable) {
            $id = DB::table('inventories_product_quantities')->insertGetId([...$attributes, 'location_id' => 2, $column => 2]);
            if (DB::getDriverName() === 'mysql') {
                $before = (array) DB::table('inventories_product_quantities')->where('id', $id)->first();
                expect(fn () => DB::transaction(fn () => DB::table($parentTable)->where('id', 2)->delete()))
                    ->toThrow(QueryException::class);
                expect(DB::table($parentTable)->where('id', 2)->exists())->toBeTrue()
                    ->and((array) DB::table('inventories_product_quantities')->where('id', $id)->first())->toBe($before);
            } else {
                DB::table($parentTable)->where('id', 2)->delete();
                expect(DB::table('inventories_product_quantities')->where('id', $id)->value($column))->toBeNull();
            }
            DB::table('inventories_product_quantities')->where('id', $id)->delete();

            $id = DB::table('inventories_product_quantities')->insertGetId([...$attributes, $column => 1]);
            $before = (array) DB::table('inventories_product_quantities')->where('id', $id)->first();
            // Direct updates must reject a NULL collision independently of parent restrictions.
            expect(fn () => DB::transaction(fn () => DB::table('inventories_product_quantities')->where('id', $id)->update([$column => null])))
                ->toThrow(QueryException::class);
            expect(fn () => DB::transaction(fn () => DB::table($parentTable)->where('id', 1)->delete()))
                ->toThrow(QueryException::class);
            expect(DB::table($parentTable)->where('id', 1)->exists())->toBeTrue()
                ->and((array) DB::table('inventories_product_quantities')->where('id', $id)->first())->toBe($before);
        }
    });
});

it('does not leave a direct move line behind when its reservation fails', function (): void {
    $operation = InventoryHelper::delivery($this->warehouse, [[$this->product, 120]]);
    $move = $operation->moves->first();
    $move->update(['state' => MoveState::CONFIRMED]);
    $before = MoveLine::count();

    expect(fn () => $move->lines()->create($move->buildLineAttributes(quantity: 120)))
        ->toThrow(InvalidArgumentException::class);
    expect(MoveLine::count())->toBe($before)
        ->and((float) $this->stock->refresh()->reserved_quantity)->toBe(0.0);
});

it('rejects releasing more than the current reservation without changing stock', function (): void {
    expect(fn () => ProductQuantity::applyReservationDelta($this->product, $this->warehouse->lotStockLocation, -1))
        ->toThrow(InvalidArgumentException::class);
    expect((float) $this->stock->refresh()->reserved_quantity)->toBe(0.0);
});

it('does not overwrite a concurrent line quantity or reservation from a stale model', function (): void {
    $move = InventoryHelper::delivery($this->warehouse, [[$this->product, 100]])->moves->first();
    $move->update(['state' => MoveState::CONFIRMED]);
    $line = $move->lines()->create($move->buildLineAttributes(quantity: 40));
    $stale = $line->fresh();
    $line->update(['qty' => 60]);

    expect(fn () => $stale->update(['qty' => 70]))->toThrow(InvalidArgumentException::class, 'changed');
    expect((float) $line->fresh()->qty)->toBe(60.0)
        ->and((float) $this->stock->fresh()->reserved_quantity)->toBe(60.0);
});

it('rolls back direct line deletion and its released reservation after a listener failure', function (): void {
    $move = InventoryHelper::delivery($this->warehouse, [[$this->product, 100]])->moves->first();
    $move->update(['state' => MoveState::CONFIRMED]);
    $line = $move->lines()->create($move->buildLineAttributes(quantity: 40));
    MoveLine::deleted(function (): void {
        throw new RuntimeException('Delete listener failed');
    });

    expect(fn () => $line->delete())->toThrow(RuntimeException::class, 'Delete listener failed');
    expect(MoveLine::find($line->id))->not->toBeNull()
        ->and((float) $this->stock->fresh()->reserved_quantity)->toBe(40.0);
});

it('keeps a specified lot separate from the untracked balance during a delta', function (): void {
    $lot = InventoryHelper::lot($this->product, 'Identity lot');
    ProductQuantity::applyQuantityDelta($this->product, $this->warehouse->lotStockLocation, -10, lot: $lot);

    expect((float) $this->stock->fresh()->quantity)->toBe(100.0)
        ->and((float) ProductQuantity::where('product_id', $this->product->id)->where('lot_id', $lot->id)->value('quantity'))->toBe(-10.0);
});

it('does not purge another product balance as a side effect of a stock mutation', function (): void {
    $otherProduct = InventoryHelper::product();
    $otherStock = InventoryHelper::stockUp($otherProduct, $this->warehouse->lotStockLocation, 0);

    ProductQuantity::applyQuantityDelta($this->product, $this->warehouse->lotStockLocation, 1);

    expect($otherStock->fresh())->not->toBeNull();
    ProductQuantity::purgeEmpty([$otherProduct->id]);
    expect($otherStock->fresh())->toBeNull();
});

<?php

require_once __DIR__.'/ReconTestSupport.php';

use App\Models\Recon\BankReconciliation;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Support\Models\Company;

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('accounts');

    $this->company = Company::query()->firstOrFail();
});

it('installs the reconciliation integrity schema through the accounts plugin', function () {
    expect(Schema::hasColumns('recon_bank_reconciliations', [
        'bank_account_id', 'liquidity_account_id', 'currency_id', 'statement_date',
        'prior_reconciliation_id', 'continuity_type',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('accounts_bank_statement_lines', [
            'transaction_date', 'value_date', 'external_id', 'import_batch_id',
            'source_fingerprint', 'import_status',
        ]))->toBeTrue()
        ->and(Schema::hasTable('recon_reconciling_items'))->toBeTrue()
        ->and(Schema::hasTable('recon_reconciliation_snapshots'))->toBeTrue()
        ->and(Schema::hasColumn('accounts_partial_reconciles', 'recon_match_id'))->toBeTrue()
        ->and(Schema::hasColumns('recon_matches', [
            'accounting_mode', 'ledger_previous_residuals', 'accounting_applied_amount',
            'accounting_applied_at', 'accounting_applied_by', 'accounting_reversed_at',
            'accounting_reversed_by', 'idempotency_key', 'idempotency_fingerprint',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('recon_match_lines', [
            'previous_ledger_residual', 'previous_ledger_residual_currency',
            'applied_ledger_amount', 'accounting_reversed_at', 'accounting_reversed_by',
        ]))->toBeTrue();
});

it('rejects a reconciliation whose period ends before it starts', function () {
    expect(fn () => BankReconciliation::query()->create([
        ...validBankReconciliationAttributes(),
        'period_start' => '2026-09-01',
        'period_end'   => '2026-08-31',
    ]))->toThrow(QueryException::class);
});

it('rejects an unsupported reconciliation state', function () {
    expect(fn () => BankReconciliation::query()->create([
        ...validBankReconciliationAttributes(),
        'status' => 'approved',
    ]))->toThrow(QueryException::class);
});

it('requires account and currency identity for non-legacy reconciliations', function () {
    $attributes = validBankReconciliationAttributes();
    $attributes['bank_account_id'] = null;
    $attributes['created_at'] = now();
    $attributes['updated_at'] = now();

    expect(fn () => DB::table('recon_bank_reconciliations')->insert($attributes))
        ->toThrow(QueryException::class);
});

it('rejects a reversed match without an audit reason at the database boundary', function () {
    $reconciliation = BankReconciliation::query()->create(validBankReconciliationAttributes());

    expect(fn () => DB::table('recon_matches')->insert([
        'company_id'        => $reconciliation->company_id,
        'reconciliation_id' => $reconciliation->id,
        'status'            => 'reversed',
        'match_type'        => 'manual',
        'amount'            => 0,
        'variance'          => 0,
        'reversal_reason'   => null,
        'created_at'        => now(),
        'updated_at'        => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a reconciliation assigned to a different company than its journal', function () {
    $otherCompanyId = DB::table('companies')->insertGetId([
        'currency_id' => $this->company->currency_id,
        'creator_id'  => DB::table('users')->value('id'),
        'partner_id'  => $this->company->partner_id,
        'name'        => 'Other Test Company',
        'company_id'  => fake()->uuid(),
        'is_active'   => true,
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);

    expect(fn () => BankReconciliation::query()->create([
        ...validBankReconciliationAttributes(),
        'company_id' => $otherCompanyId,
    ]))->toThrow(QueryException::class);
});

it('allows only one active reconciliation for a bank account', function () {
    $attributes = validBankReconciliationAttributes();

    BankReconciliation::query()->create($attributes);

    expect(fn () => BankReconciliation::query()->create([
        ...$attributes,
        'name' => fake()->unique()->bothify('RECON-####'),
    ]))->toThrow(QueryException::class);
});

it('allows incomplete prototype records only when quarantined as legacy unverified', function () {
    $journal = testBankJournal();

    $id = DB::table('recon_bank_reconciliations')->insertGetId([
        'journal_id' => $journal->id,
        'name'       => fake()->unique()->bothify('LEGACY-####'),
        'status'     => 'legacy_unverified',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('recon_bank_reconciliations')->where('id', $id)->value('status'))
        ->toBe('legacy_unverified');
});

it('prevents reconciliation snapshots from being updated through direct database access', function () {
    $reconciliation = BankReconciliation::query()->create(validBankReconciliationAttributes());

    $snapshotId = DB::table('recon_reconciliation_snapshots')->insertGetId([
        'company_id'        => $this->company->id,
        'reconciliation_id' => $reconciliation->id,
        'version'           => 1,
        'payload'           => json_encode(['difference' => '0.0000'], JSON_THROW_ON_ERROR),
        'payload_hash'      => hash('sha256', 'snapshot'),
        'created_by'        => DB::table('users')->value('id'),
        'created_at'        => now(),
    ]);

    expect(fn () => DB::transaction(fn () => DB::table('recon_reconciliation_snapshots')
        ->where('id', $snapshotId)
        ->update(['payload_hash' => hash('sha256', 'changed')])))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('recon_reconciliation_snapshots')->where('id', $snapshotId)->delete()))
        ->toThrow(QueryException::class);
    $migration = require base_path('plugins/webkul/accounts/database/migrations/2026_09_07_000007_create_reconciliation_snapshots_table.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward recovery');
    expect(DB::table('recon_reconciliation_snapshots')->where('id', $snapshotId)->value('payload_hash'))->toBe(hash('sha256', 'snapshot'));
});

it('keeps snapshot JSON bytes unchanged and rejects malformed JSON', function (): void {
    $reconciliation = BankReconciliation::query()->create(validBankReconciliationAttributes());
    $payload = '{"z":"last key first","a":{"z":1,"a":2},"items":[3,1,2]}';
    $attributes = [
        'company_id'        => $this->company->id,
        'reconciliation_id' => $reconciliation->id,
        'version'           => 1,
        'payload'           => $payload,
        'payload_hash'      => hash('sha256', $payload),
        'created_by'        => DB::table('users')->value('id'),
        'created_at'        => now(),
    ];
    $id = DB::table('recon_reconciliation_snapshots')->insertGetId($attributes);
    expect(DB::table('recon_reconciliation_snapshots')->where('id', $id)->value('payload'))->toBe($payload);

    expect(fn () => DB::transaction(fn () => DB::table('recon_reconciliation_snapshots')->insert([
        ...$attributes,
        'version'      => 2,
        'payload'      => '{invalid json',
        'payload_hash' => hash('sha256', 'invalid'),
    ])))->toThrow(QueryException::class);
});

it('upgrades empty MySQL snapshot storage but stops before changing existing history', function (): void {
    if (DB::getDriverName() !== 'mysql') {
        $migration = require base_path('plugins/webkul/accounts/database/migrations/2026_10_21_000300_preserve_reconciliation_snapshot_payload_bytes.php');
        $migration->up();
        expect(Schema::getColumnType('recon_reconciliation_snapshots', 'payload'))->toBe('json');

        return;
    }

    $original = DB::getDefaultConnection();
    $configuration = DB::connection()->getConfig();
    $database = 'aureuserp_snapshot_'.bin2hex(random_bytes(6)).'_testing';
    $admin = DB::build([...$configuration, 'name' => 'snapshot_storage_admin']);
    $identifier = $admin->getQueryGrammar()->wrap($database);
    $admin->statement('CREATE DATABASE '.$identifier);
    try {
        config(['database.connections.snapshot_storage_test' => [...$configuration, 'url' => null, 'database' => $database]]);
        DB::setDefaultConnection('snapshot_storage_test');
        Schema::clearResolvedInstance('db.schema');
        $createLegacyTable = function (): void {
            Schema::create('recon_reconciliation_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->json('payload');
            });
        };
        $createLegacyTable();
        $migration = require base_path('plugins/webkul/accounts/database/migrations/2026_10_21_000300_preserve_reconciliation_snapshot_payload_bytes.php');
        $migration->up();
        expect(Schema::getColumnType('recon_reconciliation_snapshots', 'payload'))->toBe('longtext')
            ->and(fn () => DB::table('recon_reconciliation_snapshots')->insert(['payload' => 'invalid']))->toThrow(QueryException::class);
        $migration->up();

        Schema::drop('recon_reconciliation_snapshots');
        $createLegacyTable();
        DB::table('recon_reconciliation_snapshots')->insert(['payload' => '{"z":1,"a":2}']);
        $before = DB::table('recon_reconciliation_snapshots')->value('payload');
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'do not rewrite saved hashes')
            ->and(Schema::getColumnType('recon_reconciliation_snapshots', 'payload'))->toBe('json')
            ->and(DB::table('recon_reconciliation_snapshots')->value('payload'))->toBe($before);
    } finally {
        DB::purge('snapshot_storage_test');
        DB::setDefaultConnection($original);
        Schema::clearResolvedInstance('db.schema');
        $admin->statement('DROP DATABASE '.$identifier);
        $admin->disconnect();
    }
});

it('prevents reconciliation events from being updated or deleted through direct database access', function () {
    $reconciliation = BankReconciliation::query()->create(validBankReconciliationAttributes());
    BankReconciliation::logEvent($reconciliation, 'test_event', ['source' => 'schema-test']);
    $eventId = $reconciliation->events()->value('id');

    expect(fn () => DB::transaction(fn () => DB::table('recon_events')->where('id', $eventId)->update(['event' => 'tampered'])))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('recon_events')->where('id', $eventId)->delete()))
        ->toThrow(QueryException::class);
    $migration = require base_path('plugins/webkul/accounts/database/migrations/2026_09_07_000001_create_recon_tables.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'forward recovery');
    expect(DB::table('recon_events')->where('id', $eventId)->value('event'))->toBe('test_event');

});

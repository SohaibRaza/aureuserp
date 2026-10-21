<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Webkul\Account\Settings\DefaultAccountSettings;
use Webkul\PluginManager\Console\Commands\InstallERP;

it('stops ERP installation when the migration command reports failure', function (): void {
    $artisan = Artisan::getFacadeRoot();
    $command = new InstallERP;
    $command->setLaravel(app());
    $input = new ArrayInput(['--force' => true]);
    $input->setInteractive(false);

    try {
        Artisan::shouldReceive('call')->once()
            ->with('migrate', ['--force' => true, '--no-interaction' => true], Mockery::any())
            ->andReturn(1);

        expect(fn () => $command->run($input, new BufferedOutput))
            ->toThrow(RuntimeException::class, 'Database migrations failed. Installation cannot continue.');
    } finally {
        Artisan::swap($artisan);
    }
});

it('installs reconciliation migrations through Accounts after the core ERP schema', function (): void {
    TestBootstrapHelper::ensurePluginInstalled('accounts');

    expect(Schema::hasTable('accounts_journals'))->toBeTrue()
        ->and(Schema::hasTable('recon_bank_opening_balances'))->toBeTrue()
        ->and(Schema::hasColumn('recon_matches', 'idempotency_fingerprint'))->toBeTrue();

    foreach ([
        '2026_09_07_000001_create_recon_tables',
        '2026_09_10_000001_add_accounting_provenance_to_recon_matches',
        '2026_09_11_000001_create_recon_bank_opening_balances_table',
        '2026_09_12_000002_protect_bank_opening_balance_evidence',
    ] as $migration) {
        expect(DB::table('migrations')->where('migration', $migration)->count())->toBe(1);
    }
});

it('resolves Webkul and non-Webkul plugin roots', function (): void {
    $pluginsRoot = sys_get_temp_dir().'/aureuserp-plugin-path-'.bin2hex(random_bytes(8));

    try {
        mkdir($pluginsRoot.'/webkul/accounts', 0700, true);
        mkdir($pluginsRoot.'/shafique/logistics', 0700, true);

        expect(TestBootstrapHelper::pluginRootPath('accounts', $pluginsRoot))
            ->toBe($pluginsRoot.'/webkul/accounts')
            ->and(TestBootstrapHelper::pluginRootPath('logistics', $pluginsRoot))
            ->toBe($pluginsRoot.'/shafique/logistics');
    } finally {
        rmdir($pluginsRoot.'/webkul/accounts');
        rmdir($pluginsRoot.'/webkul');
        rmdir($pluginsRoot.'/shafique/logistics');
        rmdir($pluginsRoot.'/shafique');
        rmdir($pluginsRoot);
    }
});

it('loads account settings when Inventory initializes the shared test database first', function (): void {
    TestBootstrapHelper::ensurePluginInstalled('inventories');
    TestBootstrapHelper::ensurePluginInstalled('accounts');

    $settings = app(DefaultAccountSettings::class)->toArray();

    expect($settings)->toHaveKeys([
        'currency_exchange_journal_id',
        'income_currency_exchange_account_id',
        'expense_currency_exchange_account_id',
        'account_discount_expense_allocation_id',
        'account_discount_income_allocation_id',
        'account_journal_suspense_account_id',
        'account_journal_payment_debit_account_id',
        'account_journal_payment_credit_account_id',
        'income_account_id',
        'expense_account_id',
        'transfer_account_id',
    ]);
});

it('does not mark or cache a plugin when its installer fails', function (): void {
    TestBootstrapHelper::ensurePluginInstalled('inventories');

    config(['database.connections.test_bootstrap' => config('database.connections.'.config('database.default'))]);
    $bootstrap = DB::connection('test_bootstrap');
    $wasInstalled = $bootstrap->table('plugins')->where('name', 'accounts')->value('is_installed');
    $bootstrap->table('plugins')->where('name', 'accounts')->update(['is_installed' => false]);
    $installedPlugins = new ReflectionProperty(TestBootstrapHelper::class, 'installedPlugins');
    $cachedPlugins = $installedPlugins->getValue();
    $installedPlugins->setValue([]);
    $artisan = Artisan::getFacadeRoot();

    try {
        Artisan::shouldReceive('call')
            ->once()
            ->with('accounts:install', ['--no-interaction' => true])
            ->andReturn(1);

        expect(fn (): mixed => TestBootstrapHelper::ensurePluginInstalled('accounts'))
            ->toThrow(RuntimeException::class, 'Unable to install required test plugin [accounts].');

        expect((bool) DB::connection('test_bootstrap')->table('plugins')->where('name', 'accounts')->value('is_installed'))
            ->toBeFalse()
            ->and($installedPlugins->getValue())
            ->not->toHaveKey('accounts');
    } finally {
        Artisan::swap($artisan);
        $installedPlugins->setValue($cachedPlugins);
        DB::connection('test_bootstrap')->table('plugins')->where('name', 'accounts')->update(['is_installed' => $wasInstalled]);
    }
});

<?php

use Filament\Facades\Filament;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;
use Webkul\PluginManager\PackageServiceProvider;
use Webkul\Support\Database\Dialects\PostgresDialect;

final class TestBootstrapException extends RuntimeException {}

class TestBootstrapHelper
{
    private const BOOTSTRAP_CONNECTION = 'test_bootstrap';

    private const FRESH_BOOTSTRAP_PLUGINS = ['products', 'accounts'];

    private static bool $isERPInstalled = false;

    private static array $installedPlugins = [];

    private static array $bootedPluginProviders = [];

    private static array $pluginTables = [
        'projects'      => 'projects_projects',
        'sales'         => 'sales_orders',
        'purchases'     => 'purchases_orders',
        'inventories'   => 'inventories_operations',
        'accounts'      => 'accounts_account_moves',
        'products'      => 'products_products',
        'manufacturing' => 'manufacturing_orders',
    ];

    public static function ensureAllPluginsInstalled(): void
    {
        foreach (array_keys(static::$pluginTables) as $pluginName) {
            static::ensurePluginInstalled($pluginName);
        }
    }

    public static function ensurePluginInstalled(string $pluginName): void
    {
        static::ensureERPInstalled();

        if (isset(static::$installedPlugins[$pluginName])) {
            return;
        }

        $table = static::$pluginTables[$pluginName] ?? null;

        static::onCommittedConnection(function () use ($pluginName, $table) {
            $isMarkedInstalled = Schema::hasTable('plugins')
                && DB::table('plugins')->where('name', $pluginName)->where('is_installed', true)->exists();
            $isInstalled = $isMarkedInstalled && (! $table || Schema::hasTable($table));

            if (! $isInstalled) {
                static::installPlugin($pluginName);
            }

            foreach (static::$pluginTables as $candidate => $candidateTable) {
                if (isset(static::$installedPlugins[$candidate]) || ! Schema::hasTable($candidateTable)) {
                    continue;
                }

                if (! DB::table('plugins')->where('name', $candidate)->where('is_installed', true)->exists()) {
                    static::installPlugin($candidate);
                }

                static::ensurePluginSeeded($candidate);

                static::ensurePluginMarkedInstalled($candidate);

                static::$installedPlugins[$candidate] = true;
            }

            if (! $table) {
                static::ensurePluginMarkedInstalled($pluginName);

                static::$installedPlugins[$pluginName] = true;
            }
        });

        static::syncIdentitySequences();

        static::bootInstalledPluginProviders();

        static::restartTestTransaction();
    }

    private static function installPlugin(string $pluginName): void
    {
        if (Artisan::call("{$pluginName}:install", ['--no-interaction' => true]) !== 0) {
            throw new RuntimeException("Unable to install required test plugin [{$pluginName}].");
        }
    }

    private static function onCommittedConnection(callable $callback): void
    {
        $default = config('database.default');

        config(['database.connections.'.self::BOOTSTRAP_CONNECTION => config("database.connections.{$default}")]);

        DB::purge(self::BOOTSTRAP_CONNECTION);

        config(['database.default' => self::BOOTSTRAP_CONNECTION]);

        try {
            $callback();
        } finally {
            config(['database.default' => $default]);
        }
    }

    private static function restartTestTransaction(): void
    {
        $connection = DB::connection();

        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();

            $connection->beginTransaction();
        }
    }

    private static function bootInstalledPluginProviders(): void
    {
        config(['database.connections.'.self::BOOTSTRAP_CONNECTION => config('database.connections.'.config('database.default'))]);

        $installed = DB::connection(self::BOOTSTRAP_CONNECTION)
            ->table('plugins')
            ->where('is_installed', true)
            ->pluck('name')
            ->all();

        foreach (array_keys(app()->getLoadedProviders()) as $providerClass) {
            if (! is_subclass_of($providerClass, PackageServiceProvider::class)) {
                continue;
            }

            $pluginName = property_exists($providerClass, 'name') ? $providerClass::$name : null;

            if (! $pluginName || isset(static::$bootedPluginProviders[$pluginName])) {
                continue;
            }

            if (! in_array($pluginName, $installed, true)) {
                continue;
            }

            $provider = app()->getProvider($providerClass);

            if ($provider instanceof PackageServiceProvider) {
                $provider->packageBooted();
            }

            static::registerPanelPlugin($pluginName);

            static::loadPluginRoutes($pluginName);

            static::$bootedPluginProviders[$pluginName] = true;
        }
    }

    private static function ensurePluginSeeded(string $pluginName): void
    {
        $pluginSeeders = [
            'projects'      => ['projects_project_stages', 'Webkul\Project\Database\Seeders\DatabaseSeeder'],
            'sales'         => ['sales_teams', 'Webkul\Sale\Database\Seeders\DatabaseSeeder'],
            'inventories'   => ['inventories_locations', 'Webkul\Inventory\Database\Seeders\DatabaseSeeder'],
            'accounts'      => ['accounts_accounts', 'Webkul\Account\Database\Seeders\DatabaseSeeder'],
            'products'      => ['products_categories', 'Webkul\Product\Database\Seeders\DatabaseSeeder'],
            'manufacturing' => ['manufacturing_work_center_productivity_losses', 'Webkul\Manufacturing\Database\Seeders\DatabaseSeeder'],
        ];

        if (! isset($pluginSeeders[$pluginName])) {
            return;
        }

        [$probeTable, $seederClass] = $pluginSeeders[$pluginName];

        if (! Schema::hasTable($probeTable) || DB::table($probeTable)->exists()) {
            return;
        }

        Artisan::call('db:seed', [
            '--class' => $seederClass,
            '--force' => true,
        ]);
    }

    private static function ensurePluginMarkedInstalled(string $pluginName): void
    {
        if (! Schema::hasTable('plugins')) {
            return;
        }

        DB::table('plugins')->updateOrInsert(
            ['name' => $pluginName],
            [
                'is_installed' => true,
                'is_active'    => true,
                'created_at'   => now(),
                'updated_at'   => now(),
            ],
        );

        Package::$plugins = Plugin::all()->keyBy('name');
    }

    private static function registerPanelPlugin(string $pluginName): void
    {
        foreach (Filament::getPanels() as $panel) {
            if ($panel->hasPlugin($pluginName)) {
                $panel->getPlugin($pluginName)->register($panel);
            }
        }
    }

    private static function loadPluginRoutes(string $pluginName): void
    {
        $pluginRoot = static::pluginRootPath($pluginName);
        $routeFile = $pluginRoot ? $pluginRoot.'/routes/api.php' : null;

        if ($routeFile && file_exists($routeFile) && ! app()->routesAreCached()) {
            require $routeFile;
        }
    }

    public static function pluginRootPath(string $pluginName, ?string $pluginsRoot = null): ?string
    {
        $matches = glob(($pluginsRoot ?? base_path('plugins'))."/*/{$pluginName}", GLOB_ONLYDIR);

        return count($matches) === 1 ? $matches[0] : null;
    }

    public static function ensureERPInstalled(): void
    {
        if (static::$isERPInstalled) {
            return;
        }

        static::rememberProvidersBootedBeforeFresh();

        static::ensureWorkerDatabase();

        if (static::shouldReusePreparedDatabase()) {
            static::assertPreparedDatabase();
            static::syncIdentitySequences();
            static::$isERPInstalled = true;
            static::bootInstalledPluginProviders();

            return;
        }

        Artisan::call('migrate:fresh', [
            '--force'    => true,
            '--path'     => static::migrationPathsForFreshInstall(),
            '--realpath' => true,
        ]);

        Artisan::call('erp:install', [
            '--force'          => true,
            '--no-interaction' => true,
            '--admin-name'     => 'Test Admin',
            '--admin-email'    => 'admin@example.com',
            '--admin-password' => 'admin123',
        ]);

        static::syncIdentitySequences();

        static::$isERPInstalled = true;

        static::bootInstalledPluginProviders();
    }

    /**
     * Reuse is deliberately opt-in. It prevents focused local tests from rebuilding
     * the entire ERP, while CI and clean-room runs retain the fresh-install path.
     */
    private static function shouldReusePreparedDatabase(): bool
    {
        if (filled(config('testing.parallel_token'))) {
            return false;
        }

        return filter_var(config('testing.reuse_database', false), FILTER_VALIDATE_BOOL);
    }

    private static function assertPreparedDatabase(): void
    {
        $requiredTables = [
            'migrations',
            'plugins',
            'companies',
            'users',
            'accounts_account_moves',
            'recon_bank_reconciliations',
            'recon_events',
            'recon_reconciliation_snapshots',
        ];

        $missing = array_values(array_filter($requiredTables, fn (string $table): bool => ! Schema::hasTable($table)));
        $accountsInstalled = Schema::hasTable('plugins')
            && DB::table('plugins')->where('name', 'accounts')->where('is_installed', true)->exists();

        if ($missing === [] && $accountsInstalled) {
            return;
        }

        $database = config('database.connections.'.config('database.default').'.database');
        $missingLabel = $missing === [] ? 'the Accounts plugin installation' : implode(', ', $missing);

        throw new TestBootstrapException(
            "Prepared test database [{$database}] is unavailable ({$missingLabel}). "
            .'Run the explicitly approved test-database preparation steps, then retry with AUREUSERP_TEST_REUSE_DATABASE=1.'
        );
    }

    /**
     * Seeders insert rows with explicit IDs, leaving Postgres identity
     * sequences behind. Sync every sequence past its table's current max so
     * factory-created rows cannot collide with seeded ones.
     */
    private static function syncIdentitySequences(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        (new PostgresDialect)->syncSequences();
    }

    /**
     * @return list<string>
     */
    private static function migrationPathsForFreshInstall(): array
    {
        $coreNames = [];

        /** @var Migrator $migrator */
        $migrator = app('migrator');
        $paths = [database_path('migrations'), ...$migrator->paths()];

        foreach (array_keys(app()->getLoadedProviders()) as $providerClass) {
            if (! is_subclass_of($providerClass, PackageServiceProvider::class)) {
                continue;
            }

            $provider = app()->getProvider($providerClass);

            if (! $provider instanceof PackageServiceProvider) {
                continue;
            }

            $package = new Package;
            $provider->configureCustomPackage($package);

            if ($package->isCore) {
                $coreNames[] = $package->shortName();
            }

            if (! in_array($package->shortName(), self::FRESH_BOOTSTRAP_PLUGINS, true)) {
                continue;
            }

            $pluginRoot = static::pluginRootPath($package->shortName());
            if (! $pluginRoot) {
                continue;
            }

            foreach ($package->migrationFileNames as $migration) {
                $path = "{$pluginRoot}/database/migrations/{$migration}.php";

                if (file_exists($path)) {
                    $paths[] = $path;
                }
            }
        }

        $allowedNames = array_merge($coreNames, self::FRESH_BOOTSTRAP_PLUGINS);

        return array_values(array_unique(array_filter($paths, function (string $path) use ($allowedNames): bool {
            if (! preg_match('#plugins/[^/]+/([^/]+)/#', str_replace('\\', '/', $path), $matches)) {
                return true;
            }

            return in_array($matches[1], $allowedNames, true);
        })));
    }

    private static function rememberProvidersBootedBeforeFresh(): void
    {
        foreach (Package::$plugins ?? [] as $name => $plugin) {
            if ($plugin->is_installed) {
                static::$bootedPluginProviders[$name] = true;
            }
        }
    }

    private static function ensureWorkerDatabase(): void
    {
        $token = config('testing.parallel_token');

        if (blank($token)) {
            $database = (string) config('database.connections.'.config('database.default').'.database');
            if (! str_ends_with($database, '_testing')) {
                throw new TestBootstrapException('Refusing to run test bootstrap against a database that does not end in _testing.');
            }

            return;
        }

        $default = config('database.default');

        $connection = config("database.connections.{$default}");

        $testDatabase = (string) $connection['database'];
        if (! str_ends_with($testDatabase, '_testing')) {
            throw new TestBootstrapException('Refusing to create a parallel worker database from a database that does not end in _testing.');
        }

        $workerDatabase = static::workerDatabaseName($testDatabase, (string) $token);

        if ($connection['driver'] === 'pgsql') {
            $pdo = new PDO(
                "pgsql:host={$connection['host']};port={$connection['port']};dbname={$connection['database']}",
                $connection['username'],
                $connection['password'],
            );

            $statement = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = :database');
            if ($statement === false) {
                throw new TestBootstrapException('Unable to prepare the parallel test database lookup.');
            }

            $statement->execute(['database' => $workerDatabase]);
            $exists = $statement->fetchColumn() !== false;

            if (! $exists) {
                try {
                    $pdo->exec('CREATE DATABASE '.static::quoteDatabaseIdentifier($workerDatabase, 'pgsql')); // NOSONAR: identifier is restricted by workerDatabaseName().
                } catch (PDOException $e) {
                    if (! str_contains($e->getMessage(), 'already exists')) {
                        throw $e;
                    }
                }
            }
        } else {
            $pdo = new PDO(
                "mysql:host={$connection['host']};port={$connection['port']}",
                $connection['username'],
                $connection['password'],
            );

            $pdo->exec('CREATE DATABASE IF NOT EXISTS '.static::quoteDatabaseIdentifier($workerDatabase, 'mysql')." CHARACTER SET utf8mb4 COLLATE 'utf8mb4_unicode_ci'"); // NOSONAR: identifier is restricted by workerDatabaseName().
        }

        config(["database.connections.{$default}.database" => $workerDatabase]);

        DB::purge($default);

        putenv("DB_DATABASE={$workerDatabase}");

        $_ENV['DB_DATABASE'] = $workerDatabase;

        $_SERVER['DB_DATABASE'] = $workerDatabase;

        static::ensureWorkerViewPath($token);
    }

    private static function workerDatabaseName(string $testDatabase, string $token): string
    {
        $workerDatabase = $testDatabase.'_p'.$token;

        if (! preg_match('/\A[A-Za-z_]\w*\z/', $workerDatabase)) {
            throw new TestBootstrapException('Refusing to create a parallel worker database with an unsafe identifier.');
        }

        return $workerDatabase;
    }

    private static function quoteDatabaseIdentifier(string $database, string $driver): string
    {
        return $driver === 'pgsql' ? '"'.$database.'"' : '`'.$database.'`';
    }

    private static function ensureWorkerViewPath(string $token): void
    {
        $viewPath = storage_path('framework/testing/views/'.$token);

        if (! is_dir($viewPath)) {
            mkdir($viewPath, 0755, true);
        }

        config(['view.compiled' => $viewPath]);

        putenv("VIEW_COMPILED_PATH={$viewPath}");

        $_ENV['VIEW_COMPILED_PATH'] = $viewPath;

        $_SERVER['VIEW_COMPILED_PATH'] = $viewPath;
    }
}

<?php

use App\Providers\Filament\AdminPanelProvider;
use Filament\Panel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Webkul\PluginManager\Console\Commands\InstallCommand;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;

class TraversalTestInstallCommand extends InstallCommand
{
    public static array $visits = [];

    public static array $permissionRefreshes = [];

    public static array $cacheRefreshes = [];

    protected function regenerateAdminPanelPermissions(): void
    {
        self::$permissionRefreshes[] = $this->getName();
    }

    protected function refreshApplicationCaches(): void
    {
        self::$cacheRefreshes[] = $this->getName();
    }
}

beforeEach(function (): void {
    $this->savedInstallerPlugins = is_object(Package::$plugins) ? clone Package::$plugins : Package::$plugins;
    TraversalTestInstallCommand::$visits = [];
    TraversalTestInstallCommand::$permissionRefreshes = [];
    TraversalTestInstallCommand::$cacheRefreshes = [];
    $this->installerRoot = base_path('storage/framework/testing/installer-'.Str::uuid());
});

afterEach(function (): void {
    Package::$plugins = $this->savedInstallerPlugins;
    File::deleteDirectory($this->installerRoot);
});

/** @return array<string, Package> */
function registerTraversalPackages(string $root, array $graph, ?Closure $onStart = null): array
{
    $packages = [];
    foreach ($graph as $name => $dependencies) {
        File::ensureDirectoryExists($root.'/'.$name.'/src');
        File::ensureDirectoryExists($root.'/'.$name.'/database/migrations');
        File::put($root.'/'.$name.'/composer.json', json_encode(['name' => 'test/'.$name], JSON_THROW_ON_ERROR));
        $package = (new Package)->name($name)->setBasePath($root.'/'.$name.'/src')->hasDependencies($dependencies);
        $command = new TraversalTestInstallCommand($package);
        $command->installDependencies()->runsMigrations()->startWith(function () use ($name, $onStart): void {
            TraversalTestInstallCommand::$visits[] = $name;
            if ($onStart) {
                $onStart($name);
            }
        });
        Artisan::registerCommand($command);
        $packages[$name] = $package;
    }

    return $packages;
}

it('visits a shared installed dependency once and still applies its pending migration', function (): void {
    $packages = registerTraversalPackages($this->installerRoot, [
        'review_root'   => ['review_left', 'review_right'],
        'review_left'   => ['review_shared'],
        'review_right'  => ['review_shared'],
        'review_shared' => [],
    ]);
    Plugin::query()->create(['name' => 'review_shared', 'is_installed' => true, 'is_active' => true]);
    $migration = '2099_01_01_000001_review_installer_dependency';
    $packages['review_shared']->migrationFileNames[] = $migration;
    File::put($this->installerRoot.'/review_shared/database/migrations/'.$migration.'.php', <<<'MIGRATION'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        \Illuminate\Support\Facades\Schema::create('review_installer_rows', function (\Illuminate\Database\Schema\Blueprint $table): void { $table->id(); });
    }
};
MIGRATION);

    expect(Artisan::call('review_root:install', ['--no-interaction' => true]))->toBe(0)
        ->and(TraversalTestInstallCommand::$visits)->toBe(['review_root', 'review_left', 'review_shared', 'review_right'])
        ->and(TraversalTestInstallCommand::$permissionRefreshes)->toBe(['review_root:install'])
        ->and(TraversalTestInstallCommand::$cacheRefreshes)->toBe(['review_root:install'])
        ->and(Schema::hasTable('review_installer_rows'))->toBeTrue()
        ->and(DB::table('migrations')->where('migration', $migration)->count())->toBe(1);
    expect(Plugin::query()->where('name', 'review_root')->sole()->dependencies()->orderBy('name')->pluck('name')->all())
        ->toBe(['review_left', 'review_right']);

    expect(Artisan::call('review_root:install', ['--no-interaction' => true]))->toBe(0)
        ->and(TraversalTestInstallCommand::$visits)->toHaveCount(8)
        ->and(TraversalTestInstallCommand::$permissionRefreshes)->toHaveCount(2)
        ->and(DB::table('migrations')->where('migration', $migration)->count())->toBe(1);
});

it('clears traversal state after a failed dependency so a later command can retry', function (): void {
    $fail = true;
    registerTraversalPackages($this->installerRoot, ['review_root' => ['review_shared'], 'review_shared' => []], function (string $name) use (&$fail): void {
        if ($name === 'review_shared' && $fail) {
            throw new RuntimeException('Synthetic dependency failure');
        }
    });
    expect(fn () => Artisan::call('review_root:install', ['--no-interaction' => true]))->toThrow(RuntimeException::class, 'Synthetic dependency failure');
    expect(Plugin::query()->where('name', 'review_root')->exists())->toBeFalse()
        ->and(TraversalTestInstallCommand::$permissionRefreshes)->toBe([]);
    $fail = false;
    expect(Artisan::call('review_root:install', ['--no-interaction' => true]))->toBe(0)
        ->and(TraversalTestInstallCommand::$visits)->toBe(['review_root', 'review_shared', 'review_root', 'review_shared'])
        ->and(TraversalTestInstallCommand::$permissionRefreshes)->toBe(['review_root:install']);
});

it('rejects a dependency cycle and releases the traversal state', function (): void {
    $packages = registerTraversalPackages($this->installerRoot, ['review_root' => ['review_shared'], 'review_shared' => ['review_root']]);
    expect(fn () => Artisan::call('review_root:install', ['--no-interaction' => true]))->toThrow(RuntimeException::class, 'Circular plugin dependency');
    $packages['review_root']->dependencies = [];
    expect(Artisan::call('review_root:install', ['--no-interaction' => true]))->toBe(0)
        ->and(TraversalTestInstallCommand::$permissionRefreshes)->toBe(['review_root:install']);
});

it('keeps the caller execution limit when a console command initializes the admin panel', function (): void {
    $originalLimit = (int) ini_get('max_execution_time');
    try {
        set_time_limit(27);
        (new AdminPanelProvider(app()))->panel(Panel::make());
        expect((int) ini_get('max_execution_time'))->toBe(27);
    } finally {
        set_time_limit($originalLimit);
    }
});

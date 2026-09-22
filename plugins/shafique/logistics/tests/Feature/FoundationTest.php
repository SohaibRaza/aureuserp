<?php

use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Shafique\Logistics\Filament\Resources\ModuleSettingResource;
use Shafique\Logistics\Filament\Resources\ModuleSettingResource\Pages\ModuleSettings;
use Shafique\Logistics\LogisticsServiceProvider;
use Shafique\Logistics\Models\ControlChange;
use Shafique\Logistics\Services\OperationalControl;
use Symfony\Component\Process\Process;
use Webkul\PluginManager\Events\UninstallingPlugins;
use Webkul\PluginManager\Models\Plugin;
use Webkul\Security\Models\Permission;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../../../webkul/support/tests/Helpers/SecurityHelper.php';

beforeEach(function (): void {
    Http::preventStrayRequests();
    Mail::fake();
    Notification::fake();
});

it('discovers and installs the actual Shafique Logistics package', function (): void {
    expect(app()->getProvider(LogisticsServiceProvider::class))->not->toBeNull();
    expect(Schema::hasTable('logistics_company_controls'))->toBeFalse()
        ->and(Filament::getPanel('admin')->getResources())->not->toContain(ModuleSettingResource::class);
    expect(fn () => app(OperationalControl::class)->read(null, 1))->toThrow(DomainException::class, 'Logistics is not installed.');

    TestBootstrapHelper::ensurePluginInstalled('sales');
    TestBootstrapHelper::ensurePluginInstalled('inventories');
    $postingTables = ['sales_orders', 'inventories_moves', 'inventories_move_lines', 'accounts_account_moves', 'accounts_account_payments', 'recon_events'];
    $postingCounts = collect($postingTables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();

    $process = new Process([PHP_BINARY, 'artisan', 'logistics:install', '--no-interaction'], base_path());
    $process->setTimeout(180)->mustRun();
    expect(Plugin::query()->where('name', 'logistics')->sole()->is_installed)->toBeTruthy()
        ->and(Schema::hasTable('logistics_company_controls'))->toBeTrue();

    TestBootstrapHelper::ensurePluginInstalled('logistics');

    expect(Plugin::query()->where('name', 'logistics')->sole()->is_installed)->toBeTruthy()
        ->and(Filament::getPanel('admin')->hasPlugin('logistics'))->toBeTrue()
        ->and(Schema::hasTable('logistics_company_controls'))->toBeTrue()
        ->and(Schema::hasTable('logistics_control_changes'))->toBeTrue()
        ->and(Permission::query()->where('guard_name', 'web')->whereIn('name', ['view_any_logistics_module::setting', 'update_logistics_module::setting'])->count())->toBe(2);
    expect(Plugin::query()->where('name', 'logistics')->sole()->dependencies()->orderBy('name')->pluck('name')->all())->toBe(['accounts', 'inventories', 'products', 'sales']);
    expect(Plugin::query()->whereIn('name', ['accounts', 'inventories', 'products', 'sales', 'invoices', 'payments'])->where('is_installed', true)->count())->toBe(6);

    $counts = [Plugin::query()->count(), DB::table('plugin_dependencies')->count(), Permission::query()->count(), DB::table('migrations')->count()];
    $listeners = count(app('events')->getListeners(UninstallingPlugins::class));
    expect($listeners)->toBe(1);
    $process->mustRun();
    (new Process([PHP_BINARY, 'artisan', 'migrate', '--path=plugins/shafique/logistics/database/migrations', '--no-interaction'], base_path()))->setTimeout(60)->mustRun();
    expect([Plugin::query()->count(), DB::table('plugin_dependencies')->count(), Permission::query()->count(), DB::table('migrations')->count()])->toBe($counts)
        ->and(count(app('events')->getListeners(UninstallingPlugins::class)))->toBe($listeners)
        ->and(DB::table('logistics_company_controls')->count())->toBe(0)
        ->and(DB::table('logistics_control_changes')->count())->toBe(0);
    expect(collect($postingTables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all())->toBe($postingCounts);
});

it('changes only the current Company through the native settings action without posting', function (): void {
    TestBootstrapHelper::ensurePluginInstalled('logistics');
    $user = SecurityHelper::authenticateWithPermissions(['view_any_logistics_module::setting', 'update_logistics_module::setting']);
    Auth::shouldUse('web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    $companyId = $user->default_company_id;
    $tables = ['sales_orders', 'inventories_moves', 'inventories_move_lines', 'accounts_account_moves', 'accounts_account_payments', 'recon_events'];
    $counts = collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();

    expect(app(OperationalControl::class)->read($user, $companyId))->toBeFalse();
    expect(fn () => app(OperationalControl::class)->authorizeNewActivity($user, $companyId))->toThrow(DomainException::class);

    Livewire::test(ModuleSettings::class)->assertSuccessful()
        ->assertSee('Suspended')
        ->callAction('changeActivity', data: ['accept_new_activity' => true, 'reason' => 'Synthetic rollout authorization'])
        ->assertHasNoActionErrors()->assertSee('Active');

    app(OperationalControl::class)->authorizeNewActivity($user, $companyId);
    $history = ControlChange::query()->sole();
    expect($history->company_id)->toBe($companyId)
        ->and($history->actor_id)->toBe($user->id)
        ->and($history->reason)->toBe('Synthetic rollout authorization')
        ->and($history->created_at)->not->toBeNull();
    expect(collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all())->toBe($counts);

    Livewire::test(ModuleSettings::class)
        ->callAction('changeActivity', data: ['accept_new_activity' => false, 'reason' => 'Synthetic suspension'])
        ->assertHasNoActionErrors()->assertSee('Suspended');
    expect(ControlChange::query()->count())->toBe(2);
    expect(app(OperationalControl::class)->read($user, $companyId))->toBeFalse();
});

it('does not grant parent access to a branch and keeps permitted Company settings separate', function (): void {
    TestBootstrapHelper::ensurePluginInstalled('logistics');
    $user = SecurityHelper::authenticateWithPermissions(['view_any_logistics_module::setting', 'update_logistics_module::setting']);
    $parentId = $user->default_company_id;
    $branch = Company::factory()->create(['parent_id' => $parentId]);
    $user->allowedCompanies()->sync([$parentId]);
    $control = app(OperationalControl::class);
    expect(fn () => $control->change($user, $branch->id, true, 'Unauthorized branch'))->toThrow(AuthorizationException::class);
    $control->change($user, $parentId, true, 'Parent only');
    $user->allowedCompanies()->syncWithoutDetaching([$branch->id]);
    expect($control->read($user, $branch->id))->toBeFalse()
        ->and($control->read($user, $parentId))->toBeTrue();
    $control->change($user, $branch->id, true, 'Branch separately approved');
    $control->change($user, $branch->id, false, 'Branch suspended');
    expect($control->read($user, $parentId))->toBeTrue()
        ->and($control->read($user, $branch->id))->toBeFalse();
});

it('denies direct unauthorized page and service mutations', function (): void {
    TestBootstrapHelper::ensurePluginInstalled('logistics');
    $user = SecurityHelper::authenticateWithPermissions([]);
    Auth::shouldUse('web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    Livewire::test(ModuleSettings::class)->assertForbidden();
    expect(fn () => app(OperationalControl::class)->change($user, $user->default_company_id, true, 'Denied'))->toThrow(AuthorizationException::class);
    expect(ControlChange::query()->count())->toBe(0);
});

it('rejects invalid reasons and rolls back the control when history persistence fails', function (): void {
    TestBootstrapHelper::ensurePluginInstalled('logistics');
    $user = SecurityHelper::authenticateWithPermissions(['view_any_logistics_module::setting', 'update_logistics_module::setting']);
    $control = app(OperationalControl::class);
    expect(fn () => $control->change($user, $user->default_company_id, true, '   '))->toThrow(ValidationException::class);
    expect(fn () => $control->change($user, $user->default_company_id, true, str_repeat('a', 1001)))->toThrow(ValidationException::class);
    $event = 'eloquent.creating: '.ControlChange::class;
    Event::listen($event, fn () => throw new FoundationHistoryFailure('Synthetic history failure'));
    try {
        expect(fn () => $control->change($user, $user->default_company_id, true, 'Must roll back'))->toThrow(FoundationHistoryFailure::class);
    } finally {
        Event::forget($event);
    }
    expect(DB::table('logistics_company_controls')->count())->toBe(0)
        ->and(ControlChange::query()->count())->toBe(0);
    $control->change($user, $user->default_company_id, true, 'Successful retry');
    $control->change($user, $user->default_company_id, true, 'Identical state retry');
    expect(ControlChange::query()->count())->toBe(1)
        ->and(ControlChange::query()->sole()->old_value)->toBeFalse()
        ->and(ControlChange::query()->sole()->new_value)->toBeTrue();
    expect(fn () => ControlChange::query()->sole()->delete())->toThrow(LogicException::class);
});

it('rejects tampering with the Company selected by the native context', function (): void {
    TestBootstrapHelper::ensurePluginInstalled('logistics');
    SecurityHelper::authenticateWithPermissions(['view_any_logistics_module::setting', 'update_logistics_module::setting']);
    Auth::shouldUse('web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    expect(fn () => Livewire::test(ModuleSettings::class)->set('companyId', 999999))->toThrow(CannotUpdateLockedPropertyException::class);
    expect(ControlChange::query()->count())->toBe(0);
});

final class FoundationHistoryFailure extends LogicException {}

it('keeps read-only access while preventing the settings action', function (): void {
    TestBootstrapHelper::ensurePluginInstalled('logistics');
    $user = SecurityHelper::authenticateWithPermissions(['view_any_logistics_module::setting']);
    Auth::shouldUse('web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    Livewire::test(ModuleSettings::class)->assertSuccessful()->assertActionHidden('changeActivity');
    expect(fn () => app(OperationalControl::class)->change($user, $user->default_company_id, true, 'Denied'))->toThrow(AuthorizationException::class);
});

it('denies a stale page and service access after the installed flag is removed', function (): void {
    TestBootstrapHelper::ensurePluginInstalled('logistics');
    $user = SecurityHelper::authenticateWithPermissions(['view_any_logistics_module::setting', 'update_logistics_module::setting']);
    Auth::shouldUse('web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    Plugin::query()->where('name', 'logistics')->update(['is_installed' => false]);
    Livewire::test(ModuleSettings::class)->assertForbidden();
    expect(fn () => app(OperationalControl::class)->change($user, $user->default_company_id, true, 'Not installed'))->toThrow(DomainException::class);
    expect(ControlChange::query()->count())->toBe(0)
        ->and(Company::query()->whereKey($user->default_company_id)->exists())->toBeTrue();
});

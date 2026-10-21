<?php

use Illuminate\Support\Facades\DB;
use Shafique\Logistics\Models\CompanyControl;
use Shafique\Logistics\Models\ControlChange;
use Shafique\Logistics\Services\OperationalControl;
use Symfony\Component\Process\Process;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../../webkul/support/tests/Helpers/SecurityHelper.php';

it('serializes suspension with the entire authorized business transaction', function (string $firstAction): void {
    if (! config('testing.run_reconciliation_concurrency')) {
        test()->markTestSkipped('Run explicitly on an isolated PostgreSQL or MySQL test database.');
    }
    $connection = DB::connection();
    $driver = $connection->getDriverName();
    expect($driver)->toBeIn(['pgsql', 'mysql']);
    $database = $connection->getDatabaseName();
    $connectionConfig = config("database.connections.{$driver}");
    expect($database)->toMatch('/^aureuserp_[A-Za-z0-9_]+_testing$/');
    TestBootstrapHelper::ensurePluginInstalled('logistics');
    $user = SecurityHelper::authenticateWithPermissions(['view_any_logistics_module::setting', 'update_logistics_module::setting']);
    $company = Company::factory()->create([
        'is_active'   => true,
        'currency_id' => DB::table('companies')->where('id', $user->default_company_id)->value('currency_id'),
    ]);
    $companyId = $company->id;
    $user->forceFill(['default_company_id' => $companyId])->saveQuietly();
    $user->allowedCompanies()->sync([$companyId]);
    session([CompanyContext::SESSION_KEY => [$companyId]]);
    app()->forgetInstance(CompanyContext::class);
    app(OperationalControl::class)->change($user, $companyId, true, 'Synthetic start');
    $originalName = DB::table('companies')->where('id', $companyId)->value('name');
    while (DB::transactionLevel() > 0) {
        DB::commit();
    }
    $directory = sys_get_temp_dir().'/aureuserp-suspension-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $worker = <<<'PHP'
$base = $argv[1];
chdir($base);
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Auth::loginUsingId((int) $argv[5]);
$directory = $argv[2];
$number = $argv[3];
$action = $argv[4];
$companyId = (int) $argv[6];
$connection = Illuminate\Support\Facades\DB::connection();
$identityQuery = $connection->getDriverName() === 'pgsql' ? 'SELECT pg_backend_pid() AS id' : 'SELECT CONNECTION_ID() AS id';
file_put_contents($directory.'/ready-'.$number, (string) $connection->selectOne($identityQuery)->id);
try {
    $connection->transaction(function () use ($directory, $number, $action, $companyId): void {
        $control = app(Shafique\Logistics\Services\OperationalControl::class);
        if ($action === 'command') {
            $revision = $control->authorizeNewActivity(auth()->user(), $companyId);
            if ($revision !== 1) { throw new RuntimeException('Unexpected control revision.'); }
        } else {
            $control->change(auth()->user(), $companyId, false, 'Synthetic suspension');
        }
        if ($number === '1') {
            touch($directory.'/holding');
            $deadline = microtime(true) + 20;
            while (! file_exists($directory.'/release')) {
                if (microtime(true) > $deadline) { throw new RuntimeException('Suspension test release timed out.'); }
                usleep(10000);
            }
        }
        if ($action === 'command') {
            // A synthetic business write after authorization proves the lock outlives the check.
            Illuminate\Support\Facades\DB::table('companies')->where('id', $companyId)->update(['name' => 'Synthetic committed Logistics work']);
        }
    });
    echo json_encode(['result' => 'accepted']);
} catch (DomainException $error) {
    echo json_encode(['result' => 'rejected', 'error' => $error->getMessage()]);
}
PHP;
    $waitFor = function (Closure $condition): void {
        $deadline = microtime(true) + 20;
        while (! $condition()) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Suspension workers did not reach the expected lock boundary.');
            }
            usleep(10000);
        }
    };
    $processes = [];
    try {
        foreach ([1 => $firstAction, 2 => $firstAction === 'command' ? 'suspend' : 'command'] as $number => $action) {
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=512M', '-r', $worker, base_path(), $directory, (string) $number, $action, (string) $user->id, (string) $companyId], base_path(), [
                ...$_ENV,
                'APP_ENV'                       => 'testing',
                'DB_CONNECTION'                 => $driver,
                'DB_HOST'                       => (string) ($connectionConfig['host'] ?? ''),
                'DB_PORT'                       => (string) ($connectionConfig['port'] ?? ''),
                'DB_SOCKET'                     => (string) ($connectionConfig['unix_socket'] ?? ''),
                'DB_URL'                        => '',
                'DB_USERNAME'                   => (string) ($connectionConfig['username'] ?? ''),
                'DB_PASSWORD'                   => (string) ($connectionConfig['password'] ?? ''),
                'DB_DATABASE'                   => $database,
                'TEST_TOKEN'                    => '',
                'AUREUSERP_TEST_REUSE_DATABASE' => '1',
            ], timeout: 40);
            $processes[] = $process;
            $process->start();
            $waitFor(fn (): bool => file_exists($directory.'/ready-'.$number));
            if ($number === 1) {
                $waitFor(fn (): bool => file_exists($directory.'/holding'));
            }
        }
        $firstConnectionId = (int) file_get_contents($directory.'/ready-1');
        $secondConnectionId = (int) file_get_contents($directory.'/ready-2');
        $waitFor(function () use ($driver, $firstConnectionId, $secondConnectionId): bool {
            if ($driver === 'pgsql') {
                return (bool) DB::selectOne('SELECT ? = ANY(pg_blocking_pids(?)) AS blocked', [$firstConnectionId, $secondConnectionId])->blocked;
            }

            return (bool) DB::selectOne('SELECT EXISTS (
                SELECT 1
                FROM performance_schema.data_lock_waits AS waits
                JOIN performance_schema.threads AS requesting
                    ON requesting.THREAD_ID = waits.REQUESTING_THREAD_ID
                JOIN performance_schema.threads AS blocking
                    ON blocking.THREAD_ID = waits.BLOCKING_THREAD_ID
                WHERE requesting.PROCESSLIST_ID = ?
                    AND blocking.PROCESSLIST_ID = ?
            ) AS blocked', [$secondConnectionId, $firstConnectionId])->blocked;
        });
        touch($directory.'/release');
        $outcomes = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
            $outcomes[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }
        expect($outcomes[0]['result'])->toBe('accepted')
            ->and($outcomes[1]['result'])->toBe($firstAction === 'command' ? 'accepted' : 'rejected')
            ->and(DB::table('companies')->where('id', $companyId)->value('name'))
            ->toBe($firstAction === 'command' ? 'Synthetic committed Logistics work' : $originalName)
            ->and(CompanyControl::query()->where('company_id', $companyId)->sole()->accept_new_activity)->toBeFalse()
            ->and(ControlChange::query()->where('company_id', $companyId)->orderBy('revision')->pluck('revision')->all())->toBe([1, 2]);
        if ($firstAction === 'suspend') {
            expect($outcomes[1]['error'])->toBe(__('logistics::settings.activity_suspended'));
        }
    } finally {
        touch($directory.'/release');
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        foreach (glob($directory.'/*') as $path) {
            unlink($path);
        }
        rmdir($directory);
        DB::table('companies')->where('id', $companyId)->update(['name' => $originalName]);
        // Committed immutable history belongs to this dedicated synthetic Company.
        // The private database teardown removes it after the concurrency selection.
        DB::table('logistics_company_controls')->where('company_id', $companyId)->delete();
    }
})->with(['command', 'suspend']);

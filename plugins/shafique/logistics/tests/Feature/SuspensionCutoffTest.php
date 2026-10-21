<?php

use Illuminate\Support\Facades\DB;
use Shafique\Logistics\Models\ControlChange;
use Shafique\Logistics\Services\OperationalControl;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../../../../webkul/support/tests/Helpers/SecurityHelper.php';

it('serializes suspension with the entire authorized business transaction', function (string $firstAction): void {
    if (! config('testing.run_reconciliation_concurrency')) {
        test()->markTestSkipped('Run explicitly on the isolated PostgreSQL test database.');
    }
    expect(DB::connection()->getDriverName())->toBe('pgsql');
    $database = DB::connection()->getDatabaseName();
    expect($database)->toMatch('/^aureuserp_[A-Za-z0-9_]+_testing$/');
    TestBootstrapHelper::ensurePluginInstalled('logistics');
    $user = SecurityHelper::authenticateWithPermissions(['view_any_logistics_module::setting', 'update_logistics_module::setting']);
    $companyId = $user->default_company_id;
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
file_put_contents($directory.'/ready-'.$number, (string) $connection->selectOne('SELECT pg_backend_pid() AS pid')->pid);
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
                ...$_ENV, 'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql',
                'DB_HOST'           => config('database.connections.pgsql.host'), 'DB_PORT' => (string) config('database.connections.pgsql.port'),
                'DB_USERNAME'       => config('database.connections.pgsql.username'), 'DB_PASSWORD' => config('database.connections.pgsql.password'),
                'DB_DATABASE'       => $database, 'AUREUSERP_TEST_REUSE_DATABASE' => '1',
            ], timeout: 40);
            $processes[] = $process;
            $process->start();
            $waitFor(fn (): bool => file_exists($directory.'/ready-'.$number));
            if ($number === 1) {
                $waitFor(fn (): bool => file_exists($directory.'/holding'));
            }
        }
        $firstPid = (int) file_get_contents($directory.'/ready-1');
        $secondPid = (int) file_get_contents($directory.'/ready-2');
        $waitFor(fn (): bool => (bool) DB::selectOne('SELECT ? = ANY(pg_blocking_pids(?)) AS blocked', [$firstPid, $secondPid])->blocked);
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
            ->and(DB::table('logistics_company_controls')->where('company_id', $companyId)->value('accept_new_activity'))->toBeFalse()
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
        DB::table('logistics_control_changes')->where('company_id', $companyId)->delete();
        DB::table('logistics_company_controls')->where('company_id', $companyId)->delete();
    }
})->with(['command', 'suspend']);

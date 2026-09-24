<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Process\Process;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Events\MovePaid;
use Webkul\Account\Models\PaymentRegister;
use Webkul\PluginManager\Models\Plugin;
use Webkul\PluginManager\Package;

require_once __DIR__.'/../../Helpers/AccountHelper.php';

it('serializes payment instructions with committed replay and residual evidence', function (string $scenario): void {
    $sameInstruction = $scenario === 'same instruction';
    $sameRequest = $scenario === 'same request';
    $requestKey = $sameRequest ? 'race-'.fake()->uuid() : '';
    if (! config('testing.run_reconciliation_concurrency') || DB::connection()->getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requires the opted-in disposable PostgreSQL runner.');
    }
    TestBootstrapHelper::ensurePluginInstalled('accounts');
    DB::table('plugins')->updateOrInsert(['name' => 'accounts'], ['is_installed' => true, 'is_active' => true, 'updated_at' => now()]);
    Package::$plugins = Plugin::all()->keyBy('name');
    URL::resolveMissingNamedRoutesUsing(fn () => '#');
    AccountHelper::actingAsAdmin();
    Event::fake([MovePaid::class]);
    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE, AccountHelper::partner());
    AccountHelper::productLine($invoice, AccountHelper::account('income'), qty: 2, priceUnit: 100);
    AccountHelper::post($invoice);
    AccountHelper::pay($invoice, 20);
    $template = PaymentRegister::query()->latest('id')->firstOrFail();
    $lineIds = $template->lines()->pluck('accounts_account_move_lines.id')->all();
    $first = PaymentRegister::create([...Arr::only($template->getAttributes(), $template->getFillable()), 'amount' => 120]);
    $first->lines()->sync($lineIds);
    $second = $sameInstruction ? $first : PaymentRegister::create([...Arr::only($template->getAttributes(), $template->getFillable()), 'amount' => 120]);
    if (! $sameInstruction) {
        $second->lines()->sync($lineIds);
    }
    $database = DB::connection()->getDatabaseName();
    expect($database)->toMatch('/^aureuserp_[A-Za-z0-9_]+_testing$/');
    while (DB::connection()->transactionLevel() > 0) {
        DB::connection()->commit();
    }
    $directory = sys_get_temp_dir().'/payment-race-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Auth::loginUsingId((int) $argv[5]);
Illuminate\Support\Facades\URL::resolveMissingNamedRoutesUsing(fn () => '#');
Illuminate\Support\Facades\Event::forget(Webkul\Account\Events\MovePaid::class);
Illuminate\Support\Facades\Event::listen(Webkul\Account\Events\MovePaid::class, function () use ($argv): void {
    file_put_contents($argv[2].'/notification-'.$argv[3], 'committed');
});
$connection = Illuminate\Support\Facades\DB::connection();
$pid = $connection->selectOne('SELECT pg_backend_pid() AS id')->id;
$register = Webkul\Account\Models\PaymentRegister::findOrFail((int) $argv[4]);
file_put_contents($argv[2].'/ready-'.$argv[3], (string) $pid);
$deadline = microtime(true) + 20;
while (! file_exists($argv[2].'/go')) {
    if (microtime(true) > $deadline) { throw new RuntimeException('Start barrier timed out'); }
    usleep(10000);
}
if ($argv[3] === '0') {
    Webkul\Account\Models\Payment::creating(function () use ($argv): void {
        touch($argv[2].'/holding');
        $deadline = microtime(true) + 20;
        while (! file_exists($argv[2].'/release')) {
            if (microtime(true) > $deadline) { throw new RuntimeException('Payment lock release timed out'); }
            usleep(10000);
        }
    });
} else {
    $deadline = microtime(true) + 20;
    while (! file_exists($argv[2].'/holding')) {
        if (microtime(true) > $deadline) { throw new RuntimeException('First payment did not hold the source'); }
        usleep(10000);
    }
}
try {
    $ids = $connection->transaction(function () use ($register, $argv): array {
        $service = app(Webkul\Account\Services\PaymentRegistrar::class);
        $result = $argv[6] === ''
            ? $service->register($register)
            : $service->registerForMove($register->lines->first()->move, Illuminate\Support\Arr::only($register->getAttributes(), $register->getFillable()), $register->lines->pluck('id')->all(), $argv[6], ['amount' => 120]);
        if (file_exists($argv[2].'/notification-'.$argv[3])) { throw new RuntimeException('Notification escaped before commit'); }
        return $result;
    });
    echo json_encode(['ok' => true, 'ids' => $ids, 'pid' => $pid]);
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'error' => $error->getMessage(), 'pid' => $pid]);
}
PHP;
    $processes = [];
    try {
        foreach ([$first, $second] as $index => $register) {
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=512M', '-r', $worker, base_path(), $directory, (string) $index, (string) $register->id, (string) auth()->id(), $requestKey], base_path(), [...$_ENV, 'AUREUSERP_TEST_REUSE_DATABASE' => '1'], timeout: 40);
            $processes[] = $process;
            $process->start();
        }
        $deadline = microtime(true) + 25;
        while (count(glob($directory.'/ready-*')) !== 2) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Workers failed to initialize: '.implode(' ', array_map(fn (Process $process): string => $process->getErrorOutput(), $processes)));
            }
            usleep(10000);
        }
        touch($directory.'/go');
        $secondPid = (int) file_get_contents($directory.'/ready-1');
        $deadline = microtime(true) + 20;
        $contended = false;
        while (microtime(true) < $deadline) {
            $contended = (bool) DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE pid = ? AND wait_event_type = 'Lock') AS blocked", [$secondPid])->blocked;
            if ($contended || ! $processes[1]->isRunning()) {
                break;
            }
            usleep(10000);
        }
        touch($directory.'/release');
        $outcomes = collect($processes)->map(function (Process $process): array {
            $process->wait();
            expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

            return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        });
        expect($contended)->toBeTrue()
            ->and($outcomes->pluck('pid')->unique())->toHaveCount(2)
            ->and($outcomes->where('ok', true))->toHaveCount(($sameInstruction || $sameRequest) ? 2 : 1)
            ->and((float) $invoice->fresh()->amount_residual)->toBe(60.0)
            ->and($invoice->matchedPayments()->count())->toBe(2)
            ->and(glob($directory.'/notification-*'))->toHaveCount(1);
        if ($sameInstruction || $sameRequest) {
            expect($outcomes[0]['ids'])->toBe($outcomes[1]['ids']);
        } else {
            expect($outcomes->firstWhere('ok', false)['error'])->toContain('remaining source amount');
        }
        $completed = DB::table('accounts_payment_registers')
            ->when($sameRequest, fn ($query) => $query->where('request_key', $requestKey), fn ($query) => $query->whereIn('id', [$first->id, $second->id]))
            ->where('registration_state', 'completed')->get();
        expect($completed)->toHaveCount(1)
            ->and(json_decode($completed->first()->registered_payment_ids, true))->toHaveCount(1);
    } finally {
        touch($directory.'/go');
        touch($directory.'/release');
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        foreach (glob($directory.'/*') as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
})->with(['same instruction', 'distinct instructions', 'same request']);

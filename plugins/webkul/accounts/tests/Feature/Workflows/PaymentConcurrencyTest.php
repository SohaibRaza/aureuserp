<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Process\Process;
use Webkul\Account\Enums\AccountType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Enums\PaymentStatus;
use Webkul\Account\Events\MovePaid;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\Payment;
use Webkul\Account\Models\PaymentRegister;
use Webkul\Account\Services\PaymentRegistrar;
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

it('serializes payment against reverse and cancel on the same invoice', function (): void {
    if (! config('testing.run_reconciliation_concurrency') || DB::connection()->getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requires the opted-in disposable PostgreSQL runner.');
    }
    TestBootstrapHelper::ensurePluginInstalled('accounts');
    DB::table('plugins')->updateOrInsert(['name' => 'accounts'], ['is_installed' => true, 'is_active' => true, 'updated_at' => now()]);
    Package::$plugins = Plugin::all()->keyBy('name');
    URL::resolveMissingNamedRoutesUsing(fn () => '#');
    AccountHelper::actingAsAdmin();
    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE, AccountHelper::partner());
    AccountHelper::productLine($invoice, AccountHelper::account('income'), qty: 2, priceUnit: 100);
    AccountHelper::post($invoice);
    $paymentCount = Payment::count();
    expect(DB::connection()->getDatabaseName())->toMatch('/^aureuserp_[A-Za-z0-9_]+_testing$/');
    while (DB::connection()->transactionLevel() > 0) {
        DB::connection()->commit();
    }

    $directory = sys_get_temp_dir().'/payment-reversal-race-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Auth::loginUsingId((int) $argv[5]);
Illuminate\Support\Facades\URL::resolveMissingNamedRoutesUsing(fn () => '#');
$connection = Illuminate\Support\Facades\DB::connection();
$pid = $connection->selectOne('SELECT pg_backend_pid() AS id')->id;
$role = $argv[3];
file_put_contents($argv[2].'/ready-'.$role, (string) $pid);
$deadline = microtime(true) + 20;
while (! file_exists($argv[2].'/go')) {
    if (microtime(true) > $deadline) { throw new RuntimeException('Start barrier timed out'); }
    usleep(10000);
}
if ($role === 'payment') {
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
        if (microtime(true) > $deadline) { throw new RuntimeException('Payment did not acquire the source lock'); }
        usleep(10000);
    }
}
try {
    $invoice = Webkul\Account\Models\Move::findOrFail((int) $argv[4]);
    if ($role === 'payment') {
        require $argv[1].'/plugins/webkul/accounts/tests/Helpers/AccountHelper.php';
        AccountHelper::pay($invoice, 60);
    } else {
        Webkul\Account\Facades\Account::reverseMoves(collect([$invoice]), [], true);
    }
    echo json_encode(['ok' => true, 'pid' => $pid]);
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'error' => $error->getMessage(), 'pid' => $pid]);
}
PHP;
    $processes = [];
    try {
        foreach (['payment', 'reversal'] as $role) {
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=512M', '-r', $worker, base_path(), $directory, $role, (string) $invoice->id, (string) auth()->id()], base_path(), [...$_ENV, 'AUREUSERP_TEST_REUSE_DATABASE' => '1'], timeout: 40);
            $processes[$role] = $process;
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
        $reversalPid = (int) file_get_contents($directory.'/ready-reversal');
        $deadline = microtime(true) + 20;
        $blocked = false;
        while (microtime(true) < $deadline) {
            $blocked = (bool) DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE pid = ? AND wait_event_type = 'Lock') AS blocked", [$reversalPid])->blocked;
            if ($blocked || ! $processes['reversal']->isRunning()) {
                break;
            }
            usleep(10000);
        }
        touch($directory.'/release');
        $outcomes = [];
        foreach ($processes as $role => $process) {
            $process->wait();
            expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
            $outcomes[$role] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }
        expect($blocked)->toBeTrue()
            ->and($outcomes['payment']['ok'])->toBeTrue()
            ->and($outcomes['reversal']['ok'])->toBeFalse()
            ->and($outcomes['reversal']['error'])->toContain('paid journal entry cannot be reversed and cancelled')
            ->and((float) $invoice->fresh()->amount_residual)->toBe(140.0)
            ->and($invoice->matchedPayments()->count())->toBe(1)
            ->and(Move::where('reversed_entry_id', $invoice->id)->count())->toBe(0)
            ->and(Payment::count())->toBe($paymentCount + 1);
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
});

it('serializes competing posts of the same preloaded draft with one rank and confirmation', function (): void {
    if (! config('testing.run_reconciliation_concurrency') || DB::connection()->getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requires the opted-in disposable PostgreSQL runner.');
    }
    TestBootstrapHelper::ensurePluginInstalled('accounts');
    DB::table('plugins')->updateOrInsert(['name' => 'accounts'], ['is_installed' => true, 'is_active' => true, 'updated_at' => now()]);
    Package::$plugins = Plugin::all()->keyBy('name');
    URL::resolveMissingNamedRoutesUsing(fn () => '#');
    AccountHelper::actingAsAdmin();
    $partner = AccountHelper::partner();
    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE, $partner);
    AccountHelper::productLine($invoice, AccountHelper::account('income'), qty: 2, priceUnit: 100);
    $beforeRank = (int) $partner->fresh()->customer_rank;
    expect(DB::connection()->getDatabaseName())->toMatch('/^aureuserp_[A-Za-z0-9_]+_testing$/');
    while (DB::connection()->transactionLevel() > 0) {
        DB::connection()->commit();
    }

    $directory = sys_get_temp_dir().'/posting-race-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Auth::loginUsingId((int) $argv[5]);
Illuminate\Support\Facades\URL::resolveMissingNamedRoutesUsing(fn () => '#');
$connection = Illuminate\Support\Facades\DB::connection();
$pid = $connection->selectOne('SELECT pg_backend_pid() AS id')->id;
$move = Webkul\Account\Models\Move::with('lines')->findOrFail((int) $argv[4]);
Illuminate\Support\Facades\Event::listen(Webkul\Account\Events\MoveConfirmed::class, function () use ($argv): void {
    file_put_contents($argv[2].'/confirmation-'.$argv[3], 'confirmed');
    if ($argv[3] !== '0') { return; }
    touch($argv[2].'/holding');
    $deadline = microtime(true) + 20;
    while (! file_exists($argv[2].'/release')) {
        if (microtime(true) > $deadline) { throw new RuntimeException('Posting lock release timed out'); }
        usleep(10000);
    }
});
file_put_contents($argv[2].'/ready-'.$argv[3], (string) $pid);
$deadline = microtime(true) + 20;
while (! file_exists($argv[2].'/go')) {
    if (microtime(true) > $deadline) { throw new RuntimeException('Start barrier timed out'); }
    usleep(10000);
}
if ($argv[3] === '1') {
    $deadline = microtime(true) + 20;
    while (! file_exists($argv[2].'/holding')) {
        if (microtime(true) > $deadline) { throw new RuntimeException('First posting did not hold the source'); }
        usleep(10000);
    }
}
try {
    app(Webkul\Account\Services\MoveWorkflow::class)->post($move);
    echo json_encode(['ok' => true, 'pid' => $pid]);
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'error' => $error->getMessage(), 'pid' => $pid]);
}
PHP;
    $processes = [];
    try {
        foreach ([0, 1] as $index) {
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=512M', '-r', $worker, base_path(), $directory, (string) $index, (string) $invoice->id, (string) auth()->id()], base_path(), [...$_ENV, 'AUREUSERP_TEST_REUSE_DATABASE' => '1'], timeout: 40);
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
        $blocked = false;
        while (microtime(true) < $deadline) {
            $blocked = (bool) DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE pid = ? AND wait_event_type = 'Lock') AS blocked", [$secondPid])->blocked;
            if ($blocked || ! $processes[1]->isRunning()) {
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
        expect($blocked)->toBeTrue()
            ->and($outcomes->pluck('pid')->unique())->toHaveCount(2)
            ->and($outcomes->where('ok', true))->toHaveCount(1)
            ->and($outcomes->firstWhere('ok', false)['error'])->toBe(__('accounts::account-manager.post-action-validate.draft-state-required'))
            ->and($invoice->fresh()->state)->toBe(MoveState::POSTED)
            ->and((float) $invoice->fresh()->amount_total)->toBe(200.0)
            ->and($invoice->lines()->where('parent_state', '!=', MoveState::POSTED)->count())->toBe(0)
            ->and((int) $partner->fresh()->customer_rank)->toBe($beforeRank + 1)
            ->and(glob($directory.'/confirmation-*'))->toHaveCount(1);
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
});

it('serializes reconciliation and lifecycle boundaries on persisted move roots', function (string $scenario): void {
    $paymentConfirmation = $scenario === 'payment confirmation versus cancel';
    if (! config('testing.run_reconciliation_concurrency') || DB::connection()->getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requires the opted-in disposable PostgreSQL runner.');
    }
    TestBootstrapHelper::ensurePluginInstalled('accounts');
    DB::table('plugins')->updateOrInsert(['name' => 'accounts'], ['is_installed' => true, 'is_active' => true, 'updated_at' => now()]);
    Package::$plugins = Plugin::all()->keyBy('name');
    URL::resolveMissingNamedRoutesUsing(fn () => '#');
    AccountHelper::actingAsAdmin();
    $partner = AccountHelper::partner();
    $invoice = AccountHelper::invoice(MoveType::OUT_INVOICE, $partner);
    AccountHelper::productLine($invoice, AccountHelper::account('income'), qty: 1, priceUnit: 200);
    if ($scenario !== 'post versus cancel') {
        AccountHelper::post($invoice);
    }
    if ($paymentConfirmation) {
        AccountHelper::pay($invoice, 60);
        $payment = $invoice->matchedPayments()->sole();
        app(PaymentRegistrar::class)->resetToDraftPayment($payment);
        $invoice = $payment->fresh()->move;
        $confirmationState = $payment->outstandingAccount->account_type === AccountType::ASSET_CASH ? PaymentStatus::PAID : PaymentStatus::IN_PROCESS;
    }
    $creditIds = [];
    if (in_array($scenario, ['competing reconciliations', 'reconciliation versus reset'], true)) {
        foreach ([0, 1] as $index) {
            $credit = AccountHelper::invoice(MoveType::OUT_REFUND, $partner);
            AccountHelper::productLine($credit, AccountHelper::account('income'), qty: 1, priceUnit: 200);
            AccountHelper::post($credit);
            $creditIds[$index] = $credit->id;
        }
    }
    expect(DB::connection()->getDatabaseName())->toMatch('/^aureuserp_[A-Za-z0-9_]+_testing$/');
    while (DB::connection()->transactionLevel() > 0) {
        DB::connection()->commit();
    }
    $directory = sys_get_temp_dir().'/account-boundary-race-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Auth::loginUsingId((int) $argv[5]);
Illuminate\Support\Facades\URL::resolveMissingNamedRoutesUsing(fn () => '#');
$connection = Illuminate\Support\Facades\DB::connection();
$pid = $connection->selectOne('SELECT pg_backend_pid() AS id')->id;
$move = Webkul\Account\Models\Move::with('paymentTermLines')->findOrFail((int) $argv[4]);
$scenario = $argv[6];
$index = $argv[3];
if ($scenario === 'payment confirmation versus cancel') {
    $payment = Webkul\Account\Models\Payment::findOrFail($move->origin_payment_id);
}
if (in_array($scenario, ['competing reconciliations', 'reconciliation versus reset'], true)) {
    $credit = Webkul\Account\Models\Move::with('paymentTermLines')->findOrFail((int) $argv[7]);
    $lines = $move->paymentTermLines->merge($credit->paymentTermLines);
}
if ($index === '0') {
    $hold = function () use ($argv): void {
        touch($argv[2].'/holding');
        $deadline = microtime(true) + 20;
        while (! file_exists($argv[2].'/release')) {
            if (microtime(true) > $deadline) { throw new RuntimeException('Accounting lock release timed out'); }
            usleep(10000);
        }
    };
    if (in_array($scenario, ['competing reconciliations', 'reconciliation versus reset'], true)) {
        Webkul\Account\Models\PartialReconcile::created($hold);
    } elseif ($scenario === 'payment versus reset') {
        Webkul\Account\Models\Payment::creating($hold);
    } elseif ($scenario === 'payment confirmation versus cancel') {
        Webkul\Account\Models\Payment::saved($hold);
    } else {
        Illuminate\Support\Facades\Event::listen(Webkul\Account\Events\MoveConfirmed::class, $hold);
    }
}
file_put_contents($argv[2].'/ready-'.$index, (string) $pid);
$deadline = microtime(true) + 20;
while (! file_exists($argv[2].'/go') || ($index === '1' && ! file_exists($argv[2].'/holding'))) {
    if (microtime(true) > $deadline) { throw new RuntimeException('Accounting start barrier timed out'); }
    usleep(10000);
}
try {
    if (in_array($scenario, ['competing reconciliations', 'reconciliation versus reset'], true)) {
        if ($scenario === 'reconciliation versus reset' && $index === '1') {
            app(Webkul\Account\Services\MoveWorkflow::class)->resetToDraft($move);
        } else {
            app(Webkul\Account\Services\Reconciler::class)->reconcile($lines);
        }
    } elseif ($scenario === 'payment versus reset') {
        if ($index === '0') {
            require $argv[1].'/plugins/webkul/accounts/tests/Helpers/AccountHelper.php';
            AccountHelper::pay($move, 60);
        } else {
            app(Webkul\Account\Services\MoveWorkflow::class)->resetToDraft($move);
        }
    } elseif ($scenario === 'payment confirmation versus cancel') {
        $connection->transaction(function () use ($payment, $index): void {
            $registrar = app(Webkul\Account\Services\PaymentRegistrar::class);
            if ($index === '0') {
                $registrar->postPayment($payment);
                app(Webkul\Account\Services\MoveWorkflow::class)->post($payment->move);
            } else {
                $registrar->cancelPayment($payment);
            }
        });
    } else {
        $workflow = app(Webkul\Account\Services\MoveWorkflow::class);
        $index === '0' ? $workflow->post($move) : $workflow->cancel($move);
    }
    echo json_encode(['ok' => true, 'pid' => $pid]);
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'pid' => $pid, 'error' => $error->getMessage()]);
}
PHP;
    $processes = [];
    try {
        foreach ([0, 1] as $index) {
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=512M', '-r', $worker, base_path(), $directory, (string) $index,
                (string) $invoice->id, (string) auth()->id(), $scenario, (string) ($creditIds[$index] ?? 0)], base_path(),
                [...$_ENV, 'AUREUSERP_TEST_REUSE_DATABASE' => '1'], timeout: 40);
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
        $blocked = false;
        while (microtime(true) < $deadline) {
            $blocked = (bool) DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE pid = ? AND wait_event_type = 'Lock') AS blocked", [$secondPid])->blocked;
            if ($blocked || ! $processes[1]->isRunning()) {
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
        $reset = in_array($scenario, ['payment versus reset', 'reconciliation versus reset'], true);
        expect($blocked)->toBeTrue()
            ->and($outcomes->pluck('pid')->unique())->toHaveCount(2)
            ->and($outcomes[0]['ok'])->toBeTrue()
            ->and($outcomes[1]['ok'])->toBe($reset)
            ->and($invoice->fresh()->state)->toBe($reset ? MoveState::DRAFT : MoveState::POSTED)
            ->and((float) $invoice->fresh()->amount_residual)->toBe($paymentConfirmation || $scenario === 'competing reconciliations' ? 0.0 : 200.0);
        if ($paymentConfirmation) {
            expect($outcomes[1]['error'])->toContain('Only draft payments')
                ->and($payment->fresh()->state)->toBe($confirmationState)
                ->and($payment->fresh()->move_id)->toBe($invoice->id)
                ->and((bool) $invoice->fresh()->posted_before)->toBeTrue()
                ->and($invoice->lines()->where('parent_state', '!=', MoveState::POSTED)->count())->toBe(0);
        } elseif ($scenario === 'post versus cancel') {
            expect($outcomes[1]['error'])->toContain('Only draft');
        } elseif ($reset) {
            expect($invoice->paymentTermLines->first()->matchedCredits()->count())->toBe(0);
            if ($scenario === 'payment versus reset') {
                $payment = $invoice->fresh('matchedPayments')->matchedPayments->sole();
                expect($payment->is_reconciled)->toBeFalse()
                    ->and((float) $payment->move->lines->firstWhere('account_id', $partner->property_account_receivable_id)->amount_residual)->toBe(-60.0);
            } else {
                expect((float) Move::findOrFail($creditIds[0])->amount_residual)->toBe(200.0);
            }
        } else {
            expect($outcomes[1]['error'])->toContain('reconciliation changed')
                ->and($invoice->paymentTermLines->first()->matchedCredits()->count())->toBe(1);
        }
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
})->with(['competing reconciliations', 'payment versus reset', 'post versus cancel', 'reconciliation versus reset', 'payment confirmation versus cancel']);

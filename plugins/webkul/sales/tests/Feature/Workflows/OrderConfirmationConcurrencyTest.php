<?php

use Illuminate\Support\Facades\DB as Database;
use Symfony\Component\Process\Process;
use Webkul\Inventory\Enums\DeliveryStep;
use Webkul\Inventory\Enums\ReceptionStep;
use Webkul\Sale\Enums\OrderState;
use Webkul\Sale\Models\Order;
use Webkul\Security\Models\User;

require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../../../inventories/tests/Helpers/InventoryHelper.php';
require_once __DIR__.'/../../Helpers/SaleHelper.php';

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('inventories');
    TestBootstrapHelper::ensurePluginInstalled('sales');

    SaleHelper::actingAsAdmin();
});

it('serializes concurrent confirmation retries without duplicate procurement', function (): void {
    if (! filter_var(config('testing.run_sales_confirmation_concurrency', false), FILTER_VALIDATE_BOOL)) {
        test()->markTestSkipped('Run explicitly with AUREUSERP_RUN_SALES_CONFIRMATION_CONCURRENCY=1 on an isolated disposable database.');
    }

    if (Database::connection()->getDriverName() !== 'pgsql') {
        test()->markTestSkipped('The concurrency proof requires PostgreSQL row-level locking.');
    }

    $database = (string) Database::connection()->getDatabaseName();
    $userId = (int) User::query()->firstOrFail()->getKey();

    expect($database)->toMatch('/^aureuserp_[A-Za-z0-9_]+_testing$/');

    $warehouse = InventoryHelper::warehouse(ReceptionStep::ONE_STEP, DeliveryStep::ONE_STEP);
    $product = SaleHelper::product();
    $order = SaleHelper::order(['warehouse_id' => $warehouse->id]);

    SaleHelper::line($order, $product, 10, 100);

    while (Database::connection()->transactionLevel() > 0) {
        Database::connection()->commit();
    }
    Database::disconnect();

    $barrier = sys_get_temp_dir().'/aureuserp-sales-confirm-'.bin2hex(random_bytes(8));
    mkdir($barrier, 0700);

    $firstReady = $barrier.'/ready-1';
    $secondReady = $barrier.'/ready-2';
    $gate = $barrier.'/go';
    $worker = <<<'PHP'
$base = $argv[1];
$ready = $argv[2];
$gate = $argv[3];
$orderId = (int) $argv[4];
$userId = (int) $argv[5];
chdir($base);
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Auth::loginUsingId($userId);
touch($ready);
$deadline = microtime(true) + 15;
while (! file_exists($gate) && microtime(true) < $deadline) {
    usleep(10000);
}
if (! file_exists($gate)) {
    fwrite(STDOUT, json_encode(['ok' => false, 'message' => 'Start gate was not released.']));
    exit(2);
}
try {
    $order = Webkul\Sale\Facades\SaleOrder::confirmSaleOrder(
        Webkul\Sale\Models\Order::query()->findOrFail($orderId),
    );
    fwrite(STDOUT, json_encode(['ok' => true, 'order_id' => $order->getKey()]));
} catch (Throwable $error) {
    fwrite(STDOUT, json_encode(['ok' => false, 'message' => $error->getMessage()]));
}
PHP;

    $makeProcess = function (int $number) use ($worker, $barrier, $gate, $order, $userId, $database): Process {
        return new Process([
            PHP_BINARY,
            '-d', 'memory_limit=512M',
            '-d', 'max_execution_time=30',
            '-r', $worker,
            base_path(),
            $barrier.'/ready-'.$number,
            $gate,
            (string) $order->getKey(),
            (string) $userId,
        ], base_path(), [
            ...$_ENV,
            'APP_ENV'                       => 'testing',
            'DB_CONNECTION'                 => 'pgsql',
            'DB_DATABASE'                   => $database,
            'AUREUSERP_TEST_REUSE_DATABASE' => '1',
        ], timeout: 45);
    };

    $first = $makeProcess(1);
    $second = $makeProcess(2);

    try {
        $first->start();
        $second->start();

        $deadline = microtime(true) + 20;
        while ((! file_exists($firstReady) || ! file_exists($secondReady)) && microtime(true) < $deadline) {
            usleep(10000);
        }

        if (! file_exists($firstReady) || ! file_exists($secondReady)) {
            test()->fail('Concurrent confirmation workers did not reach the barrier.');
        }

        touch($gate);
        $first->wait();
        $second->wait();

        $outcomes = collect([$first, $second])->map(function (Process $process): array {
            $outcome = json_decode(trim($process->getOutput()), true);

            expect($outcome)->toBeArray();

            return $outcome;
        });

        expect($outcomes->where('ok', true))->toHaveCount(2)
            ->and($outcomes->pluck('order_id')->unique()->values()->all())->toBe([$order->id]);

        Database::reconnect();

        $confirmed = Order::query()->findOrFail($order->id);

        expect($confirmed->state)->toBe(OrderState::SALE)
            ->and($confirmed->operations()->count())->toBe(1);
    } finally {
        foreach ([$first, $second] as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }

        @unlink($firstReady);
        @unlink($secondReady);
        @unlink($gate);
        @rmdir($barrier);
    }
});

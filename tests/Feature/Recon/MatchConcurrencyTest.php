<?php

require_once __DIR__.'/ReconTestSupport.php';

use App\Models\Recon\BankReconciliation;
use App\Models\Recon\StatementLine;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Webkul\Account\Models\BankStatement;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

beforeEach(function (): void {
    TestBootstrapHelper::ensurePluginInstalled('accounts');
    $this->company = Company::query()->firstOrFail();
    test()->actingAs(User::query()->firstOrFail());
    config(['reconciliation.financial_mutations_enabled' => true]);
    config(['reconciliation.require_company_rollout' => false]);
});

it('serializes genuine concurrent confirmations without partial or duplicate effects', function (): void {
    if (! filter_var(config('testing.run_reconciliation_concurrency', false), FILTER_VALIDATE_BOOL)) {
        test()->markTestSkipped('Run explicitly with AUREUSERP_RUN_CONCURRENCY_TEST=1 on an isolated disposable database.');
    }

    if (DB::connection()->getDriverName() !== 'pgsql') {
        test()->markTestSkipped('The concurrency proof requires PostgreSQL row-level locking.');
    }

    $testDatabase = (string) DB::connection()->getDatabaseName();
    $creatorId = (int) User::query()->firstOrFail()->getKey();

    expect($testDatabase)->toMatch('/^aureuserp_[A-Za-z0-9_]+_testing$/');

    $reconciliation = BankReconciliation::query()->create([
        ...validBankReconciliationAttributes(testBankJournal(dedicated: true)),
        'name'           => fake()->unique()->bothify('U18-CONCURRENCY-####'),
        'period_start'   => '2026-09-01',
        'period_end'     => '2026-09-30',
        'statement_date' => '2026-09-30',
    ]);
    $statement = BankStatement::query()->create([
        'company_id'       => $reconciliation->company_id,
        'journal_id'       => $reconciliation->journal_id,
        'name'             => fake()->unique()->bothify('U18-STMT-####'),
        'date'             => '2026-09-30',
        'balance_start'    => '0.0000',
        'balance_end'      => '125.0000',
        'balance_end_real' => '125.0000',
        'is_completed'     => false,
    ]);
    $reconciliation->update(['statement_id' => $statement->id]);

    $bankLine = StatementLine::query()->create([
        'statement_id'       => $statement->id,
        'company_id'         => $reconciliation->company_id,
        'journal_id'         => $reconciliation->journal_id,
        'currency_id'        => $reconciliation->currency_id,
        'transaction_date'   => '2026-09-15',
        'amount'             => '125.0000',
        'amount_residual'    => '125.0000',
        'import_status'      => 'accepted',
        'source_fingerprint' => hash('sha256', fake()->unique()->uuid()),
    ]);

    $moveId = DB::table('accounts_account_moves')->insertGetId([
        'journal_id'  => $reconciliation->journal_id,
        'company_id'  => $reconciliation->company_id,
        'currency_id' => $reconciliation->currency_id,
        'creator_id'  => $creatorId,
        'name'        => fake()->unique()->bothify('U18-MOVE-####'),
        'state'       => 'posted',
        'move_type'   => 'entry',
        'date'        => '2026-09-15',
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);
    $counterpartId = DB::table('accounts_accounts')
        ->where('id', '<>', $reconciliation->liquidity_account_id)
        ->value('id');
    $ledgerLineId = DB::table('accounts_account_move_lines')->insertGetId([
        'move_id'             => $moveId,
        'journal_id'          => $reconciliation->journal_id,
        'company_id'          => $reconciliation->company_id,
        'company_currency_id' => $reconciliation->currency_id,
        'account_id'          => $reconciliation->liquidity_account_id,
        'currency_id'         => $reconciliation->currency_id,
        'creator_id'          => $creatorId,
        'date'                => '2026-09-15',
        'parent_state'        => 'posted',
        'debit'               => '125.0000',
        'credit'              => '0.0000',
        'balance'             => '125.0000',
        'amount_residual'     => '125.0000',
        'reconciled'          => false,
        'created_at'          => now(),
        'updated_at'          => now(),
    ]);
    DB::table('accounts_account_move_lines')->insert([
        'move_id'             => $moveId,
        'journal_id'          => $reconciliation->journal_id,
        'company_id'          => $reconciliation->company_id,
        'company_currency_id' => $reconciliation->currency_id,
        'account_id'          => $counterpartId,
        'currency_id'         => $reconciliation->currency_id,
        'creator_id'          => $creatorId,
        'date'                => '2026-09-15',
        'parent_state'        => 'posted',
        'debit'               => '0.0000',
        'credit'              => '125.0000',
        'balance'             => '-125.0000',
        'amount_residual'     => '-125.0000',
        'reconciled'          => false,
        'created_at'          => now(),
        'updated_at'          => now(),
    ]);

    // Child processes need committed fixtures so they observe the same rows on
    // genuinely independent database sessions. Cleanup below is explicit.
    while (DB::connection()->transactionLevel() > 0) {
        DB::connection()->commit();
    }
    DB::disconnect();

    $barrier = sys_get_temp_dir().'/aureuserp-u18-'.bin2hex(random_bytes(8));
    mkdir($barrier, 0700);
    $gate = $barrier.'/go';
    $firstReady = $barrier.'/ready-1';
    $secondReady = $barrier.'/ready-2';
    $worker = <<<'PHP'
$base = $argv[1];
$ready = $argv[2];
$gate = $argv[3];
$reconciliationId = (int) $argv[4];
$bankLineId = (int) $argv[5];
$ledgerLineId = (int) $argv[6];
$userId = (int) $argv[7];
chdir($base);
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['reconciliation.financial_mutations_enabled' => true]);
config(['reconciliation.require_company_rollout' => false]);
Illuminate\Support\Facades\Auth::loginUsingId($userId);
$connection = Illuminate\Support\Facades\DB::connection();
$sessionId = $connection->selectOne('SELECT pg_backend_pid() AS id')->id;
touch($ready);
$deadline = microtime(true) + 15;
while (! file_exists($gate) && microtime(true) < $deadline) {
    usleep(10000);
}
if (! file_exists($gate)) {
    fwrite(STDOUT, json_encode(['ok' => false, 'class' => 'BarrierTimeout', 'message' => 'Start gate was not released.']));
    exit(2);
}
try {
    $match = app(App\Services\Recon\MatchService::class)->createMatch(
        App\Models\Recon\BankReconciliation::query()->findOrFail($reconciliationId),
        [$bankLineId],
        [$ledgerLineId],
    );
    fwrite(STDOUT, json_encode(['ok' => true, 'match_id' => $match->id, 'session_id' => $sessionId]));
} catch (Throwable $error) {
    fwrite(STDOUT, json_encode(['ok' => false, 'class' => $error::class, 'message' => $error->getMessage(), 'session_id' => $sessionId]));
}
PHP;

    $makeProcess = function (int $number) use ($worker, $barrier, $gate, $reconciliation, $bankLine, $ledgerLineId, $testDatabase, $creatorId): Process {
        return new Process([
            PHP_BINARY,
            '-d', 'memory_limit=512M',
            '-d', 'max_execution_time=30',
            '-r', $worker,
            base_path(),
            $barrier.'/ready-'.$number,
            $gate,
            (string) $reconciliation->id,
            (string) $bankLine->id,
            (string) $ledgerLineId,
            (string) $creatorId,
        ], base_path(), [
            ...$_ENV,
            'APP_ENV'                       => 'testing',
            'DB_CONNECTION'                 => DB::connection()->getDriverName(),
            'DB_DATABASE'                   => $testDatabase,
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
            test()->fail(sprintf(
                'Concurrent workers did not reach the barrier. First: %s %s Second: %s %s',
                trim($first->getOutput()),
                trim($first->getErrorOutput()),
                trim($second->getOutput()),
                trim($second->getErrorOutput()),
            ));
        }

        touch($gate);
        $first->wait();
        $second->wait();

        $outcomes = collect([$first, $second])->map(function (Process $process): array {
            $decoded = json_decode(trim($process->getOutput()), true);

            expect($process->getExitCode())->toBe(0)
                ->and($decoded)->toBeArray();

            return $decoded;
        });

        expect($outcomes->pluck('session_id')->unique())->toHaveCount(2)
            ->and($outcomes->where('ok', true))->toHaveCount(1)
            ->and($outcomes->where('ok', false))->toHaveCount(1)
            ->and($outcomes->firstWhere('ok', false)['class'])->toBe(InvalidArgumentException::class)
            ->and($outcomes->firstWhere('ok', false)['message'])->toMatch('/already allocated|already treated/');

        DB::reconnect();
        $matchIds = DB::table('recon_matches')
            ->where('reconciliation_id', $reconciliation->id)
            ->where('status', 'confirmed')
            ->pluck('id');

        expect($matchIds)->toHaveCount(1)
            ->and(DB::table('recon_match_lines')->whereIn('match_id', $matchIds)->count())->toBe(2)
            ->and(DB::table('recon_events')
                ->where('reconciliation_id', $reconciliation->id)
                ->where('event', 'match_confirmed')
                ->count())->toBe(1)
            ->and(DB::table('accounts_partial_reconciles')->whereIn('recon_match_id', $matchIds)->count())->toBe(0)
            ->and(StatementLine::query()->findOrFail($bankLine->id)->amount_residual)->toBe('0.0000')
            ->and(StatementLine::query()->findOrFail($bankLine->id)->is_reconciled)->toBeTrue()
            ->and(DB::table('recon_match_lines')->whereIn('match_id', $matchIds)->pluck('amount_applied')->all())->toBe(['125.0000', '125.0000'])
            ->and(DB::table('accounts_account_move_lines')->where('id', $ledgerLineId)->value('amount_residual'))->toBe('125.0000')
            ->and(DB::table('accounts_account_move_lines')->where('id', $ledgerLineId)->value('statement_line_id'))->toBe($bankLine->id)
            ->and((string) DB::table('accounts_account_move_lines')->where('move_id', $moveId)->sum('balance'))->toBe('0.0000');
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

        // The winning match and its event are intentionally retained in the
        // isolated test database: reconciliation audit events are immutable,
        // so deleting committed evidence would weaken the very control tested.
    }
});

<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    // Same fail-closed guard the rest of the suite relies on before any
    // destructive DDL — Schema::dropAllTables() below is exactly that.
    TestBootstrapHelper::assertSafeToRunDestructiveBootstrap();
});

/**
 * This test's own DatabaseTransactions wrapper already has a transaction
 * open by the time the test body runs — the exact same DDL-vs-transaction
 * desync fixed in TestBootstrapHelper::ensureERPInstalled() itself. Without
 * flushing it first, Schema::dropAllTables()'s own table listing can miss
 * everything a subprocess just installed, silently dropping nothing and
 * leaving the next subprocess to boot against a database that looks empty
 * to this test but isn't — reproducing the exact bug under test instead of
 * setting up a clean scenario for it.
 */
function freshlyDropAllTables(): void
{
    $connection = DB::connection();
    $transactionLevel = $connection->transactionLevel();

    for ($i = $transactionLevel; $i > 0; $i--) {
        $connection->commit();
    }

    Schema::dropAllTables();

    for ($i = 0; $i < $transactionLevel; $i++) {
        $connection->beginTransaction();
    }
}

/**
 * Same transaction-flush requirement as freshlyDropAllTables(): a
 * subprocess's changes are committed for real, but this test's own
 * REPEATABLE READ transaction (opened by DatabaseTransactions before the
 * test body even runs) would otherwise read a snapshot from before the
 * subprocess ever ran, silently reporting a stale fingerprint instead of
 * the database's real current structure.
 */
function freshSchemaFingerprint(): string
{
    $connection = DB::connection();
    $transactionLevel = $connection->transactionLevel();

    for ($i = $transactionLevel; $i > 0; $i--) {
        $connection->commit();
    }

    $fingerprint = TestBootstrapHelper::schemaFingerprint();

    for ($i = 0; $i < $transactionLevel; $i++) {
        $connection->beginTransaction();
    }

    return $fingerprint;
}

// #138 PR4 ola4A round 2-4, A18-01/A18-02/A18-03: TestBootstrapHelper's
// bootstrap must produce the SAME final schema regardless of the order
// plugins are requested in, and must fail loud instead of silently
// corrupting the schema when a caller violates the "first process to
// touch this database" contract. Both properties are only observable
// ACROSS separate PHP processes (see run_bootstrap_order.php's docblock
// and TestBootstrapHelper::assertDatabaseNotAlreadyBootstrapped()'s), so
// these tests spawn the real script as a subprocess via
// Symfony\Component\Process\Process, the same pattern already used in
// tests/Feature/Support/CompanyScopeAuditorTest.php's runAuditScript() to
// prove the actual CLI orchestration, not just an in-process unit call.

/**
 * @return array{tableCount: int, fingerprint: string}
 */
function runBootstrapOrder(array $pluginNames): array
{
    $process = new Process(
        [PHP_BINARY, base_path('plugins/webkul/support/tests/fixtures/run_bootstrap_order.php'), json_encode($pluginNames)],
        base_path(),
        [
            'APP_ENV'                           => 'testing',
            'DB_DATABASE'                       => DB::connection()->getDatabaseName(),
            'TEST_BOOTSTRAP_ALLOWED_DATABASES'  => env('TEST_BOOTSTRAP_ALLOWED_DATABASES'),
        ],
    );

    $process->setTimeout(180);
    $process->run();

    if (! $process->isSuccessful()) {
        throw new RuntimeException('run_bootstrap_order.php failed: '.$process->getErrorOutput());
    }

    return json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
}

function runBootstrapOrderExpectingFailure(array $pluginNames): Process
{
    $process = new Process(
        [PHP_BINARY, base_path('plugins/webkul/support/tests/fixtures/run_bootstrap_order.php'), json_encode($pluginNames)],
        base_path(),
        [
            'APP_ENV'                           => 'testing',
            'DB_DATABASE'                       => DB::connection()->getDatabaseName(),
            'TEST_BOOTSTRAP_ALLOWED_DATABASES'  => env('TEST_BOOTSTRAP_ALLOWED_DATABASES'),
        ],
    );

    $process->setTimeout(180);
    $process->run();

    return $process;
}

it('produces a structurally identical schema regardless of the order plugins are requested in', function () {
    // Two independent, reversed request orders — not just "two different
    // single plugins" — because ensurePluginInstalled()'s first call
    // always installs the full ALL_PLUGINS list regardless of which name
    // triggered it; the real risk this proves against is any FUTURE
    // change that makes installation order-sensitive again.
    $orderA = ['accounting', 'website', 'projects', 'manufacturing', 'employees'];
    $orderB = array_reverse($orderA);

    freshlyDropAllTables();
    $resultA = runBootstrapOrder($orderA);

    freshlyDropAllTables();
    $resultB = runBootstrapOrder($orderB);

    // Sanity: actually installed everything (20 plugins + core), not just
    // the requested subset — a shallow/partial install would trivially
    // "match" at a much lower, wrong count.
    expect($resultA['tableCount'])->toBeGreaterThan(200);

    // The fingerprint covers tables, columns (type + nullability), indexes,
    // and foreign keys — a bare table count would miss a schema that has
    // the same number of tables but a missing column or index somewhere.
    expect($resultA['fingerprint'])->toBe($resultB['fingerprint'])
        ->and($resultA['tableCount'])->toBe($resultB['tableCount']);
});

it('fails loud instead of silently corrupting the schema when bootstrapped twice against the same never-recreated database', function () {
    freshlyDropAllTables();

    $resultA = runBootstrapOrder(['projects']);
    $fingerprintAfterFirstRun = freshSchemaFingerprint();
    expect($fingerprintAfterFirstRun)->toBe($resultA['fingerprint']);

    // Same database, deliberately NOT recreated — the exact condition
    // TestBootstrapHelper::assertDatabaseNotAlreadyBootstrapped() exists to
    // catch (a second process's application boot would otherwise register
    // a wider migration set than a truly empty database does, hitting a
    // real timestamp-ordering defect and leaving the schema half-migrated).
    $processB = runBootstrapOrderExpectingFailure(['projects']);

    expect($processB->isSuccessful())->toBeFalse()
        ->and($processB->getErrorOutput())->toContain('RuntimeException')
        ->toContain('already has')
        ->toContain('plugin(s) marked installed');

    // The guard must throw BEFORE any destructive DDL runs — the schema
    // from the first run stays byte-for-byte intact, not half-wiped or
    // subtly altered.
    expect(freshSchemaFingerprint())->toBe($fingerprintAfterFirstRun);
});

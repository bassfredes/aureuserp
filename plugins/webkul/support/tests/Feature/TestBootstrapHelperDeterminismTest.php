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

// #138 PR4 ola4A round 2, A18-01/A18-02/A18-03: TestBootstrapHelper's
// bootstrap must produce the SAME final schema regardless of which plugin
// requests it first, and must fail loud — not silently corrupt the schema
// — when a caller violates the "first process to touch this database"
// contract. Both properties are only observable ACROSS separate PHP
// processes (see run_bootstrap_order.php's docblock and
// TestBootstrapHelper::assertDatabaseNotAlreadyBootstrapped()'s), so these
// tests spawn the real script as a subprocess via Symfony\Component\
// Process\Process, the same pattern already used in
// tests/Feature/Support/CompanyScopeAuditorTest.php's runAuditScript() to
// prove the actual CLI orchestration, not just an in-process unit call.

function runBootstrapOrder(array $pluginNames): Process
{
    $process = new Process(
        [PHP_BINARY, base_path('plugins/webkul/support/tests/fixtures/run_bootstrap_order.php'), json_encode($pluginNames)],
        base_path(),
        [
            'APP_ENV'                          => 'testing',
            'DB_DATABASE'                       => DB::connection()->getDatabaseName(),
            'TEST_BOOTSTRAP_ALLOWED_DATABASES'  => env('TEST_BOOTSTRAP_ALLOWED_DATABASES'),
        ],
    );

    $process->setTimeout(120);
    $process->run();

    return $process;
}

it('produces the identical final schema regardless of which plugin triggers the bootstrap first', function () {
    freshlyDropAllTables();

    $processA = runBootstrapOrder(['accounting']);
    expect($processA->isSuccessful())->toBeTrue($processA->getErrorOutput());
    $tableCountA = (int) trim($processA->getOutput());

    freshlyDropAllTables();

    $processB = runBootstrapOrder(['website']);
    expect($processB->isSuccessful())->toBeTrue($processB->getErrorOutput());
    $tableCountB = (int) trim($processB->getOutput());

    // Sanity: actually installed everything (20 plugins + core), not just
    // whichever single plugin was requested — a shallow/partial install
    // would trivially "match" at a much lower, wrong count.
    expect($tableCountA)->toBeGreaterThan(200)
        ->and($tableCountA)->toBe($tableCountB);
});

it('fails loud instead of silently corrupting the schema when bootstrapped twice against the same never-recreated database', function () {
    freshlyDropAllTables();

    $processA = runBootstrapOrder(['projects']);
    expect($processA->isSuccessful())->toBeTrue($processA->getErrorOutput());
    $tableCountAfterFirstRun = (int) trim($processA->getOutput());

    // Same database, deliberately NOT recreated — the exact condition
    // TestBootstrapHelper::assertDatabaseNotAlreadyBootstrapped() exists to
    // catch (a second process's application boot would otherwise register
    // a wider migration set than a truly empty database does, hitting a
    // real timestamp-ordering defect and leaving the schema half-migrated).
    $processB = runBootstrapOrder(['projects']);

    expect($processB->isSuccessful())->toBeFalse()
        ->and($processB->getErrorOutput())->toContain('RuntimeException')
        ->toContain('already has')
        ->toContain('plugin(s) marked installed');

    // The guard must throw BEFORE any destructive DDL runs — the schema
    // from the first run stays fully intact, not half-wiped.
    expect(Schema::hasTable('website_pages'))->toBeTrue();
    $tableCountAfterFailedSecondRun = count(DB::select('SHOW TABLES'));
    expect($tableCountAfterFailedSecondRun)->toBe($tableCountAfterFirstRun);
});

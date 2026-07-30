<?php

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    // Cheap sanity net before spinning up ephemeral databases via raw,
    // privileged PDO connections below — refuses to run outside a
    // testing environment, matching the guard the rest of the suite
    // relies on before any destructive DDL.
    TestBootstrapHelper::assertSafeToRunDestructiveBootstrap();
});

/**
 * Same transaction-flush requirement documented in
 * TestBootstrapHelper::ensureERPInstalled(): this test's own
 * DatabaseTransactions wrapper already has a transaction open (REPEATABLE
 * READ) by the time the test body runs, which would otherwise read a
 * snapshot from before any subprocess spawned below ever ran. Used here
 * only to fingerprint the MAIN test database — never to modify it — so a
 * before/after comparison can prove this whole file never touches it.
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

/**
 * @return array{0: PDO, 1: string, 2: string} [pdo, rootUser, appUser]
 */
function ephemeralBootstrapPdo(): array
{
    // Same root-credential pattern as scripts/reset-test-database.php.
    // Falls back to the app's own DB_USERNAME/DB_PASSWORD when no
    // separate TEST_BOOTSTRAP_DB_ROOT_* pair is configured — exactly
    // CI's case (DB_USERNAME=root there already, see .github/workflows/
    // pest_tests.yml), so no CI workflow change is needed for this to
    // work; locally, DB_USERNAME is a least-privilege app user that
    // cannot create databases on its own, hence the separate root pair.
    $rootUser = env('TEST_BOOTSTRAP_DB_ROOT_USER') ?: env('DB_USERNAME');
    $rootPassword = env('TEST_BOOTSTRAP_DB_ROOT_PASSWORD') ?: env('DB_PASSWORD');
    $appUser = env('DB_USERNAME');
    $host = env('DB_HOST', '127.0.0.1');
    $port = env('DB_PORT', '3306');

    $pdo = new PDO("mysql:host={$host};port={$port}", $rootUser, $rootPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    return [$pdo, $rootUser, $appUser];
}

/**
 * Creates a brand-new, uniquely-named MySQL database via a privileged raw
 * PDO connection — never through the app's own Laravel DB::connection(),
 * which stays untouched throughout this whole file (#138 PR4 review
 * 4814881805). Each scenario below gets its own ephemeral database
 * instead of sharing (and destructively resetting) the main test
 * database, so a subprocess timeout or install failure can never corrupt
 * the schema every other test file in the suite depends on.
 */
function createEphemeralBootstrapDatabase(string $label): string
{
    [$pdo, $rootUser, $appUser] = ephemeralBootstrapPdo();

    $database = DB::connection()->getDatabaseName().'_boot_'.$label.'_'.bin2hex(random_bytes(4));

    $pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    if ($appUser !== $rootUser) {
        $pdo->exec("GRANT ALL PRIVILEGES ON `{$database}`.* TO '{$appUser}'@'%'");
        $pdo->exec('FLUSH PRIVILEGES');
    }

    return $database;
}

function dropEphemeralBootstrapDatabase(string $database): void
{
    [$pdo] = ephemeralBootstrapPdo();

    $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
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
 * @return array{database: string, tableCount: int, fingerprint: string}
 */
function runBootstrapOrder(array $pluginNames, string $database): array
{
    $process = new Process(
        [PHP_BINARY, base_path('plugins/webkul/support/tests/fixtures/run_bootstrap_order.php'), json_encode($pluginNames), $database],
        base_path(),
        [
            'APP_ENV'                           => 'testing',
            'DB_DATABASE'                       => $database,
            'TEST_BOOTSTRAP_ALLOWED_DATABASES'  => $database,
        ],
    );

    $process->setTimeout(300);
    $process->run();

    if (! $process->isSuccessful()) {
        throw new RuntimeException('run_bootstrap_order.php failed: '.$process->getErrorOutput());
    }

    return json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
}

function runBootstrapOrderExpectingFailure(array $pluginNames, string $database): Process
{
    $process = new Process(
        [PHP_BINARY, base_path('plugins/webkul/support/tests/fixtures/run_bootstrap_order.php'), json_encode($pluginNames), $database],
        base_path(),
        [
            'APP_ENV'                           => 'testing',
            'DB_DATABASE'                       => $database,
            'TEST_BOOTSTRAP_ALLOWED_DATABASES'  => $database,
        ],
    );

    $process->setTimeout(300);
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

    $mainFingerprintBefore = freshSchemaFingerprint();
    $createdDatabases = [];

    try {
        $databaseA = createEphemeralBootstrapDatabase('order-a');
        $createdDatabases[] = $databaseA;

        $databaseB = createEphemeralBootstrapDatabase('order-b');
        $createdDatabases[] = $databaseB;

        $resultA = runBootstrapOrder($orderA, $databaseA);
        $resultB = runBootstrapOrder($orderB, $databaseB);

        // Sanity: actually installed everything (20 plugins + core), not
        // just the requested subset — a shallow/partial install would
        // trivially "match" at a much lower, wrong count.
        expect($resultA['tableCount'])->toBeGreaterThan(200);

        // The fingerprint covers tables, columns (type + nullability),
        // indexes, and foreign keys — a bare table count would miss a
        // schema that has the same number of tables but a missing column
        // or index somewhere.
        expect($resultA['fingerprint'])->toBe($resultB['fingerprint'])
            ->and($resultA['tableCount'])->toBe($resultB['tableCount']);
    } finally {
        foreach ($createdDatabases as $database) {
            dropEphemeralBootstrapDatabase($database);
        }
    }

    // Neither ephemeral install ever touched the main test database —
    // proven by an exact fingerprint match, not merely "still has tables".
    expect(freshSchemaFingerprint())->toBe($mainFingerprintBefore);
});

it('fails loud instead of silently corrupting the schema when bootstrapped twice against the same never-recreated database', function () {
    $mainFingerprintBefore = freshSchemaFingerprint();
    $createdDatabases = [];

    try {
        $database = createEphemeralBootstrapDatabase('double-bootstrap');
        $createdDatabases[] = $database;

        $resultA = runBootstrapOrder(['projects'], $database);

        // Same ephemeral database, deliberately NOT recreated — the exact
        // condition TestBootstrapHelper::assertDatabaseNotAlreadyBootstrapped()
        // exists to catch (a second process's application boot would
        // otherwise register a wider migration set than a truly empty
        // database does, hitting a real timestamp-ordering defect and
        // leaving the schema half-migrated).
        $processB = runBootstrapOrderExpectingFailure(['projects'], $database);

        expect($processB->isSuccessful())->toBeFalse()
            ->and($processB->getErrorOutput())->toContain('RuntimeException')
            ->toContain('already has')
            ->toContain('plugin(s) marked installed');

        // The guard must throw BEFORE any destructive DDL runs — reconnect
        // to the same ephemeral database with an empty plugin list (no
        // install, no bootstrap attempt at all) and confirm the schema
        // from the first run stays byte-for-byte intact, not half-wiped
        // or subtly altered by the rejected second attempt.
        $resultAfterRejectedAttempt = runBootstrapOrder([], $database);

        expect($resultAfterRejectedAttempt['fingerprint'])->toBe($resultA['fingerprint']);
    } finally {
        foreach ($createdDatabases as $db) {
            dropEphemeralBootstrapDatabase($db);
        }
    }

    expect(freshSchemaFingerprint())->toBe($mainFingerprintBefore);
});

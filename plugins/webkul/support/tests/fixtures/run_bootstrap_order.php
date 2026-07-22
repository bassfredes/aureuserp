<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Standalone entry point spawned as a real, separate PHP process by
// TestBootstrapHelperDeterminismTest.php (via Symfony\Component\Process\
// Process) — proving TestBootstrapHelper::ensureERPInstalled()'s ACTUAL
// cross-process behavior, not just an in-process unit call. The bug this
// exists to catch (#138 PR4 ola4A round 2, A18-01/A18-02/A18-03) only
// manifests when a SEPARATE PHP process boots the application against a
// database a prior process already touched — a plain Pest test running in
// the same process as the rest of the suite can never reproduce that.
//
// Usage: php run_bootstrap_order.php <json-encoded-array-of-plugin-names>
// Prints a single JSON object on success (exit 0): {"tableCount": int,
// "fingerprint": string} — the fingerprint is
// TestBootstrapHelper::schemaFingerprint(), a stable hash of tables,
// columns, indexes and foreign keys, so two runs can be compared for an
// EXACT structural match, not just a table count that could hide a
// missing column or a differently-ordered index. On failure, prints the
// exception class + message on stderr and exits 1.

require dirname(__DIR__, 5).'/vendor/autoload.php';

$app = require dirname(__DIR__, 5).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

require dirname(__DIR__).'/Helpers/TestBootstrapHelper.php';

$pluginNames = json_decode($argv[1] ?? '[]', true, flags: JSON_THROW_ON_ERROR);

try {
    foreach ($pluginNames as $pluginName) {
        TestBootstrapHelper::ensurePluginInstalled($pluginName);
    }

    echo json_encode([
        'tableCount'  => count(DB::select('SHOW TABLES')),
        'fingerprint' => TestBootstrapHelper::schemaFingerprint(),
    ], JSON_THROW_ON_ERROR).PHP_EOL;

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage().PHP_EOL);

    exit(1);
}

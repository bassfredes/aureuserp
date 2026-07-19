#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Support\CompanyScopeAudit\Auditor;
use App\Support\CompanyScopeAudit\ExceptionManifest;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$options = getopt('', ['plugins:', 'format:', 'fail-on-missing']);
$pluginNames = array_values(array_filter(
    array_map('trim', explode(',', (string) ($options['plugins'] ?? 'inventories,purchases,products'))),
    static fn (string $plugin): bool => $plugin !== '',
));
$format = (string) ($options['format'] ?? 'table');
$failOnMissing = array_key_exists('fail-on-missing', $options);

if ($pluginNames === []) {
    fwrite(STDERR, "At least one plugin is required.\n");
    exit(2);
}

if (! in_array($format, ['table', 'json'], true)) {
    fwrite(STDERR, "Unsupported --format. Use table or json.\n");
    exit(2);
}

foreach ($pluginNames as $pluginName) {
    if (! preg_match('/^[a-z0-9-]+$/', $pluginName)) {
        fwrite(STDERR, "Invalid plugin name: {$pluginName}\n");
        exit(2);
    }
}

$auditor = new Auditor;
$manifest = ExceptionManifest::default();

try {
    $rows = $auditor->inspectPlugins($pluginNames);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(2);
}

$rows = $auditor->classifyRows($rows, $manifest);

// The manifest is validated in full on every run, regardless of --plugins
// scope — a partial-scope run must still catch a stale/broken exception
// anywhere in the manifest (#138, PR 4 checkpoint).
$manifestViolations = $auditor->validateManifest($manifest);

if ($format === 'json') {
    echo json_encode(
        ['rows' => $rows, 'manifest_violations' => $manifestViolations],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ).PHP_EOL;
} else {
    $reportedRows = array_values(array_filter(
        $rows,
        static fn (array $row): bool => $row['has_company_id'] !== false,
    ));

    $headers = ['PLUGIN', 'MODEL', 'TABLE', 'COMPANY_ID', 'SCOPE', 'STATUS', 'CLASSIFICATION'];
    $displayRows = array_map(
        static fn (array $row): array => [
            $row['plugin'],
            $row['class'],
            $row['table'] ?? '-',
            $row['has_company_id'] === null ? '?' : ($row['has_company_id'] ? 'yes' : 'no'),
            $row['uses_company_scope'] === null ? '?' : ($row['uses_company_scope'] ? 'yes' : 'no'),
            $row['status'],
            $row['classification'] ?? '-',
        ],
        $reportedRows,
    );

    $widths = array_map('strlen', $headers);

    foreach ($displayRows as $displayRow) {
        foreach ($displayRow as $column => $value) {
            $widths[$column] = max($widths[$column], strlen((string) $value));
        }
    }

    $printRow = static function (array $values) use ($widths): void {
        $cells = [];

        foreach ($values as $column => $value) {
            $cells[] = str_pad((string) $value, $widths[$column]);
        }

        echo implode(' | ', $cells).PHP_EOL;
    };

    $printRow($headers);
    echo implode('-+-', array_map(static fn (int $width): string => str_repeat('-', $width), $widths)).PHP_EOL;

    foreach ($displayRows as $displayRow) {
        $printRow($displayRow);
    }

    if ($manifestViolations !== []) {
        echo PHP_EOL.'Manifest violations:'.PHP_EOL;

        foreach ($manifestViolations as $violation) {
            echo "  [{$violation['type']}] {$violation['fqcn']}: {$violation['message']}".PHP_EOL;
        }
    }
}

$realMissingCount = count(array_filter($rows, $auditor->isRealMissingScope(...)));
$inspectionErrorCount = count(array_filter(
    $rows,
    static fn (array $row): bool => $row['status'] === 'inspection_error',
));
$tableMissingCount = count(array_filter(
    $rows,
    static fn (array $row): bool => $row['status'] === 'table_missing',
));

fwrite(
    STDERR,
    sprintf(
        "Inspected %d model(s): %d missing CompanyScope (unclassified), %d missing table(s), %d inspection error(s), %d manifest violation(s).\n",
        count($rows),
        $realMissingCount,
        $tableMissingCount,
        $inspectionErrorCount,
        count($manifestViolations),
    ),
);

// Manifest violations and table/inspection errors mean the audit itself is
// untrustworthy — always fatal, regardless of --fail-on-missing.
if ($tableMissingCount > 0 || $inspectionErrorCount > 0 || $manifestViolations !== []) {
    exit(2);
}

// Real (unclassified) missing_scope findings are known, pending work — only
// gate on them when the caller explicitly opts in.
if ($failOnMissing && $realMissingCount > 0) {
    exit(1);
}

exit(0);

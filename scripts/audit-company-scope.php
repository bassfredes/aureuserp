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
$format = (string) ($options['format'] ?? 'table');
$failOnMissing = array_key_exists('fail-on-missing', $options);

if (! in_array($format, ['table', 'json'], true)) {
    fwrite(STDERR, "Unsupported --format. Use table or json.\n");
    exit(2);
}

$auditor = new Auditor;
$manifest = ExceptionManifest::default();

// No --plugins means a real, global audit — every plugin with a
// src/Models directory, discovered from disk, not a hardcoded default
// subset. A partial default here would let `php scripts/audit-company-scope.php`
// silently skip most of the ERP while looking green (#138, PR 4 review).
if (array_key_exists('plugins', $options)) {
    $pluginNames = array_values(array_filter(
        array_map('trim', explode(',', (string) $options['plugins'])),
        static fn (string $plugin): bool => $plugin !== '',
    ));

    if ($pluginNames === []) {
        fwrite(STDERR, "At least one plugin is required.\n");
        exit(2);
    }

    foreach ($pluginNames as $pluginName) {
        if (! preg_match('/^[a-z0-9-]+$/', $pluginName)) {
            fwrite(STDERR, "Invalid plugin name: {$pluginName}\n");
            exit(2);
        }
    }
} else {
    $pluginNames = $auditor->discoverPlugins();
}

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

$summary = [
    'total'                         => count($rows),
    'scoped'                        => count(array_filter($rows, static fn (array $r): bool => $r['effective_status'] === 'scoped')),
    'classified_exceptions'         => count(array_filter($rows, static fn (array $r): bool => $r['effective_status'] === 'classified_exception')),
    'real_gaps_with_company_id'     => count(array_filter($rows, static fn (array $r): bool => $r['effective_status'] === 'real_gap_company_column')),
    'real_gaps_without_company_id'  => count(array_filter($rows, static fn (array $r): bool => $r['effective_status'] === 'real_gap_without_company_column')),
    'table_missing'                 => count(array_filter($rows, static fn (array $r): bool => $r['effective_status'] === 'table_missing')),
    'inspection_errors'             => count(array_filter($rows, static fn (array $r): bool => $r['effective_status'] === 'inspection_error')),
    'manifest_violations'           => count($manifestViolations),
];

if ($format === 'json') {
    echo json_encode(
        ['plugins' => $pluginNames, 'summary' => $summary, 'rows' => $rows, 'manifest_violations' => $manifestViolations],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ).PHP_EOL;
} else {
    $reportedRows = array_values(array_filter(
        $rows,
        static fn (array $row): bool => $row['has_company_id'] !== false,
    ));

    $headers = ['PLUGIN', 'MODEL', 'TABLE', 'COMPANY_ID', 'SCOPE', 'STATUS', 'CLASSIFICATION', 'EFFECTIVE'];
    $displayRows = array_map(
        static fn (array $row): array => [
            $row['plugin'],
            $row['class'],
            $row['table'] ?? '-',
            $row['has_company_id'] === null ? '?' : ($row['has_company_id'] ? 'yes' : 'no'),
            $row['uses_company_scope'] === null ? '?' : ($row['uses_company_scope'] ? 'yes' : 'no'),
            $row['status'],
            $row['classification'] ?? '-',
            $row['effective_status'],
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

fwrite(
    STDERR,
    sprintf(
        "Audited %d plugin(s), %d model(s): %d scoped, %d classified exceptions, %d real gap(s) with company_id, %d real gap(s) without company_id, %d missing table(s), %d inspection error(s), %d manifest violation(s).\n",
        count($pluginNames),
        $summary['total'],
        $summary['scoped'],
        $summary['classified_exceptions'],
        $summary['real_gaps_with_company_id'],
        $summary['real_gaps_without_company_id'],
        $summary['table_missing'],
        $summary['inspection_errors'],
        $summary['manifest_violations'],
    ),
);

// Manifest violations and table/inspection errors mean the audit itself is
// untrustworthy — always fatal, regardless of --fail-on-missing.
if ($summary['table_missing'] > 0 || $summary['inspection_errors'] > 0 || $manifestViolations !== []) {
    exit(2);
}

// Real (unclassified) gaps are known, pending work — only gate on them when
// the caller explicitly opts in.
$realGapCount = $summary['real_gaps_with_company_id'] + $summary['real_gaps_without_company_id'];

if ($failOnMissing && $realGapCount > 0) {
    exit(1);
}

exit(0);

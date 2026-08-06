#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Support\CompanyScopeAudit\AcceptedRiskRegistry;
use App\Support\CompanyScopeAudit\Auditor;
use App\Support\CompanyScopeAudit\ExceptionManifest;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$options = getopt('', ['plugins:', 'format:', 'fail-on-missing', 'fail-on-unapproved-gaps']);
$format = (string) ($options['format'] ?? 'table');
$failOnMissing = array_key_exists('fail-on-missing', $options);
$failOnUnapprovedGaps = array_key_exists('fail-on-unapproved-gaps', $options);

if (! in_array($format, ['table', 'json'], true)) {
    fwrite(STDERR, "Unsupported --format. Use table or json.\n");
    exit(2);
}

$auditor = new Auditor;
// COMPANY_SCOPE_MANIFEST_PATH exists so tests can drive this exact script
// against a deliberately broken fixture manifest — the real orchestration
// order, not just the Auditor methods in isolation (#138, PR 4 review).
$manifestPath = getenv('COMPANY_SCOPE_MANIFEST_PATH');
$manifest = ExceptionManifest::default($manifestPath !== false ? $manifestPath : null);

// Same rationale as COMPANY_SCOPE_MANIFEST_PATH above — lets tests drive
// the real CLI orchestration against a deliberately broken/empty fixture
// accepted-risk registry (#138 PR4, Codex adversarial-review
// recommendation, 2026-08-03).
$acceptedRisksPath = getenv('COMPANY_SCOPE_ACCEPTED_RISKS_PATH');
$acceptedRisks = AcceptedRiskRegistry::default($acceptedRisksPath !== false ? $acceptedRisksPath : null);

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

// The manifest is validated in full on every run, regardless of --plugins
// scope — a partial-scope run must still catch a stale/broken exception
// anywhere in the manifest (#138, PR 4 checkpoint). Validated BEFORE
// classifyRows() ever runs: a malformed entry (missing 'table' or
// 'classification') must never reach the classification step, even
// defensively-coded — a broken manifest means the audit itself can't be
// trusted yet, so nothing downstream should try to use it
// (#138, PR 4 review, 2026-07-20).
$manifestViolations = $auditor->validateManifest($manifest);

if ($manifestViolations !== []) {
    if ($format === 'json') {
        echo json_encode(
            ['plugins' => $pluginNames, 'summary' => null, 'rows' => null, 'manifest_violations' => $manifestViolations],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ).PHP_EOL;
    } else {
        echo 'Manifest violations (fatal — fix these before the audit can run):'.PHP_EOL;

        foreach ($manifestViolations as $violation) {
            echo "  [{$violation['type']}] {$violation['fqcn']}: {$violation['message']}".PHP_EOL;
        }
    }

    fwrite(STDERR, sprintf("Manifest is broken: %d violation(s). Audit did not run.\n", count($manifestViolations)));
    exit(2);
}

// Same fail-fast discipline as the manifest above, and for the same
// reason: a malformed accepted-risk entry must never reach
// annotateAcceptedRisks() (#138 PR4, Codex adversarial-review
// recommendation, 2026-08-03).
$acceptedRiskViolations = $auditor->validateAcceptedRiskRegistry($acceptedRisks);

if ($acceptedRiskViolations !== []) {
    if ($format === 'json') {
        echo json_encode(
            ['plugins' => $pluginNames, 'summary' => null, 'rows' => null, 'manifest_violations' => [], 'accepted_risk_violations' => $acceptedRiskViolations],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ).PHP_EOL;
    } else {
        echo 'Accepted-risk registry violations (fatal — fix these before the audit can run):'.PHP_EOL;

        foreach ($acceptedRiskViolations as $violation) {
            echo "  [{$violation['type']}] {$violation['fqcn']}: {$violation['message']}".PHP_EOL;
        }
    }

    fwrite(STDERR, sprintf("Accepted-risk registry is broken: %d violation(s). Audit did not run.\n", count($acceptedRiskViolations)));
    exit(2);
}

$rows = $auditor->classifyRows($rows, $manifest);
$rows = $auditor->annotateAcceptedRisks($rows, $acceptedRisks);

$summary = [
    'total'                         => count($rows),
    'scoped'                        => count(array_filter($rows, static fn (array $r): bool => $r['effective_status'] === 'scoped')),
    'classified_exceptions'         => count(array_filter($rows, static fn (array $r): bool => $r['effective_status'] === 'classified_exception')),
    'real_gaps_with_company_id'     => count(array_filter($rows, static fn (array $r): bool => $r['effective_status'] === 'real_gap_company_column')),
    'real_gaps_without_company_id'  => count(array_filter($rows, static fn (array $r): bool => $r['effective_status'] === 'real_gap_without_company_column')),
    'table_missing'                 => count(array_filter($rows, static fn (array $r): bool => $r['effective_status'] === 'table_missing')),
    'inspection_errors'             => count(array_filter($rows, static fn (array $r): bool => $r['effective_status'] === 'inspection_error')),
    // Always 0 here — a non-empty $manifestViolations already exited above.
    'manifest_violations'           => 0,
    // Real gaps with an accepted-risk entry that is registered, exact-match,
    // and not yet expired — visible in every run, never subtracted from
    // real_gaps_with/without_company_id above.
    'accepted_risks'                => count(array_filter($rows, static fn (array $r): bool => ($r['accepted_risk_status'] ?? null) === 'approved')),
    // Real gaps `--fail-on-unapproved-gaps` gates on: unregistered, expired,
    // or a mismatched registry entry. Always 0 here for the same reason as
    // manifest_violations — a non-empty $acceptedRiskViolations already
    // exited above.
    'unapproved_gaps'               => count(array_filter($rows, $auditor->isUnapprovedGap(...))),
    'accepted_risk_violations'      => 0,
];

if ($format === 'json') {
    echo json_encode(
        ['plugins' => $pluginNames, 'summary' => $summary, 'rows' => $rows, 'manifest_violations' => [], 'accepted_risk_violations' => []],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ).PHP_EOL;
} else {
    $reportedRows = array_values(array_filter($rows, $auditor->shouldDisplayInTable(...)));

    $headers = ['PLUGIN', 'MODEL', 'TABLE', 'COMPANY_ID', 'SCOPE', 'STATUS', 'CLASSIFICATION', 'EFFECTIVE', 'ACCEPTED_RISK'];
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
            $row['accepted_risk_status'] ?? '-',
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
}

fwrite(
    STDERR,
    sprintf(
        "Audited %d plugin(s), %d model(s): %d scoped, %d classified exceptions, %d real gap(s) with company_id, %d real gap(s) without company_id, %d missing table(s), %d inspection error(s), %d manifest violation(s), %d accepted risk(s), %d unapproved gap(s).\n",
        count($pluginNames),
        $summary['total'],
        $summary['scoped'],
        $summary['classified_exceptions'],
        $summary['real_gaps_with_company_id'],
        $summary['real_gaps_without_company_id'],
        $summary['table_missing'],
        $summary['inspection_errors'],
        $summary['manifest_violations'],
        $summary['accepted_risks'],
        $summary['unapproved_gaps'],
    ),
);

// table_missing/inspection_error mean the audit itself is untrustworthy —
// always fatal, regardless of --fail-on-missing. (Manifest and
// accepted-risk registry violations already exited above.)
if ($summary['table_missing'] > 0 || $summary['inspection_errors'] > 0) {
    exit(2);
}

// Real (unclassified) gaps are known, pending work — only gate on them when
// the caller explicitly opts in. `--fail-on-missing` is a strict, zero-gap
// certification: it exits 1 for ANY real gap, registered as an accepted
// risk or not — the accepted-risk registry never softens this flag.
$realGapCount = $summary['real_gaps_with_company_id'] + $summary['real_gaps_without_company_id'];

if ($failOnMissing && $realGapCount > 0) {
    exit(1);
}

// The sustainable CI-gate candidate: exits 1 only for a real gap that is
// NOT covered by a registered, exact-match, non-expired accepted-risk
// entry (#138 PR4, Codex adversarial-review recommendation, 2026-08-03).
if ($failOnUnapprovedGaps && $summary['unapproved_gaps'] > 0) {
    exit(1);
}

exit(0);

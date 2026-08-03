<?php

declare(strict_types=1);

namespace App\Support\CompanyScopeAudit;

/**
 * Read-only view over config/company-scope-accepted-risks.php — a registry
 * of TEMPORARILY accepted company-scope gaps, deliberately kept separate
 * from ExceptionManifest (see that class/config file's docblock, and #138
 * PR4 post-closure review, Codex adversarial-review recommendation).
 *
 * An entry here changes ONLY the exit policy of
 * `--fail-on-unapproved-gaps` on scripts/audit-company-scope.php. It never
 * reclassifies a row as `classified_exception`, it never suppresses a row
 * from `--fail-on-missing` (which stays a strict, zero-gap certification
 * and is entirely unaffected by this file), and it never hides a row from
 * the audit's table/JSON output — the row still shows up as a
 * `real_gap_*` row, only additionally annotated with an
 * `accepted_risk_status`. This registry documents WHO accepted the risk,
 * WHY, and UNTIL WHEN, never that the gap is actually isolated.
 */
final class AcceptedRiskRegistry
{
    public const REQUIRED_FIELDS = ['table', 'tracking', 'justification', 'owner', 'review_by'];

    /**
     * @param  array<class-string, array{table: string, tracking: string, justification: string, owner: string, review_by: string}>  $entries
     */
    public function __construct(private readonly array $entries) {}

    /**
     * @param  string|null  $path  Absolute path to a registry file to load
     *                             instead of the real config/company-scope-accepted-risks.php.
     *                             Exists so tests can drive the exact same CLI orchestration
     *                             (scripts/audit-company-scope.php) against a deliberately broken
     *                             or empty fixture registry, mirroring
     *                             ExceptionManifest::default()'s COMPANY_SCOPE_MANIFEST_PATH.
     */
    public static function default(?string $path = null): self
    {
        /** @var array<class-string, array{table: string, tracking: string, justification: string, owner: string, review_by: string}> $entries */
        $entries = require $path ?? base_path('config/company-scope-accepted-risks.php');

        return new self($entries);
    }

    /**
     * @return array<class-string, array{table: string, tracking: string, justification: string, owner: string, review_by: string}>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public function has(string $fqcn): bool
    {
        return array_key_exists($fqcn, $this->entries);
    }

    /**
     * @return array{table: string, tracking: string, justification: string, owner: string, review_by: string}|null
     */
    public function get(string $fqcn): ?array
    {
        return $this->entries[$fqcn] ?? null;
    }
}

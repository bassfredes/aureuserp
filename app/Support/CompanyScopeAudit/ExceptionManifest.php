<?php

declare(strict_types=1);

namespace App\Support\CompanyScopeAudit;

/**
 * Read-only view over config/company-scope-exceptions.php — the only
 * sanctioned way to silence a `missing_scope`/`not_company_scoped` finding
 * in the auditor. See that file for the authoring contract.
 */
final class ExceptionManifest
{
    public const CLASSIFICATIONS = [
        'global_party_identity',
        'alias',
        'global_reference',
        'parent_scoped',
        'not_tenancy',
        'root_company_entity',
        'multi_company_membership',
    ];

    /**
     * Classifications that MUST carry an `alias_of` pointing at the class
     * this one delegates its identity to (validated transitively by
     * Auditor::validateManifest() — target must exist, must actually be an
     * ancestor of the alias, must share its table, and the chain must
     * terminate in a non-alias classification with no cycles).
     */
    public const REQUIRES_ALIAS_OF = ['alias'];

    /**
     * @param  array<class-string, array{table: string, classification: string, reason: string, tracking: string, alias_of?: class-string}>  $entries
     */
    public function __construct(private readonly array $entries)
    {
    }

    public static function default(): self
    {
        /** @var array<class-string, array{table: string, classification: string, reason: string, tracking: string, alias_of?: class-string}> $entries */
        $entries = require base_path('config/company-scope-exceptions.php');

        return new self($entries);
    }

    /**
     * @return array<class-string, array{table: string, classification: string, reason: string, tracking: string, alias_of?: class-string}>
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
     * @return array{table: string, classification: string, reason: string, tracking: string, alias_of?: class-string}|null
     */
    public function get(string $fqcn): ?array
    {
        return $this->entries[$fqcn] ?? null;
    }
}

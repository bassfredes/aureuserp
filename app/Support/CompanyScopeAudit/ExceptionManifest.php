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
    ];

    /**
     * @param  array<class-string, array{table: string, classification: string, reason: string, tracking: string}>  $entries
     */
    public function __construct(private readonly array $entries)
    {
    }

    public static function default(): self
    {
        /** @var array<class-string, array{table: string, classification: string, reason: string, tracking: string}> $entries */
        $entries = require base_path('config/company-scope-exceptions.php');

        return new self($entries);
    }

    /**
     * @return array<class-string, array{table: string, classification: string, reason: string, tracking: string}>
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
     * @return array{table: string, classification: string, reason: string, tracking: string}|null
     */
    public function get(string $fqcn): ?array
    {
        return $this->entries[$fqcn] ?? null;
    }
}

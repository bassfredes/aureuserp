<?php

declare(strict_types=1);

namespace App\Support\CompanyScopeAudit;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use Webkul\Support\Traits\HasCompanyScope;

/**
 * Company-scope audit engine backing scripts/audit-company-scope.php.
 * Extracted into a plain, testable class so the manifest-validation and
 * gap-classification logic can be exercised directly in tests without
 * spawning a subprocess per scenario (#138, PR 4 checkpoint).
 */
final class Auditor
{
    /**
     * @return list<class-string>
     */
    public static function classesDeclaredInFile(string $path): array
    {
        $tokens = token_get_all((string) file_get_contents($path));
        $namespace = '';
        $classes = [];
        $tokenCount = count($tokens);

        for ($index = 0; $index < $tokenCount; $index++) {
            $token = $tokens[$index];

            if (is_array($token) && $token[0] === T_NAMESPACE) {
                $namespaceParts = [];

                for ($index++; $index < $tokenCount; $index++) {
                    $namespaceToken = $tokens[$index];

                    if (is_string($namespaceToken) && ($namespaceToken === ';' || $namespaceToken === '{')) {
                        break;
                    }

                    if (is_array($namespaceToken) && in_array(
                        $namespaceToken[0],
                        [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED],
                        true,
                    )) {
                        $namespaceParts[] = $namespaceToken[1];
                    }
                }

                $namespace = implode('', $namespaceParts);

                continue;
            }

            if (! is_array($token) || $token[0] !== T_CLASS) {
                continue;
            }

            $previousIndex = $index - 1;
            while ($previousIndex >= 0) {
                $previousToken = $tokens[$previousIndex];

                if (! is_array($previousToken) || $previousToken[0] !== T_WHITESPACE) {
                    break;
                }

                $previousIndex--;
            }

            if (
                $previousIndex >= 0
                && is_array($tokens[$previousIndex])
                && $tokens[$previousIndex][0] === T_NEW
            ) {
                continue;
            }

            // `Foo::class` also tokenizes T_STRING, T_DOUBLE_COLON, T_CLASS —
            // indistinguishable from a real declaration by looking at T_CLASS
            // alone. Skip it, or the loop below picks up whatever identifier
            // happens to follow (usually the next method name in the file) as
            // a bogus "declared class".
            if (
                $previousIndex >= 0
                && is_array($tokens[$previousIndex])
                && $tokens[$previousIndex][0] === T_DOUBLE_COLON
            ) {
                continue;
            }

            for ($index++; $index < $tokenCount; $index++) {
                $classToken = $tokens[$index];

                if (is_array($classToken) && $classToken[0] === T_STRING) {
                    $classes[] = ltrim($namespace.'\\'.$classToken[1], '\\');
                    break;
                }
            }
        }

        return $classes;
    }

    /**
     * Deterministic, deduplicated, alphabetically sorted list of every
     * plugin directory under $basePath that has a `src/Models` directory —
     * the default audit scope when the caller doesn't pass `--plugins`.
     * Plugins without `src/Models` (e.g. barcode, full-calendar) are
     * silently excluded, not an error.
     *
     * @return list<string>
     */
    public function discoverPlugins(?string $basePath = null): array
    {
        $basePath ??= base_path('plugins/webkul');

        if (! is_dir($basePath)) {
            throw new RuntimeException("Plugins base path not found: {$basePath}");
        }

        $plugins = [];

        foreach (scandir($basePath) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (is_dir("{$basePath}/{$entry}/src/Models")) {
                $plugins[] = $entry;
            }
        }

        $plugins = array_values(array_unique($plugins));
        sort($plugins, SORT_STRING);

        return $plugins;
    }

    /**
     * Inspects every Eloquent model declared under a given directory.
     *
     * @return list<array{
     *     plugin: string,
     *     class: string,
     *     file: string,
     *     table: string|null,
     *     has_company_id: bool|null,
     *     uses_company_scope: bool|null,
     *     status: string,
     *     error: string|null
     * }>
     */
    public function inspectPath(string $pluginLabel, string $modelsPath): array
    {
        if (! is_dir($modelsPath)) {
            throw new RuntimeException("Models directory not found for {$pluginLabel}: {$modelsPath}");
        }

        $rows = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($modelsPath, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            foreach (self::classesDeclaredInFile($file->getPathname()) as $className) {
                $row = $this->inspectClass($pluginLabel, $className, self::relativePath($file->getPathname()));

                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * Strips the repo root prefix from an absolute path, so committed
     * artifacts (docs/security/*.json) are portable across machines/
     * containers instead of baking in `/var/www/aureuserp/...`.
     */
    public static function relativePath(string $absolute): string
    {
        $root = rtrim(base_path(), '/').'/';

        return str_starts_with($absolute, $root) ? substr($absolute, strlen($root)) : $absolute;
    }

    /**
     * @return array{
     *     plugin: string,
     *     class: string,
     *     file: string,
     *     table: string|null,
     *     has_company_id: bool|null,
     *     uses_company_scope: bool|null,
     *     status: string,
     *     error: string|null
     * }|null null when the class is not a concrete Eloquent model (e.g. an
     *     abstract base class) and is therefore not audit-relevant.
     */
    public function inspectClass(string $pluginLabel, string $className, string $file): ?array
    {
        try {
            if (! class_exists($className)) {
                return [
                    'plugin'             => $pluginLabel,
                    'class'              => $className,
                    'file'               => $file,
                    'table'              => null,
                    'has_company_id'     => null,
                    'uses_company_scope' => null,
                    'status'             => 'inspection_error',
                    'error'              => 'Class is not autoloadable.',
                ];
            }

            $reflection = new ReflectionClass($className);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                return null;
            }

            /** @var Model $model */
            $model = $reflection->newInstance();
            $table = $model->getTable();
            $schema = $model->getConnection()->getSchemaBuilder();

            if (! $schema->hasTable($table)) {
                return [
                    'plugin'             => $pluginLabel,
                    'class'              => $className,
                    'file'               => $file,
                    'table'              => $table,
                    'has_company_id'     => null,
                    'uses_company_scope' => null,
                    'status'             => 'table_missing',
                    'error'              => 'Model table is not present in the migrated schema.',
                ];
            }

            $hasCompanyId = $schema->hasColumn($table, 'company_id');
            $usesCompanyScope = in_array(HasCompanyScope::class, class_uses_recursive($className), true);

            return [
                'plugin'             => $pluginLabel,
                'class'              => $className,
                'file'               => $file,
                'table'              => $table,
                'has_company_id'     => $hasCompanyId,
                'uses_company_scope' => $usesCompanyScope,
                'status'             => ! $hasCompanyId
                    ? 'not_company_scoped'
                    : ($usesCompanyScope ? 'scoped' : 'missing_scope'),
                'error'              => null,
            ];
        } catch (Throwable $exception) {
            return [
                'plugin'             => $pluginLabel,
                'class'              => $className,
                'file'               => $file,
                'table'              => null,
                'has_company_id'     => null,
                'uses_company_scope' => null,
                'status'             => 'inspection_error',
                'error'              => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param  list<string>  $pluginNames
     * @return list<array{
     *     plugin: string,
     *     class: string,
     *     file: string,
     *     table: string|null,
     *     has_company_id: bool|null,
     *     uses_company_scope: bool|null,
     *     status: string,
     *     error: string|null
     * }>
     */
    public function inspectPlugins(array $pluginNames, ?string $basePath = null): array
    {
        $basePath ??= base_path('plugins/webkul');
        $rows = [];

        foreach ($pluginNames as $pluginName) {
            array_push($rows, ...$this->inspectPath($pluginName, "{$basePath}/{$pluginName}/src/Models"));
        }

        usort(
            $rows,
            static fn (array $left, array $right): int => [$left['plugin'], $left['class']] <=> [$right['plugin'], $right['class']],
        );

        return $rows;
    }

    /**
     * Annotates rows with their manifest classification, when the manifest
     * carries a (valid) entry for that class. Does NOT validate the
     * manifest itself — see validateManifest() for that, which runs
     * independently of which rows were scanned.
     *
     * @param  list<array{plugin: string, class: string, file: string, table: string|null, has_company_id: bool|null, uses_company_scope: bool|null, status: string, error: string|null}>  $rows
     * @return list<array{plugin: string, class: string, file: string, table: string|null, has_company_id: bool|null, uses_company_scope: bool|null, status: string, error: string|null, classification: string|null, effective_status: string}>
     */
    public function classifyRows(array $rows, ExceptionManifest $manifest): array
    {
        return array_map(function (array $row) use ($manifest): array {
            $entry = $manifest->get($row['class']);

            $row['classification'] = ($entry !== null && $entry['table'] === $row['table'])
                ? $entry['classification']
                : null;

            $row['effective_status'] = $this->effectiveStatus($row);

            return $row;
        }, $rows);
    }

    /**
     * Collapses the raw scan status + manifest classification into one of:
     * `scoped`, `classified_exception`, `real_gap_company_column`,
     * `real_gap_without_company_column`, `table_missing`, `inspection_error`.
     *
     * @param  array{status: string, classification: string|null}  $row
     */
    public function effectiveStatus(array $row): string
    {
        if (in_array($row['status'], ['scoped', 'table_missing', 'inspection_error'], true)) {
            return $row['status'];
        }

        if ($row['classification'] !== null) {
            return 'classified_exception';
        }

        return match ($row['status']) {
            'missing_scope'      => 'real_gap_company_column',
            'not_company_scoped' => 'real_gap_without_company_column',
            default               => $row['status'],
        };
    }

    /**
     * A real, unclassified gap: either has `company_id` without
     * `HasCompanyScope`, or has no `company_id` at all and no valid
     * manifest entry explaining why that's fine. `parent_scoped` only
     * counts as classified (not a gap) once it's an actual manifest entry
     * backed by real enforcement code — declaring the classification alone
     * never suppresses a row that isn't in the manifest.
     *
     * @param  array{effective_status: string}  $row
     */
    public function isRealGap(array $row): bool
    {
        return str_starts_with($row['effective_status'], 'real_gap_');
    }

    /**
     * Validates every manifest entry against live reflection, independent
     * of which plugins were passed to inspectPlugins() — a partial
     * `--plugins=` run must still catch a stale/broken exception anywhere
     * in the manifest, not only within the scanned subset.
     *
     * @return list<array{fqcn: string, type: string, message: string}>
     */
    public function validateManifest(ExceptionManifest $manifest): array
    {
        $violations = [];
        $entries = $manifest->entries();

        foreach ($entries as $fqcn => $entry) {
            $violations = array_merge($violations, $this->validateEntryShape($fqcn, $entry));
        }

        foreach ($entries as $fqcn => $entry) {
            $violations = array_merge($violations, $this->validateEntryAgainstReflection($fqcn, $entry));
        }

        foreach (array_keys($entries) as $fqcn) {
            $chainViolation = $this->validateAliasChain($fqcn, $entries);

            if ($chainViolation !== null) {
                $violations[] = $chainViolation;
            }
        }

        return $violations;
    }

    /**
     * @param  array{table?: mixed, classification?: mixed, reason?: mixed, tracking?: mixed, alias_of?: mixed}  $entry
     * @return list<array{fqcn: string, type: string, message: string}>
     */
    private function validateEntryShape(string $fqcn, array $entry): array
    {
        $violations = [];

        foreach (['table', 'classification', 'reason', 'tracking'] as $field) {
            if (! isset($entry[$field]) || ! is_string($entry[$field]) || trim($entry[$field]) === '') {
                $violations[] = [
                    'fqcn'    => $fqcn,
                    'type'    => 'invalid_shape',
                    'message' => "Manifest entry is missing a non-empty '{$field}' field.",
                ];
            }
        }

        if (
            isset($entry['classification'])
            && is_string($entry['classification'])
            && in_array($entry['classification'], ExceptionManifest::REQUIRES_ALIAS_OF, true)
            && (! isset($entry['alias_of']) || ! is_string($entry['alias_of']) || trim($entry['alias_of']) === '')
        ) {
            $violations[] = [
                'fqcn'    => $fqcn,
                'type'    => 'invalid_shape',
                'message' => "Classification '{$entry['classification']}' requires a non-empty 'alias_of' field naming the class it delegates to.",
            ];
        }

        if (
            isset($entry['classification'])
            && is_string($entry['classification'])
            && ! in_array($entry['classification'], ExceptionManifest::CLASSIFICATIONS, true)
        ) {
            $violations[] = [
                'fqcn'    => $fqcn,
                'type'    => 'invalid_classification',
                'message' => "Unknown classification '{$entry['classification']}' — must be one of: ".implode(', ', ExceptionManifest::CLASSIFICATIONS).'.',
            ];
        }

        return $violations;
    }

    /**
     * @param  array{table: string, classification: string, reason: string, tracking: string, alias_of?: string}  $entry
     * @return list<array{fqcn: string, type: string, message: string}>
     */
    private function validateEntryAgainstReflection(string $fqcn, array $entry): array
    {
        if (! class_exists($fqcn)) {
            return [[
                'fqcn'    => $fqcn,
                'type'    => 'class_not_found',
                'message' => 'Class is not autoloadable — the manifest entry is dangling and must be removed.',
            ]];
        }

        try {
            $reflection = new ReflectionClass($fqcn);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                return [[
                    'fqcn'    => $fqcn,
                    'type'    => 'class_not_found',
                    'message' => 'Class exists but is not a concrete Eloquent model — the manifest entry does not target an auditable class.',
                ]];
            }

            /** @var Model $model */
            $model = $reflection->newInstance();
            $table = $model->getTable();
            $schema = $model->getConnection()->getSchemaBuilder();

            if ($table !== $entry['table']) {
                return [[
                    'fqcn'    => $fqcn,
                    'type'    => 'table_mismatch',
                    'message' => "Manifest records table '{$entry['table']}' but the model's actual table is now '{$table}'.",
                ]];
            }

            if (! $schema->hasTable($table)) {
                return [[
                    'fqcn'    => $fqcn,
                    'type'    => 'table_mismatch',
                    'message' => "Table '{$table}' is not present in the migrated schema — cannot verify this entry.",
                ]];
            }

            $usesCompanyScope = in_array(HasCompanyScope::class, class_uses_recursive($fqcn), true);

            if ($usesCompanyScope) {
                return [[
                    'fqcn'    => $fqcn,
                    'type'    => 'stale_exception',
                    'message' => 'The model now uses HasCompanyScope for real — this exception is no longer needed and must be removed so it cannot mask a future regression.',
                ]];
            }

            if (
                isset($entry['alias_of'])
                && is_string($entry['alias_of'])
                && $entry['alias_of'] !== ''
                && class_exists($entry['alias_of'])
            ) {
                if (! is_subclass_of($fqcn, $entry['alias_of'])) {
                    return [[
                        'fqcn'    => $fqcn,
                        'type'    => 'alias_chain_broken',
                        'message' => "'{$fqcn}' does not actually extend its declared alias_of target '{$entry['alias_of']}'.",
                    ]];
                }

                /** @var Model $aliasTarget */
                $aliasTarget = new ($entry['alias_of'])();

                if ($aliasTarget->getTable() !== $table) {
                    return [[
                        'fqcn'    => $fqcn,
                        'type'    => 'alias_chain_broken',
                        'message' => "'{$fqcn}' and its alias_of target '{$entry['alias_of']}' point at different tables.",
                    ]];
                }
            }
        } catch (Throwable $exception) {
            return [[
                'fqcn'    => $fqcn,
                'type'    => 'class_not_found',
                'message' => 'Failed to reflect/instantiate the class: '.$exception->getMessage(),
            ]];
        }

        return [];
    }

    /**
     * Walks an `alias` entry's `alias_of` pointers until it reaches a
     * non-alias classification (chain terminates), a manifest entry that
     * doesn't exist (chain broken), or revisits a class already seen in
     * this walk (cycle).
     *
     * @param  array<class-string, array{table?: mixed, classification?: mixed, alias_of?: mixed}>  $entries
     */
    private function validateAliasChain(string $fqcn, array $entries): ?array
    {
        $entry = $entries[$fqcn] ?? null;

        if ($entry === null || ($entry['classification'] ?? null) !== 'alias') {
            return null;
        }

        $visited = [$fqcn => true];
        $current = $entry['alias_of'] ?? null;

        while (is_string($current) && $current !== '') {
            if (isset($visited[$current])) {
                return [
                    'fqcn'    => $fqcn,
                    'type'    => 'alias_chain_cycle',
                    'message' => "Alias chain starting at '{$fqcn}' cycles back through '{$current}'.",
                ];
            }

            $visited[$current] = true;
            $next = $entries[$current] ?? null;

            if ($next === null) {
                if (class_exists($current)) {
                    // Terminal target has no manifest entry of its own —
                    // fine as long as it isn't itself an unclassified gap;
                    // that's caught separately by the real-gap count, not
                    // here (this method only validates the manifest's own
                    // internal consistency).
                    return null;
                }

                return [
                    'fqcn'    => $fqcn,
                    'type'    => 'alias_chain_broken',
                    'message' => "Alias chain starting at '{$fqcn}' points to '{$current}', which has no manifest entry and is not an autoloadable class.",
                ];
            }

            if (($next['classification'] ?? null) !== 'alias') {
                return null;
            }

            $current = $next['alias_of'] ?? null;
        }

        return null;
    }
}

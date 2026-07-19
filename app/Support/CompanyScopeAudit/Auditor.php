<?php

declare(strict_types=1);

namespace App\Support\CompanyScopeAudit;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
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
            throw new \RuntimeException("Models directory not found for {$pluginLabel}: {$modelsPath}");
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
                $row = $this->inspectClass($pluginLabel, $className, $file->getPathname());

                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
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
     * @return list<array{plugin: string, class: string, file: string, table: string|null, has_company_id: bool|null, uses_company_scope: bool|null, status: string, error: string|null, classification: string|null}>
     */
    public function classifyRows(array $rows, ExceptionManifest $manifest): array
    {
        return array_map(function (array $row) use ($manifest): array {
            $entry = $manifest->get($row['class']);

            $row['classification'] = ($entry !== null && $entry['table'] === $row['table'])
                ? $entry['classification']
                : null;

            return $row;
        }, $rows);
    }

    /**
     * A real, unclassified `missing_scope` finding: has company_id, does not
     * use HasCompanyScope, and has no valid manifest entry covering it.
     *
     * @param  array{status: string, classification: string|null}  $row
     */
    public function isRealMissingScope(array $row): bool
    {
        return $row['status'] === 'missing_scope' && $row['classification'] === null;
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

        foreach ($manifest->entries() as $fqcn => $entry) {
            if (! in_array($entry['classification'], ExceptionManifest::CLASSIFICATIONS, true)) {
                $violations[] = [
                    'fqcn'    => $fqcn,
                    'type'    => 'invalid_classification',
                    'message' => "Unknown classification '{$entry['classification']}' — must be one of: ".implode(', ', ExceptionManifest::CLASSIFICATIONS).'.',
                ];
            }

            if (! class_exists($fqcn)) {
                $violations[] = [
                    'fqcn'    => $fqcn,
                    'type'    => 'class_not_found',
                    'message' => 'Class is not autoloadable — the manifest entry is dangling and must be removed.',
                ];

                continue;
            }

            try {
                $reflection = new ReflectionClass($fqcn);

                if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                    $violations[] = [
                        'fqcn'    => $fqcn,
                        'type'    => 'class_not_found',
                        'message' => 'Class exists but is not a concrete Eloquent model — the manifest entry does not target an auditable class.',
                    ];

                    continue;
                }

                /** @var Model $model */
                $model = $reflection->newInstance();
                $table = $model->getTable();
                $schema = $model->getConnection()->getSchemaBuilder();

                if ($table !== $entry['table']) {
                    $violations[] = [
                        'fqcn'    => $fqcn,
                        'type'    => 'table_mismatch',
                        'message' => "Manifest records table '{$entry['table']}' but the model's actual table is now '{$table}'.",
                    ];

                    continue;
                }

                if (! $schema->hasTable($table)) {
                    $violations[] = [
                        'fqcn'    => $fqcn,
                        'type'    => 'table_mismatch',
                        'message' => "Table '{$table}' is not present in the migrated schema — cannot verify this entry.",
                    ];

                    continue;
                }

                $hasCompanyId = $schema->hasColumn($table, 'company_id');
                $usesCompanyScope = in_array(HasCompanyScope::class, class_uses_recursive($fqcn), true);

                if ($hasCompanyId && $usesCompanyScope) {
                    $violations[] = [
                        'fqcn'    => $fqcn,
                        'type'    => 'stale_exception',
                        'message' => 'The model now uses HasCompanyScope for real — this exception is no longer needed and must be removed so it cannot mask a future regression.',
                    ];
                }
            } catch (Throwable $exception) {
                $violations[] = [
                    'fqcn'    => $fqcn,
                    'type'    => 'class_not_found',
                    'message' => 'Failed to reflect/instantiate the class: '.$exception->getMessage(),
                ];
            }
        }

        return $violations;
    }
}

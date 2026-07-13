#!/usr/bin/env php
<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Webkul\Support\Traits\HasCompanyScope;

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$options = getopt('', ['plugins::', 'format::', 'fail-on-missing']);
$pluginNames = array_values(array_filter(
    array_map('trim', explode(',', (string) ($options['plugins'] ?? 'inventories,purchases,products'))),
    static fn (string $plugin): bool => $plugin !== '',
));
$format = (string) ($options['format'] ?? 'table');
$failOnMissing = array_key_exists('fail-on-missing', $options);

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

/**
 * @return list<class-string>
 */
function classesDeclaredInFile(string $path): array
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
 * @return list<array{
 *     plugin: string,
 *     class: string,
 *     table: string|null,
 *     has_company_id: bool|null,
 *     uses_company_scope: bool|null,
 *     status: string,
 *     error: string|null
 * }>
 */
function inspectPlugin(string $pluginName): array
{
    $modelsPath = dirname(__DIR__)."/plugins/webkul/{$pluginName}/src/Models";

    if (! is_dir($modelsPath)) {
        throw new RuntimeException("Models directory not found for plugin {$pluginName}: {$modelsPath}");
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

        foreach (classesDeclaredInFile($file->getPathname()) as $className) {
            try {
                if (! class_exists($className)) {
                    continue;
                }

                $reflection = new ReflectionClass($className);

                if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                    continue;
                }

                /** @var Model $model */
                $model = $reflection->newInstance();
                $table = $model->getTable();
                $hasCompanyId = $model->getConnection()->getSchemaBuilder()->hasColumn($table, 'company_id');
                $usesCompanyScope = in_array(HasCompanyScope::class, class_uses_recursive($className), true);

                $rows[] = [
                    'plugin'             => $pluginName,
                    'class'              => $className,
                    'table'              => $table,
                    'has_company_id'     => $hasCompanyId,
                    'uses_company_scope' => $usesCompanyScope,
                    'status'             => ! $hasCompanyId
                        ? 'not_company_scoped'
                        : ($usesCompanyScope ? 'scoped' : 'missing_scope'),
                    'error'              => null,
                ];
            } catch (Throwable $exception) {
                $rows[] = [
                    'plugin'             => $pluginName,
                    'class'              => $className,
                    'table'              => null,
                    'has_company_id'     => null,
                    'uses_company_scope' => null,
                    'status'             => 'inspection_error',
                    'error'              => $exception->getMessage(),
                ];
            }
        }
    }

    return $rows;
}

$rows = [];

try {
    foreach ($pluginNames as $pluginName) {
        array_push($rows, ...inspectPlugin($pluginName));
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(2);
}

usort(
    $rows,
    static fn (array $left, array $right): int => [$left['plugin'], $left['class']] <=> [$right['plugin'], $right['class']],
);

if ($format === 'json') {
    echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
} else {
    $reportedRows = array_values(array_filter(
        $rows,
        static fn (array $row): bool => $row['has_company_id'] !== false,
    ));

    $headers = ['PLUGIN', 'MODEL', 'TABLE', 'COMPANY_ID', 'SCOPE', 'STATUS'];
    $displayRows = array_map(
        static fn (array $row): array => [
            $row['plugin'],
            $row['class'],
            $row['table'] ?? '-',
            $row['has_company_id'] === null ? '?' : ($row['has_company_id'] ? 'yes' : 'no'),
            $row['uses_company_scope'] === null ? '?' : ($row['uses_company_scope'] ? 'yes' : 'no'),
            $row['status'],
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

$missingCount = count(array_filter(
    $rows,
    static fn (array $row): bool => $row['status'] === 'missing_scope',
));
$errorCount = count(array_filter(
    $rows,
    static fn (array $row): bool => $row['status'] === 'inspection_error',
));

fwrite(
    STDERR,
    sprintf(
        "Inspected %d model(s): %d missing CompanyScope, %d inspection error(s).\n",
        count($rows),
        $missingCount,
        $errorCount,
    ),
);

if ($errorCount > 0) {
    exit(2);
}

if ($failOnMissing && $missingCount > 0) {
    exit(1);
}

exit(0);

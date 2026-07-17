<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Support\Services\CompanyContext;

class TestBootstrapHelper
{
    private static bool $isERPInstalled = false;

    /**
     * Many fixture-setup helpers are shared between tests that call them
     * before actingAs() (no user yet) and tests that call them after
     * (already authenticated) — CompanyScope now fails closed for the
     * no-user case with no active CompanyContext (ADR 0007), while
     * CompanyContext itself refuses to open one for an already-
     * authenticated actor. This picks whichever is correct for the caller
     * at the moment it runs, so fixture helpers don't need two copies.
     */
    public static function withSystemContextIfNoUser(callable $callback): mixed
    {
        if (Auth::check()) {
            return $callback();
        }

        return CompanyContext::runForAllCompanies(
            reason: 'test fixture setup — no acting user yet',
            caller: 'TestBootstrapHelper',
            callback: $callback,
        );
    }

    // Allowlist explicita de bases contra las que este helper puede correr
    // migrate:fresh (DDL destructivo). Un denylist de un unico nombre
    // conocido ("db_aureuserp") no es fail-closed: cualquier otra base
    // compartida con otro nombre lo hubiera atravesado igual. Debe
    // configurarse via env, nunca inferirse. Convencion: db_aureuserp_test
    // en local, "aureuserp" en CI (ver .github/workflows/pest_tests.yml).
    private const ALLOWED_DATABASES_ENV = 'TEST_BOOTSTRAP_ALLOWED_DATABASES';

    public static function ensurePluginInstalled(string $pluginName): void
    {
        $pluginTables = [
            'projects'      => 'projects_projects',
            'sales'         => 'sales_orders',
            'purchases'     => 'purchases_orders',
            'inventories'   => 'inventories_operations',
            'accounts'      => 'accounts_account_moves',
            'products'      => 'products_products',
            'manufacturing' => 'manufacturing_orders',
        ];

        $table = $pluginTables[$pluginName] ?? null;

        if (! $table) {
            throw new InvalidArgumentException("Unknown plugin: {$pluginName}");
        }

        static::ensureERPInstalled();

        if (Schema::hasTable($table)) {
            return;
        }

        Artisan::call("{$pluginName}:install", ['--no-interaction' => true]);

        // Re-register the plugin's routes into the already-booted application.
        // On CI, the app boots before beforeEach installs the plugin, so routes
        // are skipped in PackageServiceProvider::boot(). Loading them here ensures
        // the first test in each file can resolve named routes correctly.
        static::loadPluginRoutes($pluginName);
    }

    private static function loadPluginRoutes(string $pluginName): void
    {
        $routeFile = base_path("plugins/webkul/{$pluginName}/routes/api.php");

        if (file_exists($routeFile) && ! app()->routesAreCached()) {
            require $routeFile;
        }
    }

    public static function ensureERPInstalled(): void
    {
        if (static::$isERPInstalled) {
            return;
        }

        static::assertSafeToRunDestructiveBootstrap();

        Artisan::call('migrate:fresh', ['--force' => true]);

        Artisan::call('erp:install', [
            '--force'          => true,
            '--admin-name'     => 'Test Admin',
            // admin@erp.localhost -- alineado con el admin real de la base
            // compartida db_aureuserp (confirmado en preflight #145, Tarea 0).
            '--admin-email'    => 'admin@erp.localhost',
            '--admin-password' => 'Admin123456',
        ]);

        static::$isERPInstalled = true;
    }

    /**
     * Fail-closed guard: refuse to run destructive DDL (migrate:fresh)
     * outside a testing environment or against a database not explicitly
     * allowlisted via TEST_BOOTSTRAP_ALLOWED_DATABASES. Does not infer
     * safety from DatabaseTransactions — that trait only wraps individual
     * test bodies, it does not make migrate:fresh reversible. An empty or
     * unset allowlist refuses everything (fail-closed default), not just a
     * single known-dangerous name.
     *
     * Public so tests can exercise the decision in isolation, without
     * paying for (or risking) an actual migrate:fresh run.
     */
    public static function assertSafeToRunDestructiveBootstrap(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException(
                'TestBootstrapHelper::ensureERPInstalled() runs migrate:fresh (destructive DDL) and refuses to run outside APP_ENV=testing. Current environment: '.app()->environment()
            );
        }

        $database = DB::connection()->getDatabaseName();
        $allowedDatabases = static::allowedDatabases();

        if ($allowedDatabases === [] || ! in_array($database, $allowedDatabases, true)) {
            throw new RuntimeException(
                "Refusing to run migrate:fresh against \"{$database}\" — it is not in ".self::ALLOWED_DATABASES_ENV.' (currently: ['.implode(', ', $allowedDatabases).']). Set that env var to the dedicated test database name before running this suite. This guard exists after an incident where this helper wiped the shared db_aureuserp database (see the tooling-safety issue linked from #145).'
            );
        }
    }

    private static function allowedDatabases(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(self::ALLOWED_DATABASES_ENV, '')),
        )));
    }
}

<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TestBootstrapHelper
{
    private static bool $isERPInstalled = false;

    // Nombre real de la base compartida del stack de desarrollo local
    // (docker-compose.yml raiz del monorepo, servicio mysql). CI usa su
    // propio contenedor mysql efimero con DB_DATABASE=aureuserp (ver
    // .github/workflows/pest_tests.yml), asi que ese nombre nunca choca con
    // este denylist. Agregado tras un incidente en el que este helper
    // corrio migrate:fresh contra esta base compartida y la vacio.
    private const FORBIDDEN_DATABASES = ['db_aureuserp'];

    public static function ensurePluginInstalled(string $pluginName): void
    {
        $pluginTables = [
            'projects'    => 'projects_projects',
            'sales'       => 'sales_orders',
            'purchases'   => 'purchases_orders',
            'inventories' => 'inventories_operations',
            'accounts'    => 'accounts_account_moves',
            'products'    => 'products_products',
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
            '--admin-email'    => 'admin@example.com',
            '--admin-password' => 'Admin123456',
        ]);

        static::$isERPInstalled = true;
    }

    /**
     * Fail-closed guard: refuse to run destructive DDL (migrate:fresh)
     * outside a testing environment or against a database known to be the
     * shared local dev database. Does not infer safety from
     * DatabaseTransactions — that trait only wraps individual test bodies,
     * it does not make migrate:fresh reversible.
     */
    private static function assertSafeToRunDestructiveBootstrap(): void
    {
        if (! app()->environment('testing')) {
            throw new \RuntimeException(
                'TestBootstrapHelper::ensureERPInstalled() runs migrate:fresh (destructive DDL) and refuses to run outside APP_ENV=testing. Current environment: '.app()->environment()
            );
        }

        $database = DB::connection()->getDatabaseName();

        if (in_array($database, self::FORBIDDEN_DATABASES, true)) {
            throw new \RuntimeException(
                "Refusing to run migrate:fresh against \"{$database}\" — this is the shared local dev database, not an isolated test database. Point DB_DATABASE at a dedicated test database before running this suite. This guard exists after an incident where this helper wiped {$database} (see the tooling-safety issue linked from #145)."
            );
        }
    }
}

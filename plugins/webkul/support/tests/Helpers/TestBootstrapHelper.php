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

    /**
     * Every plugin with its own `<plugin>:install` Artisan command, in the
     * exact order docs/security/company-scope-pr4-inventory.md's checkpoint
     * recipe installs them (each install command runs its own dependency
     * chain, e.g. manufacturing:install installs products/inventories
     * first). Installed unconditionally, once, right after erp:install —
     * NOT lazily per test file — so the final schema is identical
     * regardless of which test file happens to run first in the process
     * (#138 PR4 ola4A round 2 review: the previous lazy, on-demand
     * approach — plus "website" never being a recognized plugin name here
     * at all — meant a local full-suite run's schema depended on file
     * discovery order, and some plugins with no test ever calling
     * ensurePluginInstalled() for them were never installed at all).
     */
    private const ALL_PLUGINS = [
        'accounting', 'accounts', 'barcode', 'blogs', 'contacts', 'employees',
        'full-calendar', 'inventories', 'invoices', 'maintenance', 'manufacturing',
        'payments', 'products', 'projects', 'purchases', 'recruitments', 'sales',
        'time-off', 'timesheets', 'website',
    ];

    public static function ensurePluginInstalled(string $pluginName): void
    {
        if (! in_array($pluginName, self::ALL_PLUGINS, true)) {
            throw new InvalidArgumentException("Unknown plugin: {$pluginName}");
        }

        static::ensureERPInstalled();

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
        static::assertDatabaseNotAlreadyBootstrapped();

        // DatabaseTransactions has already opened a test transaction by the
        // time this first-ever call happens from inside a test's
        // beforeEach() — but this bootstrap runs many DDL statements
        // (migrate:fresh, every plugin's own migrations), and DDL always
        // auto-commits in MySQL regardless of any surrounding transaction.
        // Running all of it "inside" a transaction Laravel still THINKS is
        // open desyncs Laravel's transaction bookkeeping from MySQL's real
        // state — the data seeded by every plugin's install command then
        // gets silently rolled back the moment that first test ends,
        // reproducing exactly the schema-depends-on-file-order bug this
        // determinism fix exists to close (#138 PR4 ola4A round 2 review).
        // Committing whatever is already open first (and reopening it
        // afterward, since DatabaseTransactions' own tearDown() expects to
        // roll one back) keeps everything installed here permanent.
        $connection = DB::connection();
        $transactionLevel = $connection->transactionLevel();

        for ($i = $transactionLevel; $i > 0; $i--) {
            $connection->commit();
        }

        Artisan::call('migrate:fresh', ['--force' => true]);

        Artisan::call('erp:install', [
            '--force'          => true,
            '--admin-name'     => 'Test Admin',
            // admin@erp.localhost -- alineado con el admin real de la base
            // compartida db_aureuserp (confirmado en preflight #145, Tarea 0).
            '--admin-email'    => 'admin@erp.localhost',
            '--admin-password' => 'Admin123456',
        ]);

        foreach (self::ALL_PLUGINS as $pluginName) {
            Artisan::call("{$pluginName}:install", ['--no-interaction' => true]);
        }

        static::$isERPInstalled = true;

        for ($i = 0; $i < $transactionLevel; $i++) {
            $connection->beginTransaction();
        }
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

    /**
     * Determinism guard (#138 PR4 ola4A round 2, items A18-01/A18-02):
     * refuses to bootstrap against a database that a PRIOR process already
     * installed plugins into, instead of silently corrupting it.
     *
     * Root cause, found by direct repro (two separate PHP processes against
     * the same never-recreated database): `Webkul\PluginManager\Package::
     * isPluginInstalled()` decides whether a plugin's own service provider
     * registers its migration paths (`loadMigrationsFrom()`) by querying the
     * live `plugins` table — and that query runs during THIS process's own
     * application boot, which happens BEFORE any of this class's code runs
     * at all (Pest boots the app first, then calls into a test's
     * beforeEach()). So:
     *
     *   - Against a truly empty database (no `plugins` table yet), most
     *     plugins' migrations aren't registered yet at boot time — only
     *     core/support's. `migrate:fresh` only ever sees that small, safe
     *     subset, and succeeds.
     *   - Against a database a PRIOR process already installed plugins
     *     into, THIS process's boot sees every plugin marked installed
     *     (the DB row is still there, migrate:fresh hasn't run yet) — so
     *     every plugin's migrations get registered this time. `migrate:
     *     fresh` now processes a much larger combined set, ordered by
     *     filename/timestamp across every plugin, and hits a genuine
     *     pre-existing defect: `support`'s `2026_04_02_..._create_calendars_
     *     table` migration is dated LATER than several other plugins'
     *     migrations that add a foreign key to `calendars` (e.g.
     *     `employees`'s `2024_12_12_..._create_employees_employees_table`,
     *     and separately `manufacturing`'s `..._create_manufacturing_work_
     *     centers_table`) — those FKs fail with MySQL error 1824 because
     *     `calendars` hasn't been created yet in that ordering.
     *
     * This is a real migration-timestamp defect in those plugins, not a
     * company-scope bug — fixing it would mean renaming/re-dating shipped
     * migration files, a production schema change out of scope here and
     * riskier than the harness fix. CI never hits it: every CI run uses a
     * brand-new ephemeral MySQL service, so the `plugins` table never
     * pre-exists at boot, matching the safe "truly empty" case every time.
     * Locally, reusing the same MySQL database across separate `vendor/bin/
     * pest` invocations is what exposes it.
     *
     * The contract this enforces: every process that calls
     * ensureERPInstalled() must be the FIRST to ever touch this database.
     * Recreate it (DROP DATABASE + CREATE DATABASE, or at minimum
     * `Schema::dropAllTables()` before the NEXT process starts, never
     * mid-process) before every invocation that needs a guaranteed-clean
     * bootstrap. Violating that contract now fails loud, with this exact
     * explanation, instead of leaving the database half-migrated and every
     * subsequent test failing with an unrelated "table not found".
     */
    private static function assertDatabaseNotAlreadyBootstrapped(): void
    {
        if (! Schema::hasTable('plugins')) {
            return;
        }

        $installedCount = DB::table('plugins')->where('is_installed', true)->count();

        if ($installedCount === 0) {
            return;
        }

        $database = DB::connection()->getDatabaseName();

        throw new RuntimeException(
            "Refusing to bootstrap against \"{$database}\" — it already has {$installedCount} plugin(s) marked installed from a PRIOR process. Bootstrapping again here would make this process's application boot register a wider migration set than a truly empty database would (see this method's docblock for why), which hits a real migration-ordering defect (a `calendars` migration dated after several plugins that foreign-key into it) and leaves the schema half-migrated. Drop and recreate \"{$database}\" (or run Schema::dropAllTables()) before starting this process, not from inside it."
        );
    }

    private static function allowedDatabases(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(self::ALLOWED_DATABASES_ENV, '')),
        )));
    }
}

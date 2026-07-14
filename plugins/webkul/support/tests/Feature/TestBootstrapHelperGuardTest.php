<?php

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Helpers/TestBootstrapHelper.php';

function withMysqlDatabase(string $database, callable $callback): void
{
    $originalDatabase = config('database.connections.mysql.database');
    config(['database.connections.mysql.database' => $database]);
    DB::purge('mysql');

    try {
        $callback();
    } finally {
        config(['database.connections.mysql.database' => $originalDatabase]);
        DB::purge('mysql');
    }
}

function setTestBootstrapAllowedDatabasesEnv(?string $value): void
{
    // Laravel's env() resuelve $_ENV/$_SERVER antes que getenv() (via
    // Illuminate\Support\Env) — un valor real de proceso (p.ej. -e de
    // docker run) queda en $_ENV/$_SERVER desde el arranque; putenv() solo
    // no lo sobreescribe. Hay que tocar los tres para que env() vea el
    // cambio de forma confiable dentro del test.
    if ($value === null) {
        putenv('TEST_BOOTSTRAP_ALLOWED_DATABASES');
        unset($_ENV['TEST_BOOTSTRAP_ALLOWED_DATABASES'], $_SERVER['TEST_BOOTSTRAP_ALLOWED_DATABASES']);

        return;
    }

    putenv("TEST_BOOTSTRAP_ALLOWED_DATABASES={$value}");
    $_ENV['TEST_BOOTSTRAP_ALLOWED_DATABASES'] = $value;
    $_SERVER['TEST_BOOTSTRAP_ALLOWED_DATABASES'] = $value;
}

function withAllowedDatabasesEnv(?string $value, callable $callback): void
{
    $hadOriginal = array_key_exists('TEST_BOOTSTRAP_ALLOWED_DATABASES', $_ENV) || getenv('TEST_BOOTSTRAP_ALLOWED_DATABASES') !== false;
    $originalValue = $_ENV['TEST_BOOTSTRAP_ALLOWED_DATABASES'] ?? (getenv('TEST_BOOTSTRAP_ALLOWED_DATABASES') ?: null);

    setTestBootstrapAllowedDatabasesEnv($value);

    try {
        $callback();
    } finally {
        setTestBootstrapAllowedDatabasesEnv($hadOriginal ? $originalValue : null);
    }
}

it('refuses the known shared dev database name even with a non-empty allowlist that excludes it', function () {
    withAllowedDatabasesEnv('db_aureuserp_test', function () {
        withMysqlDatabase('db_aureuserp', function () {
            expect(fn () => TestBootstrapHelper::assertSafeToRunDestructiveBootstrap())
                ->toThrow(RuntimeException::class, 'db_aureuserp');
        });
    });
});

it('refuses any other shared-looking database name not in the allowlist', function () {
    withAllowedDatabasesEnv('db_aureuserp_test', function () {
        withMysqlDatabase('erp_shared', function () {
            expect(fn () => TestBootstrapHelper::assertSafeToRunDestructiveBootstrap())
                ->toThrow(RuntimeException::class, 'erp_shared');
        });
    });
});

it('refuses everything when the allowlist is empty — fail-closed default', function () {
    withAllowedDatabasesEnv('', function () {
        withMysqlDatabase('db_aureuserp_test', function () {
            expect(fn () => TestBootstrapHelper::assertSafeToRunDestructiveBootstrap())
                ->toThrow(RuntimeException::class, 'db_aureuserp_test');
        });
    });
});

it('accepts a database explicitly named in the allowlist', function () {
    withAllowedDatabasesEnv('db_aureuserp_test', function () {
        withMysqlDatabase('db_aureuserp_test', function () {
            // Solo se ejercita la decision del guard — nunca se llega a
            // migrate:fresh, no hace falta pagar (ni arriesgar) esa corrida
            // real para probar que el guard deja pasar un caso valido.
            expect(fn () => TestBootstrapHelper::assertSafeToRunDestructiveBootstrap())
                ->not->toThrow(RuntimeException::class);
        });
    });
});

it('refuses to run migrate:fresh outside APP_ENV=testing', function () {
    withAllowedDatabasesEnv('db_aureuserp_test', function () {
        $originalEnv = app()->environment();
        app()['env'] = 'local';

        try {
            expect(fn () => TestBootstrapHelper::assertSafeToRunDestructiveBootstrap())
                ->toThrow(RuntimeException::class, 'APP_ENV=testing');
        } finally {
            app()['env'] = $originalEnv;
        }
    });
});

<?php

use Illuminate\Support\Facades\DB;

function resetTestBootstrapHelperInstalledFlag(): void
{
    $property = new ReflectionProperty(TestBootstrapHelper::class, 'isERPInstalled');
    $property->setAccessible(true);
    $property->setValue(null, false);
}

it('refuses to run migrate:fresh against the shared dev database name', function () {
    resetTestBootstrapHelperInstalledFlag();

    $originalDatabase = config('database.connections.mysql.database');
    config(['database.connections.mysql.database' => 'db_aureuserp']);
    DB::purge('mysql');

    try {
        expect(fn () => TestBootstrapHelper::ensureERPInstalled())
            ->toThrow(RuntimeException::class, 'db_aureuserp');
    } finally {
        config(['database.connections.mysql.database' => $originalDatabase]);
        DB::purge('mysql');
        resetTestBootstrapHelperInstalledFlag();
    }
});

it('refuses to run migrate:fresh outside APP_ENV=testing', function () {
    resetTestBootstrapHelperInstalledFlag();

    $originalEnv = app()->environment();
    app()['env'] = 'local';

    try {
        expect(fn () => TestBootstrapHelper::ensureERPInstalled())
            ->toThrow(RuntimeException::class, 'APP_ENV=testing');
    } finally {
        app()['env'] = $originalEnv;
        resetTestBootstrapHelperInstalledFlag();
    }
});

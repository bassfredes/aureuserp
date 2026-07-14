<?php
// apps/aureuserp/tests/Feature/Console/SeedGoldStandardDatasetCommandTest.php

use Illuminate\Support\Facades\Schema;
use Webkul\Inventory\Models\Warehouse;
use Webkul\Security\Models\User;
use Webkul\Support\Models\UOM;

require_once __DIR__.'/../../../plugins/webkul/support/tests/Helpers/TestBootstrapHelper.php';

// NOTA (bug preexistente en TestBootstrapHelper, separado de #145 y #147,
// NO corregido aqui): ensureERPInstalled() ejecuta migrate:fresh + erp:install
// SIEMPRE que el flag estatico en memoria este en false — lo cual ocurre en
// cada proceso PHP nuevo, incluido el primer test que corre dentro de la
// transaccion que DatabaseTransactions ya abrio para ESE test (Pest.php:19,
// aplica a tests/Feature via ->in()). Las sentencias DDL de migrate:fresh
// (CREATE/DROP TABLE) producen COMMIT implicito en MySQL sin que Laravel se
// entere, dejando el tracking de transaccion desincronizado: los seeders que
// corren justo despues (p.ej. LocationSeeder, que crea la location raiz
// id=1 de la que depende cada Warehouse::create()) terminan sin persistir
// nada visible pese a reportar "DONE". Verificado: correr los mismos 3
// comandos (migrate:fresh, erp:install, inventories:install) como procesos
// `php artisan` independientes (fuera de cualquier transaccion de test)
// contra la misma base produce el resultado correcto.
// Mitigacion para este test: NO se llama a ensurePluginInstalled()/
// ensureERPInstalled() dentro del proceso de test. `db_aureuserp_test` debe
// estar pre-sembrada externamente antes de correr Pest (mismo patron que CI:
// pest_tests.yml corre `php artisan erp:install --force` como paso separado
// ANTES de `vendor/bin/pest`, nunca dentro de un test). Este beforeEach solo
// verifica que la precondicion se cumplio, con un mensaje de error accionable
// si no.
beforeEach(function () {
    if (! Schema::hasTable('inventories_locations') || ! \Webkul\Inventory\Models\Location::query()->exists()) {
        $this->fail(
            'db_aureuserp_test no esta pre-sembrada. Corre (fuera de cualquier proceso de test, en el mismo orden que apps/aureuserp/.github/workflows/pest_tests.yml): '
            .'php artisan migrate:fresh --force && '
            .'php artisan erp:install --force --admin-name="Test Admin" --admin-email="admin@example.com" --admin-password="Admin123456" && '
            .'php artisan inventories:install --no-interaction'
        );
    }
});

// NOTA (desviacion del brief, ver task-1-report.md): el plan original
// (task-1-brief.md) usaba info@aureuserp.com como capture user, pero
// progress.md (Tarea 0, preflight #145) documenta que el plan fue
// corregido: el usuario ERP real es admin@example.com (el que crea
// erp:install por defecto). info@aureuserp.com no existe en ninguna
// parte del codebase (ni seeders, ni InstallERP). Se usa admin@example.com
// aqui y en el comando para mantener consistencia con esa correccion.
it('resolves company from the capture user and UOM/warehouses by stable keys, idempotently', function () {
    $captureUser = User::where('email', 'admin@example.com')->first();
    expect($captureUser)->not->toBeNull();
    expect($captureUser->default_company_id)->not->toBeNull();

    $this->artisan('analysis:seed-gold-standard-dataset')->assertExitCode(0);
    $this->artisan('analysis:seed-gold-standard-dataset')->assertExitCode(0);

    expect(UOM::where('name', 'Units')->exists())->toBeTrue();

    $bodegaCentral = Warehouse::where('code', 'BODEGA-CENTRAL')->where('company_id', $captureUser->default_company_id)->first();
    expect($bodegaCentral)->not->toBeNull();
    expect($bodegaCentral->name)->toBe('Bodega Central');
    expect($bodegaCentral->lotStockLocation)->not->toBeNull();

    $tienda = Warehouse::where('code', 'TIENDA-STGO')->where('company_id', $captureUser->default_company_id)->first();
    expect($tienda)->not->toBeNull();
    expect($tienda->name)->toBe('Tienda Santiago Centro');

    expect(Warehouse::where('code', 'BODEGA-CENTRAL')->where('company_id', $captureUser->default_company_id)->count())->toBe(1);
    expect(Warehouse::where('code', 'TIENDA-STGO')->where('company_id', $captureUser->default_company_id)->count())->toBe(1);
});

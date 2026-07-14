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

use Webkul\Product\Models\Product;

it('loads exactly the 41 canonical SKUs under the capture company, with guaranteed divergent metadata', function () {
    $captureUser = User::where('email', 'admin@example.com')->first();

    $this->artisan('analysis:seed-gold-standard-dataset')->assertExitCode(0);
    $this->artisan('analysis:seed-gold-standard-dataset')->assertExitCode(0);

    $csvLines = file(base_path('database/data/gold-standard-products-v1.csv'));
    $header = str_getcsv(array_shift($csvLines));
    $expectedSkus = collect($csvLines)
        ->map(fn (string $line) => array_combine($header, str_getcsv($line))['sku'])
        ->sort()
        ->values();

    $actualSkus = Product::where('company_id', $captureUser->default_company_id)
        ->whereIn('reference', $expectedSkus)
        ->pluck('reference')
        ->sort()
        ->values();

    expect($actualSkus)->toEqual($expectedSkus);
    expect($actualSkus)->toHaveCount(41);

    $lenovo = Product::where('reference', 'LENOVO-IDEAPAD3')->where('company_id', $captureUser->default_company_id)->first();
    expect((float) $lenovo->price)->toBe(549990.0);
    expect($lenovo->barcode)->toBeNull();
    expect($lenovo->name)->not->toBe('Notebook Lenovo IdeaPad 3');
    expect($lenovo->name)->toStartWith('[');
    expect($lenovo->category->name)->not->toBe('All');
    expect($lenovo->category->name)->toContain('Computadores');
});

use Webkul\Inventory\Models\ProductQuantity;

it('reconciles exactly the four required heterogeneous quantity cases over the canonical 41 SKUs', function () {
    $captureUser = User::where('email', 'admin@example.com')->first();
    $companyId = $captureUser->default_company_id;

    $this->artisan('analysis:seed-gold-standard-dataset')->assertExitCode(0);
    $this->artisan('analysis:seed-gold-standard-dataset')->assertExitCode(0);

    $bodegaCentral = Warehouse::where('code', 'BODEGA-CENTRAL')->where('company_id', $companyId)->first();
    $tienda = Warehouse::where('code', 'TIENDA-STGO')->where('company_id', $companyId)->first();

    $bySku = fn (string $sku) => Product::where('reference', $sku)->where('company_id', $companyId)->firstOrFail();

    // Caso 1: stock positivo — primer SKU del CSV que no sea ninguno de los casos especiales
    $positive = $bySku('MACBOOK-PRO-M3');
    $row = ProductQuantity::where('product_id', $positive->id)->where('location_id', $bodegaCentral->lot_stock_location_id)->first();
    expect($row)->not->toBeNull();
    expect((float) $row->quantity)->toBeGreaterThan(0);
    expect($row->inventory_quantity_set)->toBeTrue();

    // Caso 2: stock cero == ausencia de fila en ambas ubicaciones
    $zeroCase = $bySku('LENOVO-IDEAPAD3');
    expect(ProductQuantity::where('product_id', $zeroCase->id)->exists())->toBeFalse();

    // Caso 3: multiubicación — positivo en ambas
    $multiLocation = $bySku('MACBOOK-AIR-M2');
    expect((float) ProductQuantity::where('product_id', $multiLocation->id)->where('location_id', $bodegaCentral->lot_stock_location_id)->value('quantity'))->toBeGreaterThan(0);
    expect((float) ProductQuantity::where('product_id', $multiLocation->id)->where('location_id', $tienda->lot_stock_location_id)->value('quantity'))->toBeGreaterThan(0);

    // Caso 4: solo Tienda
    $onlyTienda = $bySku('HP-PAVILION15');
    expect(ProductQuantity::where('product_id', $onlyTienda->id)->where('location_id', $bodegaCentral->lot_stock_location_id)->exists())->toBeFalse();
    expect((float) ProductQuantity::where('product_id', $onlyTienda->id)->where('location_id', $tienda->lot_stock_location_id)->value('quantity'))->toBeGreaterThan(0);

    // Reconciliación: correr 2 veces no duplica ni revive filas eliminadas
    expect(ProductQuantity::where('product_id', $positive->id)->count())->toBe(1);
    expect(ProductQuantity::where('product_id', $zeroCase->id)->count())->toBe(0);

    // Sin efectos colaterales de flujo de inventario
    expect(\Webkul\Inventory\Models\Move::where('company_id', $companyId)->count())->toBe(0);
});

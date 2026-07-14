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
//
// Mitigacion: este archivo corre en su PROPIO proceso Pest, separado del
// resto de la suite (grupo "gold-standard-dataset"), con la base ya
// pre-sembrada por un paso de CI previo — nunca dentro de una transaccion
// de test ni intercalado con otros archivos. Ver
// .github/workflows/pest_tests.yml: primero `erp:install` + `inventories:install`
// como pasos de shell independientes, despues este archivo solo, despues el
// resto de la suite con `--exclude-group=gold-standard-dataset`. NO se llama
// a ensurePluginInstalled()/ensureERPInstalled() aqui. El beforeEach solo
// verifica que esa precondicion externa se cumplio, sin ofrecer un comando
// copiable que pueda ejecutarse contra la base equivocada — la receta segura
// vive unicamente en el workflow de CI.
uses()->group('gold-standard-dataset');

beforeEach(function () {
    if (! Schema::hasTable('inventories_locations') || ! \Webkul\Inventory\Models\Location::query()->exists()) {
        $this->fail(
            'La base de test no esta pre-sembrada (faltan inventories_locations). '
            .'Este test requiere que erp:install + inventories:install ya hayan corrido, fuera de cualquier '
            .'proceso Pest, contra la base dedicada configurada en TEST_BOOTSTRAP_ALLOWED_DATABASES '
            .'(ver .github/workflows/pest_tests.yml para la secuencia exacta). No ejecutes migrate:fresh manualmente.'
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
    // PHP 8.4 deprecates $escape implicito — "" (sin escape) coincide con
    // el fixture real (ver Console/SeedGoldStandardDatasetCommand::readDatasetCsv()).
    $header = str_getcsv(array_shift($csvLines), ',', '"', '');
    $expectedSkus = collect($csvLines)
        ->map(fn (string $line) => array_combine($header, str_getcsv($line, ',', '"', ''))['sku'])
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

    // Caso 1: stock positivo — MACBOOK-PRO-M3 tiene stockOnHand=5 en el CSV.
    // Valor exacto, no solo >0: un bug que sembrara todo en 1 pasaria un
    // check de mero ">0".
    $positive = $bySku('MACBOOK-PRO-M3');
    $row = ProductQuantity::where('product_id', $positive->id)->where('location_id', $bodegaCentral->lot_stock_location_id)->first();
    expect($row)->not->toBeNull();
    expect((float) $row->quantity)->toBe(5.0);
    expect($row->inventory_quantity_set)->toBeTrue();

    // Caso 2: stock cero == ausencia de fila en ambas ubicaciones
    $zeroCase = $bySku('LENOVO-IDEAPAD3');
    expect(ProductQuantity::where('product_id', $zeroCase->id)->exists())->toBeFalse();

    // Caso 3: multiubicación — MACBOOK-AIR-M2 tiene stockOnHand=7; Bodega=7,
    // Tienda=round(7/3)=2 (ver SeedGoldStandardDatasetCommand::reconcileQuantities()).
    $multiLocation = $bySku('MACBOOK-AIR-M2');
    expect((float) ProductQuantity::where('product_id', $multiLocation->id)->where('location_id', $bodegaCentral->lot_stock_location_id)->value('quantity'))->toBe(7.0);
    expect((float) ProductQuantity::where('product_id', $multiLocation->id)->where('location_id', $tienda->lot_stock_location_id)->value('quantity'))->toBe(2.0);

    // Caso 4: solo Tienda — HP-PAVILION15 tiene stockOnHand=9.
    $onlyTienda = $bySku('HP-PAVILION15');
    expect(ProductQuantity::where('product_id', $onlyTienda->id)->where('location_id', $bodegaCentral->lot_stock_location_id)->exists())->toBeFalse();
    expect((float) ProductQuantity::where('product_id', $onlyTienda->id)->where('location_id', $tienda->lot_stock_location_id)->value('quantity'))->toBe(9.0);

    // Reconciliación: correr 2 veces no duplica ni revive filas eliminadas
    expect(ProductQuantity::where('product_id', $positive->id)->count())->toBe(1);
    expect(ProductQuantity::where('product_id', $zeroCase->id)->count())->toBe(0);

    // Sin Moves agregados POR EL COMANDO — comparado contra el conteo previo
    // a correrlo, no asumiendo que la base pre-sembrada parte en cero.
    $movesBefore = \Webkul\Inventory\Models\Move::where('company_id', $companyId)->count();
    $this->artisan('analysis:seed-gold-standard-dataset')->assertExitCode(0);
    expect(\Webkul\Inventory\Models\Move::where('company_id', $companyId)->count())->toBe($movesBefore);
});

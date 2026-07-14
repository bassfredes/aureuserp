<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Webkul\Inventory\Enums\DeliveryStep;
use Webkul\Inventory\Enums\ReceptionStep;
use Webkul\Inventory\Models\Warehouse;
use Webkul\Inventory\Models\ProductQuantity;
use Webkul\Product\Models\Category;
use Webkul\Product\Models\Product;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\UOM;

class SeedGoldStandardDatasetCommand extends Command
{
    protected $signature = 'analysis:seed-gold-standard-dataset {--source= : Ruta alternativa al CSV fuente} {--capture-user-email= : Email del usuario que autenticara la captura HTTP posterior}';

    protected $description = 'Seed idempotente y determinista de 41 productos y stock correlacionables con Vendure, bajo la compania del usuario de captura, para el issue #145';

    // Debe coincidir con ERP_ADMIN_EMAIL en capture-erp-artifacts.ts (Tarea 7):
    // CompanyScope no filtra sin usuario autenticado (consola), pero la captura
    // HTTP si queda scopeada a este usuario — el seed debe crear todo bajo su
    // default_company_id o la captura vera listas vacias.
    private const DEFAULT_CAPTURE_USER_EMAIL = 'admin@example.com';

    public function handle(): int
    {
        $captureUser = $this->resolveCaptureUser();
        $previousUser = Auth::user();

        // Warehouse::create() dispara internamente la creacion de Location,
        // Route y OperationType (ver Warehouse::handleWarehouseCreation()),
        // y esos modelos resuelven creator_id/company_id desde Auth::user()
        // en sus propios boot hooks (p.ej. Route::creating() accede a
        // Auth::user()->id sin null-check) — exactamente como ocurre en
        // produccion via un request autenticado. Sin esto, el comando
        // (que corre por consola, sin usuario autenticado) falla con
        // "Attempt to read property id on null". Se restaura el actor
        // previo al salir para no contaminar estado global en invocaciones
        // programaticas (tests, jobs) donde el proceso no termina aqui.
        Auth::setUser($captureUser);

        try {
            $company = $this->resolveCaptureCompany($captureUser);
            $uom = $this->resolveUom();
            $bodegaCentral = $this->resolveBodegaCentral($company);
            $tienda = $this->resolveTiendaSantiagoCentro($company);

            $sourcePath = $this->option('source') ?? database_path('data/gold-standard-products-v1.csv');
            $rows = $this->readDatasetCsv($sourcePath);

            $products = $this->seedProducts($rows, $company, $uom);
            $this->reconcileQuantities($products, $rows, $bodegaCentral, $tienda, $company);

            $this->info(sprintf('%d productos correlacionables cargados', $products->count()));

            return self::SUCCESS;
        } finally {
            // Auth::setUser() no acepta null (el contrato Guard::setUser()
            // exige Authenticatable) — el caso tipico en CLI es justamente
            // que no habia usuario previo, asi que restaurar con setUser()
            // a secas lanzaria TypeError. logout() limpia el guard de forma
            // segura sin importar si habia un usuario antes o no.
            $previousUser ? Auth::setUser($previousUser) : Auth::logout();
        }
    }

    /**
     * Distribucion fija por SKU (no por indice de iteracion, para que sea
     * estable sin importar el orden del CSV):
     * - LENOVO-IDEAPAD3: sin stock en ninguna ubicacion.
     * - HP-PAVILION15: solo en Tienda Santiago Centro.
     * - MACBOOK-AIR-M2: multiubicacion, positivo en ambas.
     * - resto: positivo solo en Bodega Central.
     */
    private const ZERO_STOCK_SKU = 'LENOVO-IDEAPAD3';

    private const TIENDA_ONLY_SKU = 'HP-PAVILION15';

    private const MULTI_LOCATION_SKU = 'MACBOOK-AIR-M2';

    private function reconcileQuantities(
        \Illuminate\Support\Collection $products,
        array $rows,
        Warehouse $bodegaCentral,
        Warehouse $tienda,
        Company $company,
    ): void {
        $skuToStock = collect($rows)->pluck('stockOnHand', 'sku');
        $productIds = $products->pluck('id');

        // Reconciliacion completa: limpiar todo el estado administrado por
        // este dataset antes de reaplicar la distribucion esperada.
        ProductQuantity::whereIn('product_id', $productIds)
            ->whereIn('location_id', [$bodegaCentral->lot_stock_location_id, $tienda->lot_stock_location_id])
            ->delete();

        foreach ($products as $product) {
            $sku = $product->reference;
            $stockOnHand = max(1.0, (float) ($skuToStock[$sku] ?? 1));

            if ($sku === self::ZERO_STOCK_SKU) {
                continue; // sin filas en ninguna ubicacion
            }

            if ($sku === self::TIENDA_ONLY_SKU) {
                $this->createQuantity($product, $tienda->lot_stock_location_id, $stockOnHand, $company);

                continue;
            }

            $this->createQuantity($product, $bodegaCentral->lot_stock_location_id, $stockOnHand, $company);

            if ($sku === self::MULTI_LOCATION_SKU) {
                $this->createQuantity($product, $tienda->lot_stock_location_id, round($stockOnHand / 3) ?: 1, $company);
            }
        }
    }

    private function createQuantity(Product $product, int $locationId, float $quantity, Company $company): void
    {
        ProductQuantity::create([
            'product_id' => $product->id,
            'location_id' => $locationId,
            'quantity' => $quantity,
            'reserved_quantity' => 0,
            'inventory_quantity_set' => true,
            'company_id' => $company->id,
        ]);
    }

    private function seedProducts(array $rows, Company $company, UOM $uom): \Illuminate\Support\Collection
    {
        return collect($rows)->map(function (array $row) use ($company, $uom) {
            $category = $this->resolveErpCategory($row['facets'] ?? '');

            return Product::updateOrCreate(
                ['reference' => $row['sku'], 'company_id' => $company->id],
                [
                    'type' => 'goods',
                    'name' => $this->buildInternalName($row, $category),
                    'barcode' => null,
                    'price' => (float) $row['price'],
                    'cost' => round((float) $row['price'] * 0.7, 2),
                    'description' => $this->deriveInternalDescription($row['description']),
                    'enable_sales' => true,
                    'enable_purchase' => true,
                    'uom_id' => $uom->id,
                    'uom_po_id' => $uom->id,
                    'category_id' => $category->id,
                ],
            );
        });
    }

    private function buildInternalName(array $row, Category $category): string
    {
        $facets = $this->parseFacets($row['facets'] ?? '');
        $brandCode = Str::upper(Str::substr($facets->get('Marca') ?? 'GEN', 0, 4));
        $categoryCode = Str::upper(Str::substr($category->name, 0, 4));

        // Prefijo "[MARCA·CATEG]" garantiza divergencia por construccion: el
        // nombre de Vendure (columna "name" del CSV) nunca empieza con "[".
        return "[{$brandCode}\u{00B7}{$categoryCode}] {$row['name']}";
    }

    private function deriveInternalDescription(string $vendureDescription): string
    {
        $firstSentence = Str::before($vendureDescription, '. ');

        return "Ficha interna: {$firstSentence}.";
    }

    private function parseFacets(string $facets): \Illuminate\Support\Collection
    {
        return collect(explode('|', $facets))
            ->filter()
            ->mapWithKeys(function (string $pair) {
                [$key, $value] = array_pad(explode(':', $pair, 2), 2, null);

                return [$key => $value];
            });
    }

    /**
     * category_id se deriva estrictamente de la facet "Categoría" — la
     * decision del dataset es que "Subcategoría" aporta contexto semantico
     * (usado en buildInternalName()) pero nunca define la categoria ERP.
     * Fallar explicito ante su ausencia evita que una fila sin "Categoría"
     * cambie de semantica en silencio hacia "Subcategoría".
     */
    private function resolveErpCategory(string $facets): Category
    {
        $categoryName = $this->parseFacets($facets)->get('Categoría');

        if (! $categoryName) {
            throw new \RuntimeException('El dataset #145 requiere la facet "Categoría" en cada fila del CSV.');
        }

        return Category::firstOrCreate(
            ['name' => $categoryName],
            ['full_name' => $categoryName, 'parent_path' => '/'],
        );
    }

    private function resolveCaptureUser(): User
    {
        $email = $this->option('capture-user-email') ?? self::DEFAULT_CAPTURE_USER_EMAIL;
        $user = User::where('email', $email)->first();

        if (! $user || ! $user->default_company_id) {
            throw new \RuntimeException(sprintf(
                'No se encontro el usuario de captura "%s" o no tiene default_company_id. Este comando reutiliza su compania por defecto para que la captura HTTP autenticada (CompanyScope) vea los datos que crea.',
                $email,
            ));
        }

        return $user;
    }

    private function resolveCaptureCompany(User $captureUser): Company
    {
        return $captureUser->defaultCompany ?? Company::findOrFail($captureUser->default_company_id);
    }

    private function resolveUom(): UOM
    {
        $uom = UOM::where('name', 'Units')->first();

        if (! $uom) {
            throw new \RuntimeException('No se encontro la UOM base "Units". Verifica que UOMSeeder haya corrido al instalar el plugin support.');
        }

        return $uom;
    }

    private function resolveBodegaCentral(Company $company): Warehouse
    {
        return Warehouse::firstOrCreate(
            ['code' => 'BODEGA-CENTRAL', 'company_id' => $company->id],
            [
                'name' => 'Bodega Central',
                'reception_steps' => ReceptionStep::ONE_STEP,
                'delivery_steps' => DeliveryStep::ONE_STEP,
            ],
        );
    }

    private function resolveTiendaSantiagoCentro(Company $company): Warehouse
    {
        return Warehouse::firstOrCreate(
            ['code' => 'TIENDA-STGO', 'company_id' => $company->id],
            [
                'name' => 'Tienda Santiago Centro',
                'reception_steps' => ReceptionStep::ONE_STEP,
                'delivery_steps' => DeliveryStep::ONE_STEP,
            ],
        );
    }

    private function readDatasetCsv(string $path): array
    {
        if (! is_readable($path)) {
            throw new \RuntimeException("No se pudo leer el dataset en {$path}. Genera la instantanea (Tarea 1, Paso 1) o pasa --source=/ruta/al/csv.");
        }

        $handle = fopen($path, 'r');
        // PHP 8.4 deprecates dejar $escape implicito en fgetcsv() — se fija
        // explicito ("" = sin escape, el CSV fuente no usa backslash-escaping).
        $header = fgetcsv($handle, null, ',', '"', '');
        $rows = [];

        while (($line = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $rows[] = array_combine($header, $line);
        }

        fclose($handle);

        return $rows;
    }
}

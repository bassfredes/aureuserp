<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Webkul\Inventory\Enums\DeliveryStep;
use Webkul\Inventory\Enums\ReceptionStep;
use Webkul\Inventory\Models\Warehouse;
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

        // Warehouse::create() dispara internamente la creacion de Location,
        // Route y OperationType (ver Warehouse::handleWarehouseCreation()),
        // y esos modelos resuelven creator_id/company_id desde Auth::user()
        // en sus propios boot hooks (p.ej. Route::creating() accede a
        // Auth::user()->id sin null-check) — exactamente como ocurre en
        // produccion via un request autenticado. Sin esto, el comando
        // (que corre por consola, sin usuario autenticado) falla con
        // "Attempt to read property id on null".
        Auth::setUser($captureUser);

        $company = $this->resolveCaptureCompany($captureUser);
        $uom = $this->resolveUom();
        $bodegaCentral = $this->resolveBodegaCentral($company);
        $tienda = $this->resolveTiendaSantiagoCentro($company);

        $sourcePath = $this->option('source') ?? database_path('data/gold-standard-products-v1.csv');
        $rows = $this->readDatasetCsv($sourcePath);

        $this->info(sprintf(
            'Company #%d (%s), UOM #%d, Bodega Central #%d, Tienda Santiago Centro #%d, %d filas leidas',
            $company->id,
            $company->name,
            $uom->id,
            $bodegaCentral->id,
            $tienda->id,
            count($rows),
        ));

        return self::SUCCESS;
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
        $header = fgetcsv($handle);
        $rows = [];

        while (($line = fgetcsv($handle)) !== false) {
            $rows[] = array_combine($header, $line);
        }

        fclose($handle);

        return $rows;
    }
}

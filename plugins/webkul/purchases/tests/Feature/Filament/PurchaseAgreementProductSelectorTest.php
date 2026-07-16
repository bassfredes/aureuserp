<?php

use Filament\Facades\Filament;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Product\Models\Product;
use Webkul\Purchase\Filament\Admin\Clusters\Orders\Resources\PurchaseAgreementResource\Pages\EditPurchaseAgreement;
use Webkul\Purchase\Models\Requisition;
use Webkul\Purchase\Models\RequisitionLine;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('purchases');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function loginAsScopedAgreementEditor(Company $company, array $companiesToAllow): User
{
    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $company->id,
        'is_active'          => true,
    ]));

    $user->forceFill(['resource_permission' => PermissionType::GLOBAL])->saveQuietly();

    foreach ($companiesToAllow as $allowedCompany) {
        $user->allowedCompanies()->attach($allowedCompany->id);
    }

    foreach (['view_any_purchase_purchase::agreement', 'view_purchase_purchase::agreement', 'update_purchase_purchase::agreement'] as $permission) {
        Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $user->givePermissionTo([
        'view_any_purchase_purchase::agreement',
        'view_purchase_purchase::agreement',
        'update_purchase_purchase::agreement',
    ]);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    test()->actingAs($user, 'web');

    return $user->fresh();
}

it('scopes the product selector of an existing agreement line to the agreement\'s own company, not the user\'s default', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $productA = Product::factory()->create(['company_id' => $companyA->id, 'is_configurable' => null]);
    $productB = Product::factory()->create(['company_id' => $companyB->id, 'is_configurable' => null]);

    $requisitionB = Requisition::factory()->create(['company_id' => $companyB->id]);
    RequisitionLine::factory()->create([
        'requisition_id' => $requisitionB->id,
        'company_id'     => $companyB->id,
        'product_id'     => $productB->id,
    ]);

    // Acting user's default company is A, but they are also allowed in B —
    // exactly the A+B scenario the guard/selector must handle correctly.
    loginAsScopedAgreementEditor($companyA, [$companyA, $companyB]);

    // Direct instantiation + mount() (no Livewire::test() full render), same
    // pattern as VendorPriceResourceCompanyScopeTest's ManageVendors checks —
    // rendering the full page pulls in breadcrumb view route() calls that
    // depend on plugin-route registration order at initial app boot, too
    // environment-order-dependent to assert on reliably in this suite.
    $page = app(EditPurchaseAgreement::class);
    $page->mount($requisitionB->getKey());

    $fields = $page->getSchema('form')->getFlatFields(withHidden: true);

    $productFieldKey = collect($fields)->keys()->first(fn ($key) => str_ends_with($key, '.product_id'));

    expect($productFieldKey)->not->toBeNull();

    $results = $fields[$productFieldKey]->getSearchResults('');

    expect(array_keys($results))->toContain($productB->id)
        ->and(array_keys($results))->not->toContain($productA->id);
});

<?php

use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Product\Models\Product;
use Webkul\Purchase\Filament\Admin\Clusters\Configurations\Resources\VendorPriceResource;
use Webkul\Purchase\Filament\Admin\Clusters\Configurations\Resources\VendorPriceResource\Pages\CreateVendorPrice;
use Webkul\Purchase\Filament\Admin\Clusters\Products\Resources\ProductResource\Pages\ManageVendors;
use Webkul\Purchase\Models\ProductSupplier;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('purchases');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

// Only the 'web' guard is needed here: the admin panel's authGuard, unlike
// the API-focused helpers elsewhere in this suite that also juggle sanctum.
function loginAsScopedVendorPriceUser(Company $company, string $permission): User
{
    // is_active isn't set by the base UserFactory and gates
    // canAccessPanel() — without it the admin panel 403s before resource
    // authorization is even reached.
    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $company->id,
        'is_active'          => true,
    ]));

    Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user->givePermissionTo($permission);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    test()->actingAs($user, 'web');

    return $user->fresh();
}

/**
 * ProductSupplier.company_id === Product.company_id is now enforced at the
 * model boot level (D3, aureuserp#137 review): any fixture that sets an
 * explicit company_id must pair it with a product actually in that same
 * company, or ProductSupplier::factory()->create() itself throws.
 */
function filamentProductInCompany(Company $company): int
{
    return Product::factory()->create(['company_id' => $company->id])->id;
}

it('excludes another company\'s vendor price lists from VendorPriceResource\'s query', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    loginAsScopedVendorPriceUser($companyA, 'view_any_purchase_vendor::price');

    $ownRow = ProductSupplier::factory()->create(['product_id' => filamentProductInCompany($companyA), 'company_id' => $companyA->id]);
    $foreignRow = ProductSupplier::factory()->create(['product_id' => filamentProductInCompany($companyB), 'company_id' => $companyB->id]);

    $ids = VendorPriceResource::getEloquentQuery()->pluck('id');

    expect($ids)->toContain($ownRow->id)
        ->and($ids)->not->toContain($foreignRow->id);
});

it('denies a user from company A the update/delete policy abilities on a vendor price list from company B', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = loginAsScopedVendorPriceUser($companyA, 'update_purchase_vendor::price');
    Permission::query()->firstOrCreate(['name' => 'delete_purchase_vendor::price', 'guard_name' => 'web']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user->givePermissionTo('delete_purchase_vendor::price');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // ProductSupplierFactory's $model is the base Webkul\Product\Models\
    // ProductSupplier (products tramo, not yet aliased per-plugin in the
    // factory itself), so ->create() returns that base-class instance —
    // re-fetch through the Purchase alias Gate::denies() must resolve
    // Webkul\Purchase\Policies\ProductSupplierPolicy against.
    $foreignRowId = ProductSupplier::factory()->create(['product_id' => filamentProductInCompany($companyB), 'company_id' => $companyB->id])->id;
    $foreignRow = ProductSupplier::find($foreignRowId);

    expect(Gate::forUser($user->fresh())->denies('update', $foreignRow))->toBeTrue()
        ->and(Gate::forUser($user->fresh())->denies('delete', $foreignRow))->toBeTrue();
});

it('marks the admin form\'s company_id select as required', function () {
    // A full Livewire mount of CreateVendorPrice needs the admin panel's
    // purchases routes registered, which in this suite depends on plugin
    // install state at the app's initial boot (before this test's
    // beforeEach runs) — too environment-order-dependent to assert
    // reliably here. Introspecting the static form definition directly
    // still verifies the actual protection: the required() rule that
    // rejects an empty submission both client- and server-side.
    $company = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $company->id,
        'is_active'           => true,
    ]));
    test()->actingAs($user, 'web');

    $component = VendorPriceResource::form(Schema::make(app(CreateVendorPrice::class)))
        ->getComponentByStatePath('company_id', withHidden: true);

    expect($component)->not->toBeNull()
        ->and($component->isRequired())->toBeTrue();
});

// ── ManageVendors (Product's "sellers" relation manager page) ──────────────
// It reuses VendorPriceResource::form()/table() but NOT ::getEloquentQuery()
// — Filament's relation-manager pages feed their table directly from
// getRelationship(), so the resource-level query scope added for the
// blockers above never applied here. Found in PR #8's second review round.

it('excludes another company\'s vendor price lists from a product\'s ManageVendors listing', function () {
    // ProductSupplier.company_id === Product.company_id is now enforced
    // at the model boot level (D3, aureuserp#137 review): the SAME
    // product can no longer have suppliers in two different companies,
    // so the "foreign" row here is a different product's supplier in
    // company B — ManageVendors, mounted for company A's product, must
    // still only ever surface that product's own (company A) supplier.
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    loginAsScopedVendorPriceUser($companyA, 'view_any_purchase_vendor::price');

    $productA = Product::factory()->create(['is_configurable' => false, 'company_id' => $companyA->id]);
    $productB = Product::factory()->create(['is_configurable' => false, 'company_id' => $companyB->id]);

    $ownRow = ProductSupplier::factory()->create(['product_id' => $productA->id, 'company_id' => $companyA->id]);
    $foreignRow = ProductSupplier::factory()->create(['product_id' => $productB->id, 'company_id' => $companyB->id]);

    $page = app(ManageVendors::class);
    $page->mount($productA->id);

    $ids = $page->getRelationship()->pluck('id');

    expect($ids)->toContain($ownRow->id)
        ->and($ids)->not->toContain($foreignRow->id);
});

it('does not let a company B vendor price list be part of ManageVendors\' bulk-selectable set', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    loginAsScopedVendorPriceUser($companyA, 'view_any_purchase_vendor::price');

    $productA = Product::factory()->create(['is_configurable' => false, 'company_id' => $companyA->id]);
    $productB = Product::factory()->create(['is_configurable' => false, 'company_id' => $companyB->id]);

    $foreignRow = ProductSupplier::factory()->create(['product_id' => $productB->id, 'company_id' => $companyB->id]);

    $page = app(ManageVendors::class);
    $page->mount($productA->id);

    // DeleteBulkAction (shared via VendorPriceResource::table()) resolves
    // its candidate records through this same relationship query — a row
    // never fetched into it can't end up in a bulk selection to begin
    // with. Deleting through it directly proves the row is unreachable
    // via that path, not merely hidden from a rendered list.
    $deleted = $page->getRelationship()->whereKey($foreignRow->id)->delete();

    expect($deleted)->toBe(0);
    $this->assertDatabaseHas('products_product_suppliers', ['id' => $foreignRow->id]);
});

it('cannot create a ProductSupplier through ManageVendors\' relationship for a company that mismatches its owning product', function () {
    // D3 (aureuserp#137 review): the invariant is enforced at
    // ProductSupplier's own model boot level, so it holds regardless of
    // caller. This test exercises the actual Filament relation-manager
    // write path (getRelationship()->create(), what a submitted
    // CreateAction on this page ultimately calls), not just the model in
    // isolation, proving ManageVendors specifically cannot produce the
    // mismatch either.
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    // mount() needs view_any_purchase_vendor::price (same as the read-side
    // tests above) in addition to create, or it 403s before the relation
    // is ever built.
    $user = loginAsScopedVendorPriceUser($companyA, 'view_any_purchase_vendor::price');
    Permission::query()->firstOrCreate(['name' => 'create_purchase_vendor::price', 'guard_name' => 'web']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user->givePermissionTo('create_purchase_vendor::price');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user->allowedCompanies()->attach($companyB->id);

    $product = Product::factory()->create(['is_configurable' => false, 'company_id' => $companyA->id]);

    $page = app(ManageVendors::class);
    $page->mount($product->id);

    expect(fn () => $page->getRelationship()->create([
        'partner_id'  => \Webkul\Partner\Models\Partner::factory()->create()->id,
        'currency_id' => \Webkul\Support\Models\Currency::factory()->create()->id,
        'price'       => 10,
        'company_id'  => $companyB->id,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('products_product_suppliers', ['product_id' => $product->id, 'company_id' => $companyB->id]);
});

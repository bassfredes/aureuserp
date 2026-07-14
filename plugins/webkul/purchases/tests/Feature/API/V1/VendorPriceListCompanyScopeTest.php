<?php

use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Partner\Models\Partner;
use Webkul\Product\Models\Product;
use Webkul\Purchase\Models\ProductSupplier;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

require_once __DIR__.'/../../../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('purchases');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

// Bypasses SecurityHelper on purpose: see PurchaseOrderCompanyScopeTest.php.
function actingAsScopedVendorPriceListUser(Company $company, array $permissions): User
{
    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $company->id,
    ]));

    $user->forceFill([
        'resource_permission' => PermissionType::GLOBAL,
    ])->saveQuietly();

    $records = collect($permissions)->crossJoin(['web', 'sanctum'])
        ->map(fn (array $pair) => ['name' => $pair[0], 'guard_name' => $pair[1]])
        ->all();

    Permission::query()->upsert($records, uniqueBy: ['name', 'guard_name'], update: []);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $user->givePermissionTo(
        Permission::query()->whereIn('name', $permissions)->whereIn('guard_name', ['web', 'sanctum'])->get()
    );

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Auth::guard('web')->login($user);
    Auth::guard('web')->setUser($user);
    Auth::guard('sanctum')->setUser($user);
    Auth::shouldUse('sanctum');
    Sanctum::actingAs($user, ['*']);

    return $user;
}

function scopedVendorPriceListPayload(array $overrides = []): array
{
    $currency = Currency::first() ?? Currency::factory()->create();
    $partner = Partner::factory()->create();
    $product = Product::factory()->create(['is_configurable' => false]);

    return array_replace_recursive([
        'partner_id'  => $partner->id,
        'product_id'  => $product->id,
        'currency_id' => $currency->id,
        'price'       => 100,
    ], $overrides);
}

it('defaults a vendor price list to the acting user default company when company_id is omitted', function () {
    $company = Company::factory()->create();

    $user = actingAsScopedVendorPriceListUser($company, ['create_purchase_vendor::price']);

    $payload = scopedVendorPriceListPayload();
    unset($payload['company_id']); // not present at all, not even null

    $response = $this->postJson(route('admin.api.v1.purchases.vendor-price-lists.store'), $payload)
        ->assertCreated();

    $this->assertDatabaseHas('products_product_suppliers', [
        'id'         => $response->json('data.id'),
        'company_id' => $user->default_company_id,
    ]);
});

it('accepts an explicit, allowed company_id when creating a vendor price list', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = actingAsScopedVendorPriceListUser($companyA, ['create_purchase_vendor::price']);
    $user->allowedCompanies()->attach($companyB->id);

    $response = $this->postJson(
        route('admin.api.v1.purchases.vendor-price-lists.store'),
        scopedVendorPriceListPayload(['company_id' => $companyB->id])
    )->assertCreated();

    $this->assertDatabaseHas('products_product_suppliers', [
        'id'         => $response->json('data.id'),
        'company_id' => $companyB->id,
    ]);
});

it('forbids creating a vendor price list with an explicit, unauthorized company_id', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    actingAsScopedVendorPriceListUser($companyA, ['create_purchase_vendor::price']);

    $this->postJson(
        route('admin.api.v1.purchases.vendor-price-lists.store'),
        scopedVendorPriceListPayload(['company_id' => $companyB->id])
    )->assertForbidden();

    $this->assertDatabaseMissing('products_product_suppliers', ['company_id' => $companyB->id]);
});

it('rejects an explicit null company_id when creating a vendor price list', function () {
    $company = Company::factory()->create();

    actingAsScopedVendorPriceListUser($company, ['create_purchase_vendor::price']);

    $this->postJson(
        route('admin.api.v1.purchases.vendor-price-lists.store'),
        scopedVendorPriceListPayload(['company_id' => null])
    )->assertForbidden();

    $this->assertDatabaseCount('products_product_suppliers', 0);
});

it('forbids changing an existing vendor price list from company A to company B', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    actingAsScopedVendorPriceListUser($companyA, ['create_purchase_vendor::price', 'update_purchase_vendor::price']);

    $vendorPriceList = ProductSupplier::factory()->create(['company_id' => $companyA->id]);

    $this->patchJson(route('admin.api.v1.purchases.vendor-price-lists.update', $vendorPriceList), [
        'company_id' => $companyB->id,
    ])->assertForbidden();

    $this->assertDatabaseHas('products_product_suppliers', [
        'id'         => $vendorPriceList->id,
        'company_id' => $companyA->id,
    ]);
});

it('rejects an explicit null company_id on vendor price list update', function () {
    $companyA = Company::factory()->create();

    actingAsScopedVendorPriceListUser($companyA, ['create_purchase_vendor::price', 'update_purchase_vendor::price']);

    $vendorPriceList = ProductSupplier::factory()->create(['company_id' => $companyA->id]);

    $this->patchJson(route('admin.api.v1.purchases.vendor-price-lists.update', $vendorPriceList), [
        'company_id' => null,
    ])->assertForbidden();

    $this->assertDatabaseHas('products_product_suppliers', [
        'id'         => $vendorPriceList->id,
        'company_id' => $companyA->id,
    ]);
});

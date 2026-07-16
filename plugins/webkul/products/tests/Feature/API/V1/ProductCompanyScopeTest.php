<?php

use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Product\Models\Category;
use Webkul\Product\Models\Packaging;
use Webkul\Product\Models\Product;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\UOM;

require_once __DIR__.'/../../../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('products');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

// Bypasses SecurityHelper on purpose: identical rationale to
// VendorPriceListCompanyScopeTest.php's actingAsScopedVendorPriceListUser() —
// this test controls company membership explicitly and needs the acting
// user's default_company_id to be exactly what each scenario asserts, not
// whatever grantExistingCompanies() would auto-derive.
function actingAsScopedProductUser(Company $company, array $permissions): User
{
    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $company->id,
    ]));

    $user->forceFill(['resource_permission' => PermissionType::GLOBAL])->saveQuietly();

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

function scopedProductPayload(array $overrides = []): array
{
    $category = Category::factory()->create();
    $uom = UOM::factory()->create();

    return array_replace_recursive([
        'type'        => 'goods',
        'name'        => 'Scoped Test Product',
        'price'       => 10,
        'category_id' => $category->id,
        'uom_id'      => $uom->id,
        'uom_po_id'   => $uom->id,
    ], $overrides);
}

function scopedPackagingPayload(array $overrides = []): array
{
    $productAttributes = ['is_configurable' => false];
    if ($companyId = $overrides['company_id'] ?? Auth::user()?->default_company_id) {
        $productAttributes['company_id'] = $companyId;
    }

    $product = Product::factory()->create($productAttributes);

    return array_replace_recursive([
        'name'       => 'Scoped Test Packaging',
        'qty'        => 6,
        'product_id' => $product->id,
    ], $overrides);
}

// ── Product: create ──────────────────────────────────────────────────────────

it('defaults a product to the acting user default company when company_id is omitted', function () {
    $company = Company::factory()->create();

    $user = actingAsScopedProductUser($company, ['create_product_product']);

    $response = $this->postJson(route('admin.api.v1.products.products.store'), scopedProductPayload())
        ->assertCreated();

    $this->assertDatabaseHas('products_products', [
        'id'         => $response->json('data.id'),
        'company_id' => $user->default_company_id,
    ]);
});

it('accepts an explicit, allowed company_id when creating a product', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = actingAsScopedProductUser($companyA, ['create_product_product']);
    $user->allowedCompanies()->attach($companyB->id);

    $response = $this->postJson(
        route('admin.api.v1.products.products.store'),
        scopedProductPayload(['company_id' => $companyB->id])
    )->assertCreated();

    $this->assertDatabaseHas('products_products', [
        'id'         => $response->json('data.id'),
        'company_id' => $companyB->id,
    ]);
});

it('forbids creating a product with an explicit, unauthorized company_id', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    actingAsScopedProductUser($companyA, ['create_product_product']);

    $this->postJson(
        route('admin.api.v1.products.products.store'),
        scopedProductPayload(['company_id' => $companyB->id])
    )->assertForbidden();

    $this->assertDatabaseMissing('products_products', ['company_id' => $companyB->id]);
});

it('rejects an explicit null company_id when creating a product', function () {
    $company = Company::factory()->create();

    actingAsScopedProductUser($company, ['create_product_product']);

    $this->postJson(
        route('admin.api.v1.products.products.store'),
        scopedProductPayload(['company_id' => null])
    )->assertForbidden();
});

// ── Product: update ──────────────────────────────────────────────────────────

it('forbids changing an existing product from company A to company B', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    actingAsScopedProductUser($companyA, ['create_product_product', 'update_product_product']);

    $product = Product::factory()->create(['company_id' => $companyA->id]);

    $this->patchJson(route('admin.api.v1.products.products.update', $product), [
        'company_id' => $companyB->id,
    ])->assertForbidden();

    $this->assertDatabaseHas('products_products', [
        'id'         => $product->id,
        'company_id' => $companyA->id,
    ]);
});

it('rejects an explicit null company_id on product update', function () {
    $company = Company::factory()->create();

    actingAsScopedProductUser($company, ['create_product_product', 'update_product_product']);

    $product = Product::factory()->create(['company_id' => $company->id]);

    $this->patchJson(route('admin.api.v1.products.products.update', $product), [
        'company_id' => null,
    ])->assertForbidden();

    $this->assertDatabaseHas('products_products', [
        'id'         => $product->id,
        'company_id' => $company->id,
    ]);
});

// ── Packaging: create ────────────────────────────────────────────────────────

it('derives a packaging company from its product when company_id is omitted', function () {
    $company = Company::factory()->create();

    actingAsScopedProductUser($company, ['create_product_packaging']);

    $product = Product::factory()->create(['company_id' => $company->id]);

    $payload = scopedPackagingPayload(['product_id' => $product->id]);
    unset($payload['company_id']);

    $response = $this->postJson(route('admin.api.v1.products.packagings.store'), $payload)
        ->assertCreated();

    $this->assertDatabaseHas('products_packagings', [
        'id'         => $response->json('data.id'),
        'company_id' => $company->id,
    ]);
});

it('forbids creating a packaging whose company_id does not match its product company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = actingAsScopedProductUser($companyA, ['create_product_packaging']);
    $user->allowedCompanies()->attach($companyB->id);

    $product = Product::factory()->create(['company_id' => $companyA->id]);

    $this->postJson(route('admin.api.v1.products.packagings.store'), scopedPackagingPayload([
        'product_id' => $product->id,
        'company_id' => $companyB->id,
    ]))->assertForbidden();

    $this->assertDatabaseMissing('products_packagings', ['product_id' => $product->id]);
});

it('rejects an explicit null company_id when creating a packaging', function () {
    $company = Company::factory()->create();

    actingAsScopedProductUser($company, ['create_product_packaging']);

    $product = Product::factory()->create(['company_id' => $company->id]);

    $this->postJson(route('admin.api.v1.products.packagings.store'), scopedPackagingPayload([
        'product_id'  => $product->id,
        'company_id'  => null,
    ]))->assertForbidden();
});

// ── Packaging: update ────────────────────────────────────────────────────────

it('forbids changing an existing packaging from company A to company B', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    actingAsScopedProductUser($companyA, ['create_product_packaging', 'update_product_packaging']);

    $product = Product::factory()->create(['company_id' => $companyA->id]);
    $packaging = Packaging::factory()->create(['company_id' => $companyA->id, 'product_id' => $product->id]);

    $this->patchJson(route('admin.api.v1.products.packagings.update', $packaging), [
        'company_id' => $companyB->id,
    ])->assertForbidden();

    $this->assertDatabaseHas('products_packagings', [
        'id'         => $packaging->id,
        'company_id' => $companyA->id,
    ]);
});

// ── Relation invariant: Packaging.company_id === Product.company_id ─────────

it('forbids a Packaging from company A referencing a Product from company B even for a user allowed in both', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = actingAsScopedProductUser($companyA, ['create_product_packaging']);
    $user->allowedCompanies()->attach($companyB->id);

    $productB = Product::factory()->create(['company_id' => $companyB->id]);

    $this->postJson(route('admin.api.v1.products.packagings.store'), scopedPackagingPayload([
        'product_id' => $productB->id,
        'company_id' => $companyA->id,
    ]))->assertForbidden();

    $this->assertDatabaseMissing('products_packagings', ['product_id' => $productB->id]);
});

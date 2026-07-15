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

    // Product now enforces ProductSupplier.company_id === Product.company_id
    // (D3, aureuserp#137): default the fixture's product to whichever
    // company this vendor price list will actually resolve to — the
    // override's explicit company_id when given, else the acting user's
    // own default — so tests that omit company_id, pass an explicit
    // allowed one, or exercise an early rejection before that check runs
    // aren't tripped up by an unrelated, independently-randomized company.
    $productAttributes = ['is_configurable' => false];
    if ($companyId = $overrides['company_id'] ?? Auth::user()?->default_company_id) {
        $productAttributes['company_id'] = $companyId;
    }

    $product = Product::factory()->create($productAttributes);

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

// ── ProductSupplier gained HasCompanyScope in the products tramo of #137
// (D3): a cross-company row is now hidden by the global scope before it
// ever reaches the controller/policy, so show/update/delete on another
// company's row 404s (ModelNotFoundException from the scoped findOrFail),
// not 403 from Gate::authorize — matching Order/Move's established
// behavior. The index-level whereIn() filter from PR #8 stays in place as
// defense in depth (D3), even though it's now redundant with the scope. ──

it('excludes other companies rows from the vendor price list index', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    actingAsScopedVendorPriceListUser($companyA, ['view_any_purchase_vendor::price']);

    $ownRow = ProductSupplier::factory()->create(['company_id' => $companyA->id]);
    $foreignRow = ProductSupplier::factory()->create(['company_id' => $companyB->id]);

    $ids = collect(
        $this->getJson(route('admin.api.v1.purchases.vendor-price-lists.index'))
            ->assertOk()
            ->json('data')
    )->pluck('id');

    expect($ids)->toContain($ownRow->id)
        ->and($ids)->not->toContain($foreignRow->id);
});

it('forbids a user from company A viewing an existing vendor price list from company B', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    actingAsScopedVendorPriceListUser($companyA, ['view_purchase_vendor::price']);

    $foreignRow = ProductSupplier::factory()->create(['company_id' => $companyB->id]);

    $this->getJson(route('admin.api.v1.purchases.vendor-price-lists.show', $foreignRow))
        ->assertNotFound();
});

it('forbids a user from company A updating a non-company field of a vendor price list from company B', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    actingAsScopedVendorPriceListUser($companyA, ['update_purchase_vendor::price']);

    $foreignRow = ProductSupplier::factory()->create(['company_id' => $companyB->id, 'price' => 100]);

    $this->patchJson(route('admin.api.v1.purchases.vendor-price-lists.update', $foreignRow), [
        'price' => 999,
    ])->assertNotFound();

    $this->assertDatabaseHas('products_product_suppliers', [
        'id'    => $foreignRow->id,
        'price' => 100,
    ]);
});

it('forbids a user from company A deleting a vendor price list from company B', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    actingAsScopedVendorPriceListUser($companyA, ['delete_purchase_vendor::price']);

    $foreignRow = ProductSupplier::factory()->create(['company_id' => $companyB->id]);

    $this->deleteJson(route('admin.api.v1.purchases.vendor-price-lists.destroy', $foreignRow))
        ->assertNotFound();

    $this->assertDatabaseHas('products_product_suppliers', ['id' => $foreignRow->id]);
});

it('returns a controlled 403, not a TypeError, when a companyless user omits company_id on create', function () {
    // Build the payload (Product::factory() creates its own Company as a
    // nested default) BEFORE authenticating: TestCase registers a global
    // Company::created listener that back-fills Auth::user()'s
    // default_company_id from any company created while a user is
    // authenticated. Authenticating first would have this test's own
    // fixture setup silently un-set the exact companyless condition it's
    // trying to exercise.
    $payload = scopedVendorPriceListPayload();
    unset($payload['company_id']);

    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => null,
    ]));

    $user->forceFill(['resource_permission' => PermissionType::GLOBAL])->saveQuietly();

    $permission = 'create_purchase_vendor::price';
    collect(['web', 'sanctum'])->each(
        fn (string $guard) => Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => $guard])
    );
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user->givePermissionTo(Permission::query()->where('name', $permission)->whereIn('guard_name', ['web', 'sanctum'])->get());
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Auth::guard('web')->login($user);
    Auth::guard('web')->setUser($user);
    Auth::guard('sanctum')->setUser($user);
    Auth::shouldUse('sanctum');
    Sanctum::actingAs($user, ['*']);

    $this->postJson(route('admin.api.v1.purchases.vendor-price-lists.store'), $payload)
        ->assertForbidden();

    $this->assertDatabaseCount('products_product_suppliers', 0);
});

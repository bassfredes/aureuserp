<?php

use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Inventory\Enums\LocationType;
use Webkul\Inventory\Enums\ProductTracking;
use Webkul\Inventory\Models\Location;
use Webkul\Inventory\Models\Lot;
use Webkul\Inventory\Models\Package;
use Webkul\Inventory\Models\Product;
use Webkul\Inventory\Models\ProductQuantity;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('inventories');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

function actingAsInventoryQuantityApiUser(array $permissions = []): User
{
    $user = SecurityHelper::authenticateWithPermissions($permissions);

    $user->forceFill([
        'resource_permission' => PermissionType::GLOBAL,
    ])->saveQuietly();

    return $user;
}

function inventoryQuantityRoute(string $action, mixed $quantity = null): string
{
    $name = "admin.api.v1.inventories.quantities.{$action}";

    return $quantity ? route($name, $quantity) : route($name);
}

function inventoryQuantityPayload(Product $product, Location $location, array $overrides = []): array
{
    $draftQuantity = ProductQuantity::factory()->make([
        'product_id'       => $product->id,
        'location_id'      => $location->id,
        'lot_id'           => null,
        'package_id'       => null,
        'counted_quantity' => fake()->randomFloat(2, 1, 100),
    ]);

    return array_replace_recursive([
        'location_id'      => $draftQuantity->location_id,
        'product_id'       => $draftQuantity->product_id,
        'lot_id'           => $draftQuantity->lot_id,
        'package_id'       => $draftQuantity->package_id,
        'counted_quantity' => $draftQuantity->counted_quantity,
        'scheduled_at'     => now()->toDateString(),
    ], $overrides);
}

it('requires authentication to list quantities', function () {
    $this->getJson(inventoryQuantityRoute('index'))
        ->assertUnauthorized();
});

it('requires authentication to show a quantity', function () {
    $quantity = ProductQuantity::factory()->create();

    $this->getJson(inventoryQuantityRoute('show', $quantity))
        ->assertUnauthorized();
});

it('requires authentication to create a quantity', function () {
    $this->postJson(inventoryQuantityRoute('store'), [])
        ->assertUnauthorized();
});

it('forbids listing quantities without permission', function () {
    actingAsInventoryQuantityApiUser();

    $this->getJson(inventoryQuantityRoute('index'))
        ->assertForbidden();
});

it('forbids showing a quantity without permission', function () {
    actingAsInventoryQuantityApiUser();

    $quantity = ProductQuantity::factory()->create();

    $this->getJson(inventoryQuantityRoute('show', $quantity))
        ->assertForbidden();
});

it('lists quantities for authorized users', function () {
    actingAsInventoryQuantityApiUser(['view_any_inventory_quantity']);

    ProductQuantity::factory()->count(3)->create();

    $this->getJson(inventoryQuantityRoute('index'))
        ->assertOk()
        ->assertJsonStructure(['data', 'meta', 'links']);
});

it('filters quantities by product_id', function () {
    actingAsInventoryQuantityApiUser(['view_any_inventory_quantity']);

    $product = Product::factory()->create();

    ProductQuantity::factory()->create(['product_id' => $product->id]);
    ProductQuantity::factory()->count(2)->create();

    $response = $this->getJson(inventoryQuantityRoute('index')."?filter[product_id]={$product->id}&include=product")
        ->assertOk();

    $productIds = collect($response->json('data'))
        ->pluck('product.id')
        ->unique()
        ->filter();

    expect($productIds)->toContain($product->id);
});

it('shows a quantity for authorized users', function () {
    actingAsInventoryQuantityApiUser(['view_any_inventory_quantity']);

    $quantity = ProductQuantity::factory()->create();

    $this->getJson(inventoryQuantityRoute('show', $quantity))
        ->assertOk()
        ->assertJsonPath('data.id', $quantity->id);
});

it('returns 404 for a non-existent quantity', function () {
    actingAsInventoryQuantityApiUser(['view_any_inventory_quantity']);

    $this->getJson(inventoryQuantityRoute('show', 999999))
        ->assertNotFound();
});

it('creates a quantity for a valid variant payload', function () {
    $user = actingAsInventoryQuantityApiUser(['create_inventory_quantity']);

    $parentProduct = Product::factory()->create([
        'is_configurable' => true,
        'is_storable'     => true,
        'tracking'        => ProductTracking::LOT,
        'company_id'      => $user->default_company_id,
    ]);

    $variantProduct = Product::factory()->create([
        'parent_id'       => $parentProduct->id,
        'is_configurable' => false,
        'is_storable'     => true,
        'tracking'        => ProductTracking::LOT,
        'company_id'      => $user->default_company_id,
    ]);

    $location = Location::factory()->create(['type' => LocationType::INTERNAL, 'company_id' => $user->default_company_id]);
    $lot = Lot::factory()->create(['product_id' => $variantProduct->id, 'company_id' => $user->default_company_id]);
    $package = Package::factory()->create(['location_id' => $location->id, 'company_id' => $user->default_company_id]);

    $payload = inventoryQuantityPayload($variantProduct, $location, [
        'lot_id'      => $lot->id,
        'package_id'  => $package->id,
    ]);

    $this->postJson(inventoryQuantityRoute('store'), $payload)
        ->assertCreated()
        ->assertJsonPath('message', 'Quantity created successfully.');
});

it('rejects configurable products and requires variant products', function () {
    actingAsInventoryQuantityApiUser(['create_inventory_quantity']);

    $configurableProduct = Product::factory()->create([
        'is_configurable' => true,
        'is_storable'     => true,
        'tracking'        => ProductTracking::QTY,
    ]);

    $location = Location::factory()->create(['type' => LocationType::INTERNAL]);

    $this->postJson(inventoryQuantityRoute('store'), inventoryQuantityPayload($configurableProduct, $location))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['product_id']);
});

it('requires lot_id for tracked products', function () {
    actingAsInventoryQuantityApiUser(['create_inventory_quantity']);

    $trackedProduct = Product::factory()->create([
        'is_configurable' => false,
        'is_storable'     => true,
        'tracking'        => ProductTracking::LOT,
    ]);

    $location = Location::factory()->create(['type' => LocationType::INTERNAL]);

    $this->postJson(inventoryQuantityRoute('store'), inventoryQuantityPayload($trackedProduct, $location, [
        'lot_id' => null,
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['lot_id']);
});

it('rejects lots that do not belong to the selected product', function () {
    actingAsInventoryQuantityApiUser(['create_inventory_quantity']);

    $product = Product::factory()->create([
        'is_configurable' => false,
        'is_storable'     => true,
        'tracking'        => ProductTracking::LOT,
    ]);

    $otherProduct = Product::factory()->create([
        'is_configurable' => false,
        'is_storable'     => true,
        'tracking'        => ProductTracking::LOT,
    ]);

    $location = Location::factory()->create(['type' => LocationType::INTERNAL]);
    $otherProductLot = Lot::factory()->create(['product_id' => $otherProduct->id]);

    $this->postJson(inventoryQuantityRoute('store'), inventoryQuantityPayload($product, $location, [
        'lot_id' => $otherProductLot->id,
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['lot_id']);
});

it('rejects packages that do not belong to the selected location', function () {
    actingAsInventoryQuantityApiUser(['create_inventory_quantity']);

    $product = Product::factory()->create([
        'is_configurable' => false,
        'is_storable'     => true,
        'tracking'        => ProductTracking::QTY,
    ]);

    $sourceLocation = Location::factory()->create(['type' => LocationType::INTERNAL]);
    $otherLocation = Location::factory()->create(['type' => LocationType::INTERNAL]);
    $otherLocationPackage = Package::factory()->create(['location_id' => $otherLocation->id]);

    $this->postJson(inventoryQuantityRoute('store'), inventoryQuantityPayload($product, $sourceLocation, [
        'package_id' => $otherLocationPackage->id,
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['package_id']);
});

it('rejects counted_quantity greater than one for serial tracked products', function () {
    actingAsInventoryQuantityApiUser(['create_inventory_quantity']);

    $serialProduct = Product::factory()->create([
        'is_configurable' => false,
        'is_storable'     => true,
        'tracking'        => ProductTracking::SERIAL,
    ]);

    $location = Location::factory()->create(['type' => LocationType::INTERNAL]);
    $lot = Lot::factory()->create(['product_id' => $serialProduct->id]);

    $this->postJson(inventoryQuantityRoute('store'), inventoryQuantityPayload($serialProduct, $location, [
        'lot_id'           => $lot->id,
        'counted_quantity' => 2,
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['counted_quantity']);
});

// ── Company-scope write-path guard ──────────────────────────────────────────────
// Bypass actingAsInventoryQuantityApiUser/SecurityHelper on purpose — same
// pattern as LocationTest.php/RouteTest.php/WarehouseTest.php.

it('forbids creating a quantity for a product belonging to another company, even when company_id is omitted', function () {
    $companyB = Company::factory()->create();
    $companyA = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $companyA->id,
    ]));
    $user->forceFill(['resource_permission' => PermissionType::GLOBAL])->saveQuietly();
    // Un unico guard: Webkul\Security\Models\User::$guard_name es 'web' --
    // autorizacion es agnostica de si la request se autentico via sesion o
    // via token Sanctum. Raw upsert + re-query (not Permission::
    // findOrCreate()) to avoid the registrar's stale-cache duplicate-row
    // bug: findOrCreate() can create a second Permission row with a
    // different id when the cache doesn't see rows inserted via upsert()
    // elsewhere, and givePermissionTo() then attaches an id
    // Gate::authorize() never matches.
    Permission::query()->upsert(
        [['name' => 'create_inventory_quantity', 'guard_name' => 'web']],
        uniqueBy: ['name', 'guard_name'],
        update: []
    );
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user->givePermissionTo(
        Permission::query()->where('name', 'create_inventory_quantity')->where('guard_name', 'web')->get()
    );
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    // The API route group uses auth:sanctum middleware, so plain
    // test()->actingAs($user) (which only authenticates the default 'web'
    // guard) leaves the sanctum guard unauthenticated for the real HTTP
    // request — auth:sanctum then rejects the request before Gate::
    // authorize() is ever reached, which is indistinguishable from a real
    // company-scope denial in the response (bootstrap/app.php renders
    // every AuthorizationException as the same generic 403 message on API
    // requests, by design). Without the full guard chain this test would
    // "pass" without ever reaching the company-scope check. Permission
    // resolution itself is guard-agnostic (User::$guard_name = 'web'), but
    // authentication still requires both guards set up here. Same guard
    // chain as SecurityHelper::authenticateWithPermissions().
    Auth::guard('web')->login($user);
    Auth::guard('web')->setUser($user);
    Auth::guard('sanctum')->setUser($user);
    Auth::shouldUse('sanctum');
    Sanctum::actingAs($user, ['*']);

    // Product isn't part of this rollout (out of scope, see ADR 0007), so
    // it's visible to any authenticated user regardless of its own
    // company_id — the request never even mentions companyB explicitly.
    $productB = Product::factory()->create([
        'is_configurable' => false,
        'is_storable'     => true,
        'tracking'        => ProductTracking::QTY,
        'company_id'      => $companyB->id,
    ]);

    $location = Location::factory()->create(['company_id' => $companyA->id, 'type' => LocationType::INTERNAL]);

    $this->postJson(inventoryQuantityRoute('store'), inventoryQuantityPayload($productB, $location))
        ->assertForbidden();

    $this->assertDatabaseMissing('inventories_product_quantities', [
        'product_id' => $productB->id,
    ]);
});

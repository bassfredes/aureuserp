<?php

use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Inventory\Models\Route;
use Webkul\Inventory\Models\Warehouse;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../../../support/tests/Helpers/TestBootstrapHelper.php';

const INVENTORY_ROUTE_JSON_STRUCTURE = [
    'id',
    'name',
];

const INVENTORY_ROUTE_REQUIRED_FIELDS = [
    'name',
];

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('inventories');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

function actingAsInventoryRouteApiUser(array $permissions = []): User
{
    $user = SecurityHelper::authenticateWithPermissions($permissions);

    $user->forceFill([
        'resource_permission' => PermissionType::GLOBAL,
    ])->saveQuietly();

    return $user;
}

function inventoryRouteRoute(string $action, mixed $route = null): string
{
    $name = "admin.api.v1.inventories.routes.{$action}";

    return $route ? route($name, $route) : route($name);
}

function inventoryRoutePayload(array $overrides = []): array
{
    return array_replace_recursive([
        'name'                => 'Test Route',
        'product_selectable'  => false,
    ], $overrides);
}

// ── Authentication ────────────────────────────────────────────────────────────

it('requires authentication to list routes', function () {
    $this->getJson(inventoryRouteRoute('index'))
        ->assertUnauthorized();
});

it('requires authentication to create a route', function () {
    $this->postJson(inventoryRouteRoute('store'), [])
        ->assertUnauthorized();
});

it('requires authentication to show a route', function () {
    // No acting user in this test (it proves the 401) — Route's factory
    // can cascade into creating a Warehouse, whose creation reads the
    // shared Vendors/Customers Location rows (company_or_shared), so this
    // needs an explicit system context instead of the no-user implicit
    // bypass (ADR 0007).
    $route = CompanyContext::runForAllCompanies(reason: 'test fixture setup', caller: __FILE__, callback: fn () => Route::factory()->create());

    $this->getJson(inventoryRouteRoute('show', $route))
        ->assertUnauthorized();
});

it('requires authentication to update a route', function () {
    $route = CompanyContext::runForAllCompanies(reason: 'test fixture setup', caller: __FILE__, callback: fn () => Route::factory()->create());

    $this->patchJson(inventoryRouteRoute('update', $route), [])
        ->assertUnauthorized();
});

it('requires authentication to delete a route', function () {
    $route = CompanyContext::runForAllCompanies(reason: 'test fixture setup', caller: __FILE__, callback: fn () => Route::factory()->create());

    $this->deleteJson(inventoryRouteRoute('destroy', $route))
        ->assertUnauthorized();
});

it('requires authentication to restore a route', function () {
    $route = CompanyContext::runForAllCompanies(reason: 'test fixture setup', caller: __FILE__, callback: function () {
        $route = Route::factory()->create();
        $route->delete();

        return $route;
    });

    $this->postJson(inventoryRouteRoute('restore', $route))
        ->assertUnauthorized();
});

it('requires authentication to force-delete a route', function () {
    $route = CompanyContext::runForAllCompanies(reason: 'test fixture setup', caller: __FILE__, callback: function () {
        $route = Route::factory()->create();
        $route->delete();

        return $route;
    });

    $this->deleteJson(inventoryRouteRoute('force-destroy', $route))
        ->assertUnauthorized();
});

// ── Authorization ─────────────────────────────────────────────────────────────

it('forbids listing routes without permission', function () {
    actingAsInventoryRouteApiUser();

    $this->getJson(inventoryRouteRoute('index'))
        ->assertForbidden();
});

it('forbids creating a route without permission', function () {
    actingAsInventoryRouteApiUser();

    $this->postJson(inventoryRouteRoute('store'), inventoryRoutePayload())
        ->assertForbidden();
});

it('forbids showing a route without permission', function () {
    actingAsInventoryRouteApiUser();

    $route = Route::factory()->create();

    $this->getJson(inventoryRouteRoute('show', $route))
        ->assertForbidden();
});

it('forbids updating a route without permission', function () {
    actingAsInventoryRouteApiUser();

    $route = Route::factory()->create();

    $this->patchJson(inventoryRouteRoute('update', $route), [])
        ->assertForbidden();
});

it('forbids deleting a route without permission', function () {
    actingAsInventoryRouteApiUser();

    $route = Route::factory()->create();

    $this->deleteJson(inventoryRouteRoute('destroy', $route))
        ->assertForbidden();
});

it('forbids restoring a route without permission', function () {
    actingAsInventoryRouteApiUser();

    $route = Route::factory()->create();
    $route->delete();

    $this->postJson(inventoryRouteRoute('restore', $route))
        ->assertForbidden();
});

it('forbids force-deleting a route without permission', function () {
    actingAsInventoryRouteApiUser();

    $route = Route::factory()->create();
    $route->delete();

    $this->deleteJson(inventoryRouteRoute('force-destroy', $route))
        ->assertForbidden();
});

// ── Index ─────────────────────────────────────────────────────────────────────

it('lists routes for authorized users', function () {
    actingAsInventoryRouteApiUser(['view_any_inventory_route']);

    Route::factory()->count(3)->create();

    $this->getJson(inventoryRouteRoute('index'))
        ->assertOk()
        ->assertJsonStructure(['data', 'meta', 'links']);
});

it('filters routes by name', function () {
    actingAsInventoryRouteApiUser(['view_any_inventory_route']);

    $route = Route::factory()->create(['name' => 'UniqueRouteXYZ']);
    Route::factory()->count(2)->create();

    $response = $this->getJson(inventoryRouteRoute('index').'?filter[name]=UniqueRouteXYZ')
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($route->id);
});

it('filters routes by product_selectable', function () {
    actingAsInventoryRouteApiUser(['view_any_inventory_route']);

    $selectable = Route::factory()->create(['product_selectable' => true]);
    Route::factory()->create(['product_selectable' => false]);

    $response = $this->getJson(inventoryRouteRoute('index').'?filter[product_selectable]=1')
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($selectable->id);
});

it('excludes soft-deleted routes from default listing', function () {
    actingAsInventoryRouteApiUser(['view_any_inventory_route']);

    $active = Route::factory()->create();
    $deleted = Route::factory()->create();
    $deleted->delete();

    $response = $this->getJson(inventoryRouteRoute('index'))
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($active->id)
        ->and($ids)->not->toContain($deleted->id);
});

// ── Store ─────────────────────────────────────────────────────────────────────

it('creates a route', function () {
    actingAsInventoryRouteApiUser(['create_inventory_route']);

    $payload = inventoryRoutePayload();

    $this->postJson(inventoryRouteRoute('store'), $payload)
        ->assertCreated()
        ->assertJsonPath('message', 'Route created successfully.')
        ->assertJsonPath('data.name', $payload['name'])
        ->assertJsonStructure(['data' => INVENTORY_ROUTE_JSON_STRUCTURE]);

    $this->assertDatabaseHas('inventories_routes', [
        'name' => $payload['name'],
    ]);
});

it('validates required fields when creating a route', function (string $field) {
    actingAsInventoryRouteApiUser(['create_inventory_route']);

    $payload = inventoryRoutePayload();
    unset($payload[$field]);

    $this->postJson(inventoryRouteRoute('store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with(INVENTORY_ROUTE_REQUIRED_FIELDS);

// ── Show ──────────────────────────────────────────────────────────────────────

it('shows a route for authorized users', function () {
    actingAsInventoryRouteApiUser(['view_inventory_route']);

    $route = Route::factory()->create();

    $this->getJson(inventoryRouteRoute('show', $route))
        ->assertOk()
        ->assertJsonPath('data.id', $route->id)
        ->assertJsonPath('data.name', $route->name)
        ->assertJsonStructure(['data' => INVENTORY_ROUTE_JSON_STRUCTURE]);
});

it('returns 404 for a non-existent route', function () {
    actingAsInventoryRouteApiUser(['view_inventory_route']);

    $this->getJson(inventoryRouteRoute('show', 999999))
        ->assertNotFound();
});

// ── Update ────────────────────────────────────────────────────────────────────

it('updates a route', function () {
    actingAsInventoryRouteApiUser(['update_inventory_route']);

    $route = Route::factory()->create();

    $this->patchJson(inventoryRouteRoute('update', $route), ['name' => 'Updated Route'])
        ->assertOk()
        ->assertJsonPath('message', 'Route updated successfully.')
        ->assertJsonPath('data.name', 'Updated Route');

    $this->assertDatabaseHas('inventories_routes', [
        'id'   => $route->id,
        'name' => 'Updated Route',
    ]);
});

it('returns 404 when updating a non-existent route', function () {
    actingAsInventoryRouteApiUser(['update_inventory_route']);

    $this->patchJson(inventoryRouteRoute('update', 999999), ['name' => 'X'])
        ->assertNotFound();
});

// ── Destroy ───────────────────────────────────────────────────────────────────

it('soft deletes a route', function () {
    actingAsInventoryRouteApiUser(['delete_inventory_route']);

    $route = Route::factory()->create();

    $this->deleteJson(inventoryRouteRoute('destroy', $route))
        ->assertOk()
        ->assertJsonPath('message', 'Route deleted successfully.');

    $this->assertSoftDeleted('inventories_routes', ['id' => $route->id]);
});

it('returns 404 when deleting a non-existent route', function () {
    actingAsInventoryRouteApiUser(['delete_inventory_route']);

    $this->deleteJson(inventoryRouteRoute('destroy', 999999))
        ->assertNotFound();
});

// ── Restore ───────────────────────────────────────────────────────────────────

it('restores a soft-deleted route', function () {
    actingAsInventoryRouteApiUser(['restore_inventory_route']);

    $route = Route::factory()->create();
    $route->delete();

    $this->postJson(inventoryRouteRoute('restore', $route))
        ->assertOk()
        ->assertJsonPath('message', 'Route restored successfully.');

    $this->assertDatabaseHas('inventories_routes', [
        'id'         => $route->id,
        'deleted_at' => null,
    ]);
});

it('returns 404 when restoring a non-existent route', function () {
    actingAsInventoryRouteApiUser(['restore_inventory_route']);

    $this->postJson(inventoryRouteRoute('restore', 999999))
        ->assertNotFound();
});

// ── Force Delete ──────────────────────────────────────────────────────────────

it('permanently deletes a route', function () {
    actingAsInventoryRouteApiUser(['force_delete_inventory_route']);

    $route = Route::factory()->create();
    $route->delete();

    $this->deleteJson(inventoryRouteRoute('force-destroy', $route))
        ->assertOk()
        ->assertJsonPath('message', 'Route permanently deleted successfully.');

    $this->assertDatabaseMissing('inventories_routes', ['id' => $route->id]);
});

it('returns 404 when force-deleting a non-existent route', function () {
    actingAsInventoryRouteApiUser(['force_delete_inventory_route']);

    $this->deleteJson(inventoryRouteRoute('force-destroy', 999999))
        ->assertNotFound();
});

// ── Company-scope write-path guards ─────────────────────────────────────────────
// Bypass actingAsInventoryRouteApiUser/SecurityHelper on purpose: that helper
// grants the acting user access to every company that already exists at
// authentication time, which would silently defeat these cross-company
// tests — same pattern as LocationTest.php.

function actingAsScopedRouteUser(Company $company, array $permissions): User
{
    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $company->id,
    ]));

    $user->forceFill([
        'resource_permission' => PermissionType::GLOBAL,
    ])->saveQuietly();

    // Un unico guard: Webkul\Security\Models\User::$guard_name es 'web' --
    // autorizacion es agnostica de si la request se autentico via sesion o
    // via token Sanctum. Raw upsert + re-query (not Permission::
    // findOrCreate()) to avoid the registrar's stale-cache duplicate-row
    // bug: findOrCreate() can create a second Permission row with a
    // different id when the cache doesn't see rows inserted via upsert()
    // elsewhere, and givePermissionTo() then attaches an id
    // Gate::authorize() never matches.
    $records = collect($permissions)
        ->map(fn (string $name) => ['name' => $name, 'guard_name' => 'web'])
        ->all();

    Permission::query()->upsert($records, uniqueBy: ['name', 'guard_name'], update: []);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $user->givePermissionTo(
        Permission::query()->whereIn('name', $permissions)->where('guard_name', 'web')->get()
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

    return $user;
}

it('forbids a user from company A creating a route in company B', function () {
    $companyB = Company::factory()->create();
    $companyA = Company::factory()->create();

    actingAsScopedRouteUser($companyA, ['create_inventory_route']);

    $this->postJson(inventoryRouteRoute('store'), [
        'name'       => 'Cross Company Route',
        'company_id' => $companyB->id,
    ])->assertForbidden();

    $this->assertDatabaseMissing('inventories_routes', [
        'name'       => 'Cross Company Route',
        'company_id' => $companyB->id,
    ]);
});

it('forbids a user authorized in A+B from associating a route of A with a warehouse of B', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $warehouseB = CompanyContext::runForCompany($companyB->id, reason: 'test fixture setup', caller: __FILE__, callback: fn () => Warehouse::factory()->create(['company_id' => $companyB->id]));

    $user = actingAsScopedRouteUser($companyA, ['create_inventory_route']);
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);

    $this->postJson(inventoryRouteRoute('store'), [
        'name'        => 'Route A linked to Warehouse B',
        'company_id'  => $companyA->id,
        'warehouses'  => [$warehouseB->id],
    ])->assertForbidden();

    $this->assertDatabaseMissing('inventories_routes', [
        'name'       => 'Route A linked to Warehouse B',
        'company_id' => $companyA->id,
    ]);
});

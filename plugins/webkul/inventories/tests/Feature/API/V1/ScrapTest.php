<?php

use Webkul\Inventory\Models\Location;
use Webkul\Inventory\Models\Product;
use Webkul\Inventory\Models\Scrap;
use Webkul\Inventory\Models\Warehouse;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('inventories');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

function actingAsInventoryScrapApiUser(array $permissions = []): User
{
    $user = SecurityHelper::authenticateWithPermissions($permissions);

    $user->forceFill([
        'resource_permission' => PermissionType::GLOBAL,
    ])->saveQuietly();

    return $user;
}

function scrapRoute(string $action, mixed $scrap = null): string
{
    $name = "admin.api.v1.inventories.scraps.{$action}";

    return $scrap ? route($name, $scrap) : route($name);
}

function scrapPayload(array $overrides = []): array
{
    $sourceLocation = Location::factory()->internal()->create();
    $destinationLocation = Location::factory()->scrap()->create(['company_id' => $sourceLocation->company_id]);
    // Product.company_id must match the effective scrap company (D5b,
    // aureuserp#137).
    $product = Product::factory()->create(['is_storable' => true, 'company_id' => $sourceLocation->company_id]);

    return array_replace_recursive([
        'qty'                     => 1,
        'product_id'              => $product->id,
        'uom_id'                  => $product->uom_id,
        'source_location_id'      => $sourceLocation->id,
        'destination_location_id' => $destinationLocation->id,
        'company_id'              => $sourceLocation->company_id,
    ], $overrides);
}

it('requires authentication to list scraps', function () {
    $this->getJson(scrapRoute('index'))
        ->assertUnauthorized();
});

it('requires authentication to create scraps', function () {
    $this->postJson(scrapRoute('store'), [])
        ->assertUnauthorized();
});

it('requires authentication to show scraps', function () {
    $scrap = Scrap::factory()->create();

    $this->getJson(scrapRoute('show', $scrap->id))
        ->assertUnauthorized();
});

it('requires authentication to validate scraps', function () {
    $scrap = Scrap::factory()->create();

    $this->postJson(scrapRoute('validate', $scrap->id))
        ->assertUnauthorized();
});

it('forbids listing scraps without permission', function () {
    actingAsInventoryScrapApiUser();

    $this->getJson(scrapRoute('index'))
        ->assertForbidden();
});

it('forbids creating scraps without permission', function () {
    actingAsInventoryScrapApiUser();

    $this->postJson(scrapRoute('store'), scrapPayload())
        ->assertForbidden();
});

it('forbids showing scraps without permission', function () {
    actingAsInventoryScrapApiUser();

    $scrap = Scrap::factory()->create();

    $this->getJson(scrapRoute('show', $scrap->id))
        ->assertForbidden();
});

it('forbids updating scraps without permission', function () {
    actingAsInventoryScrapApiUser();

    $scrap = Scrap::factory()->create();

    $this->patchJson(scrapRoute('update', $scrap->id), ['qty' => 2])
        ->assertForbidden();
});

it('forbids deleting scraps without permission', function () {
    actingAsInventoryScrapApiUser();

    $scrap = Scrap::factory()->create();

    $this->deleteJson(scrapRoute('destroy', $scrap->id))
        ->assertForbidden();
});

it('forbids validating scraps without permission', function () {
    actingAsInventoryScrapApiUser();

    $scrap = Scrap::factory()->create();

    $this->postJson(scrapRoute('validate', $scrap->id))
        ->assertForbidden();
});

it('lists scraps for authorized users', function () {
    actingAsInventoryScrapApiUser(['view_any_inventory_scrap']);

    $scrap = Scrap::factory()->create();

    $response = $this->getJson(scrapRoute('index'))
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($scrap->id);
});

it('creates a scrap with valid payload', function () {
    actingAsInventoryScrapApiUser(['create_inventory_scrap']);

    $payload = scrapPayload();

    $response = $this->postJson(scrapRoute('store'), $payload)
        ->assertCreated()
        ->assertJsonPath('message', 'Scrap created successfully.');

    expect(Scrap::query()->whereKey($response->json('data.id'))->exists())->toBeTrue();
});

it('validates required qty when creating scraps', function () {
    actingAsInventoryScrapApiUser(['create_inventory_scrap']);

    $payload = scrapPayload();
    unset($payload['qty']);

    $this->postJson(scrapRoute('store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['qty']);
});

it('shows a scrap for authorized users', function () {
    actingAsInventoryScrapApiUser(['view_inventory_scrap']);

    $scrap = Scrap::factory()->create();

    $this->getJson(scrapRoute('show', $scrap->id))
        ->assertOk()
        ->assertJsonPath('data.id', $scrap->id);
});

it('returns 404 for a non-existent scrap', function () {
    actingAsInventoryScrapApiUser(['view_inventory_scrap']);

    $this->getJson(scrapRoute('show', 999999))
        ->assertNotFound();
});

it('rejects validating a done scrap', function () {
    actingAsInventoryScrapApiUser(['update_inventory_scrap']);

    $scrap = Scrap::factory()->done()->create();

    $this->postJson(scrapRoute('validate', $scrap->id))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Only draft scraps can be validated.');
});

it('rejects updating a done scrap', function () {
    actingAsInventoryScrapApiUser(['update_inventory_scrap']);

    $scrap = Scrap::factory()->done()->create();

    $this->patchJson(scrapRoute('update', $scrap->id), ['qty' => 2])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Done scraps cannot be updated.');
});

it('updates a draft scrap', function () {
    actingAsInventoryScrapApiUser(['update_inventory_scrap']);

    $scrap = Scrap::factory()->create();

    $this->patchJson(scrapRoute('update', $scrap->id), [
        'qty'        => 3,
        'product_id' => $scrap->product_id,
    ])
        ->assertOk()
        ->assertJsonPath('message', 'Scrap updated successfully.');
});

it('rejects deleting a done scrap', function () {
    actingAsInventoryScrapApiUser(['delete_inventory_scrap']);

    $scrap = Scrap::factory()->done()->create();

    $this->deleteJson(scrapRoute('destroy', $scrap->id))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Done scraps cannot be deleted.');
});

it('deletes a draft scrap', function () {
    actingAsInventoryScrapApiUser(['delete_inventory_scrap']);

    $scrap = Scrap::factory()->create();

    $this->deleteJson(scrapRoute('destroy', $scrap->id))
        ->assertOk()
        ->assertJsonPath('message', 'Scrap deleted successfully.');
});

// ── Company-scope write-path guard ──────────────────────────────────────────────
// Uses actingAsInventoryScrapApiUser/SecurityHelper's own auth chain (proven
// reliable elsewhere in this file) instead of hand-building one — building
// the guard/permission/cache setup manually turned out to be too easy to
// get subtly wrong. Company B is created AFTER authentication so
// TestCase's own Company::created listener grants the acting user access
// to it automatically, same pattern as "auth then create company" tests
// elsewhere in this suite.

it('defaults source/destination location from the effective company, not any company the A+B user can see', function () {
    $user = actingAsInventoryScrapApiUser(['create_inventory_scrap']);

    $companyA = Company::find($user->default_company_id) ?? Company::factory()->create();

    // Company A's warehouse is created first, so an unscoped-to-effective-
    // company "first()" lookup would pick it by default — the bug this
    // test guards against.
    $warehouseA = Warehouse::factory()->create(['company_id' => $companyA->id]);

    $companyB = Company::factory()->create();
    $warehouseB = Warehouse::factory()->create(['company_id' => $companyB->id]);
    $scrapLocationB = Location::factory()->create(['company_id' => $companyB->id, 'is_scrap' => true]);
    $user->allowedCompanies()->attach($companyB->id);

    // Product.company_id must match the effective scrap company (D5b,
    // aureuserp#137).
    $product = Product::factory()->create(['is_storable' => true, 'company_id' => $companyB->id]);

    $response = $this->postJson(scrapRoute('store'), [
        'qty'         => 1,
        'product_id'  => $product->id,
        'uom_id'      => $product->uom_id,
        'company_id'  => $companyB->id,
        // source_location_id / destination_location_id omitted on purpose.
    ])->assertCreated();

    $scrap = Scrap::query()->findOrFail($response->json('data.id'));

    expect($scrap->company_id)->toBe($companyB->id)
        ->and($scrap->source_location_id)->toBe($warehouseB->lot_stock_location_id)
        ->and($scrap->source_location_id)->not->toBe($warehouseA->lot_stock_location_id)
        ->and($scrap->destination_location_id)->toBe($scrapLocationB->id);
});

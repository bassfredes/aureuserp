<?php

use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Inventory\Enums\OperationState;
use Webkul\Inventory\Models\Delivery;
use Webkul\Inventory\Models\InternalTransfer;
use Webkul\Inventory\Models\Location;
use Webkul\Inventory\Models\Operation;
use Webkul\Inventory\Models\OperationType;
use Webkul\Inventory\Models\Product;
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

function actingAsInventoryDeliveryApiUser(array $permissions = []): User
{
    $user = SecurityHelper::authenticateWithPermissions($permissions);

    $user->forceFill([
        'resource_permission' => PermissionType::GLOBAL,
    ])->saveQuietly();

    return $user;
}

function deliveryRoute(string $action, mixed $delivery = null): string
{
    $name = "admin.api.v1.inventories.deliveries.{$action}";

    return $delivery ? route($name, $delivery) : route($name);
}

function deliveryPayload(array $overrides = []): array
{
    $product = Product::factory()->create();
    $operationType = OperationType::factory()->delivery()->create();

    return array_replace_recursive([
        'operation_type_id' => $operationType->id,
        'moves'             => [[
            'product_id'      => $product->id,
            'product_uom_qty' => 1,
            'uom_id'          => $product->uom_id,
        ]],
    ], $overrides);
}

function createDeliveryRecord(array $overrides = []): Delivery
{
    $operation = Operation::factory()->delivery()->create($overrides);

    return Delivery::query()->findOrFail($operation->id);
}

it('requires authentication to list deliveries', function () {
    $this->getJson(deliveryRoute('index'))
        ->assertUnauthorized();
});

it('forbids creating a delivery without permission', function () {
    actingAsInventoryDeliveryApiUser();

    $this->postJson(deliveryRoute('store'), deliveryPayload())
        ->assertForbidden();
});

it('forbids showing a delivery without permission', function () {
    actingAsInventoryDeliveryApiUser();

    $delivery = createDeliveryRecord();

    $this->getJson(deliveryRoute('show', $delivery->id))
        ->assertForbidden();
});

it('forbids listing deliveries without permission', function () {
    actingAsInventoryDeliveryApiUser();

    $this->getJson(deliveryRoute('index'))
        ->assertForbidden();
});

it('lists deliveries for authorized users', function () {
    actingAsInventoryDeliveryApiUser(['view_any_inventory_delivery']);

    $delivery = createDeliveryRecord();

    $response = $this->getJson(deliveryRoute('index'))
        ->assertOk();

    expect(collect($response->json('data'))->pluck('id'))->toContain($delivery->id);
});

it('creates a delivery with valid payload', function () {
    actingAsInventoryDeliveryApiUser(['create_inventory_delivery']);

    $response = $this->postJson(deliveryRoute('store'), deliveryPayload())
        ->assertCreated()
        ->assertJsonPath('message', 'Delivery created successfully.');

    expect(Delivery::query()->whereKey($response->json('data.id'))->exists())->toBeTrue();
});

it('shows a delivery for authorized users', function () {
    actingAsInventoryDeliveryApiUser(['view_inventory_delivery']);

    $delivery = createDeliveryRecord();

    $this->getJson(deliveryRoute('show', $delivery->id))
        ->assertOk()
        ->assertJsonPath('data.id', $delivery->id);
});

it('returns 404 for a non-existent delivery', function () {
    actingAsInventoryDeliveryApiUser(['view_inventory_delivery']);

    $this->getJson(deliveryRoute('show', 999999))
        ->assertNotFound();
});

it('validates required move product_id when creating a delivery', function () {
    actingAsInventoryDeliveryApiUser(['create_inventory_delivery']);

    $payload = deliveryPayload([
        'moves' => [[
            'product_id'      => null,
            'product_uom_qty' => 1,
        ]],
    ]);

    $this->postJson(deliveryRoute('store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['moves.0.product_id']);
});

it('returns 404 when showing a non-delivery operation id', function () {
    actingAsInventoryDeliveryApiUser(['view_inventory_delivery']);

    $internalTransfer = InternalTransfer::query()->findOrFail(Operation::factory()->internal()->create()->id);

    $this->getJson(deliveryRoute('show', $internalTransfer->id))
        ->assertNotFound();
});

it('rejects check availability when delivery is not confirmed or assigned', function () {
    actingAsInventoryDeliveryApiUser(['update_inventory_delivery']);

    $delivery = createDeliveryRecord();

    $this->postJson(deliveryRoute('check-availability', $delivery->id))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Only confirmed or assigned operations can check availability.');
});

it('forbids check availability without update permission', function () {
    actingAsInventoryDeliveryApiUser();

    $delivery = createDeliveryRecord();

    $this->postJson(deliveryRoute('check-availability', $delivery->id))
        ->assertForbidden();
});

it('returns todo validation error when delivery has no moves', function () {
    actingAsInventoryDeliveryApiUser(['update_inventory_delivery']);

    $delivery = createDeliveryRecord();

    $this->postJson(deliveryRoute('todo', $delivery->id))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Cannot set operation to todo without moves.');
});

it('returns validate validation error for done delivery', function () {
    actingAsInventoryDeliveryApiUser(['update_inventory_delivery']);

    $delivery = createDeliveryRecord(['state' => OperationState::DONE]);

    $this->postJson(deliveryRoute('validate', $delivery->id))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Only non-done and non-canceled operations can be validated.');
});

it('returns cancel validation error for canceled delivery', function () {
    actingAsInventoryDeliveryApiUser(['update_inventory_delivery']);

    $delivery = createDeliveryRecord(['state' => OperationState::CANCELED]);

    $this->postJson(deliveryRoute('cancel', $delivery->id))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Only non-done and non-canceled operations can be canceled.');
});

it('returns return validation error when delivery is not done', function () {
    actingAsInventoryDeliveryApiUser(['update_inventory_delivery']);

    $delivery = createDeliveryRecord(['state' => OperationState::DRAFT]);

    $this->postJson(deliveryRoute('return', $delivery->id))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Only done operations can be returned.');
});

// ── Company-scope write-path guard ──────────────────────────────────────────────
// Bypass actingAsInventoryDeliveryApiUser/SecurityHelper on purpose — same
// pattern as LocationTest.php/RouteTest.php/WarehouseTest.php.

function actingAsScopedDeliveryUser(Company $company, array $permissions): User
{
    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $company->id,
    ]));

    $user->forceFill([
        'resource_permission' => PermissionType::GLOBAL,
    ])->saveQuietly();

    // Both guards: the app's default auth guard is sanctum, not web, so a
    // web-only permission silently fails Gate::authorize() regardless of
    // company-scope logic — same dual-guard pattern as SecurityHelper.
    // Raw upsert + re-query (not Permission::findOrCreate()) to avoid the
    // registrar's stale-cache duplicate-row bug: findOrCreate() can create a
    // second Permission row with a different id when the cache doesn't see
    // rows inserted via upsert() elsewhere, and givePermissionTo() then
    // attaches an id Gate::authorize() never matches.
    $records = collect($permissions)->crossJoin(['web', 'sanctum'])
        ->map(fn (array $pair) => ['name' => $pair[0], 'guard_name' => $pair[1]])
        ->all();

    Permission::query()->upsert($records, uniqueBy: ['name', 'guard_name'], update: []);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $user->givePermissionTo(
        Permission::query()->whereIn('name', $permissions)->whereIn('guard_name', ['web', 'sanctum'])->get()
    );

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // The API route group uses auth:sanctum middleware, so plain
    // test()->actingAs($user) (which only authenticates the default 'web'
    // guard) leaves the sanctum guard unauthenticated for the real HTTP
    // request — Gate::authorize() then denies for lack of permission on
    // that guard, which is indistinguishable from a real company-scope
    // denial in the response (bootstrap/app.php renders every
    // AuthorizationException as the same generic 403 message on API
    // requests, by design). Without the full guard chain this test would
    // "pass" without ever reaching the company-scope check. Same guard
    // chain as SecurityHelper::authenticateWithPermissions().
    Auth::guard('web')->login($user);
    Auth::guard('web')->setUser($user);
    Auth::guard('sanctum')->setUser($user);
    Auth::shouldUse('sanctum');
    Sanctum::actingAs($user, ['*']);

    return $user;
}

// OperationRequest (Delivery/Receipt/InternalTransfer/Dropship) has no rule
// for company_id — it's silently dropped by validated(), never reaches the
// controller. Unlike Warehouse/Scrap/Rule (which DO accept an explicit
// company_id), a delivery's effective company is always derived from its
// operation type's own source/destination locations, so there is no
// "explicit company_id vs operation type company" conflict to guard against
// here: the only real boundary is whether the operation type itself is
// visible to the acting user.

it('creates a delivery under the operation type company when the user is authorized in both', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    // Locations built explicitly against companyB — OperationTypeFactory's
    // delivery() state resolves its own nested Company::factory() for its
    // source/destination locations, which create(['company_id' => ...])
    // does NOT retroactively rewrite, so relying on the factory state here
    // would leave the operation type pointing at a third, invisible company.
    $sourceB = Location::factory()->internal()->create(['company_id' => $companyB->id]);
    $destinationB = Location::factory()->customer()->create(['company_id' => $companyB->id]);
    $operationTypeB = OperationType::factory()->delivery()->create([
        'company_id'               => $companyB->id,
        'source_location_id'       => $sourceB->id,
        'destination_location_id'  => $destinationB->id,
    ]);

    $user = actingAsScopedDeliveryUser($companyA, ['create_inventory_delivery']);
    $user->allowedCompanies()->attach($companyB->id);

    $payload = deliveryPayload(['operation_type_id' => $operationTypeB->id]);

    $response = $this->postJson(deliveryRoute('store'), $payload)
        ->assertCreated();

    $delivery = Delivery::query()->findOrFail($response->json('data.id'));

    expect($delivery->company_id)->toBe($companyB->id)
        ->and($delivery->company_id)->not->toBe($companyA->id);
});

it('forbids creating a delivery referencing an operation type of a company the user is not authorized in', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $operationTypeB = OperationType::factory()->delivery()->create(['company_id' => $companyB->id]);

    actingAsScopedDeliveryUser($companyA, ['create_inventory_delivery']);

    $payload = deliveryPayload(['operation_type_id' => $operationTypeB->id]);

    $this->postJson(deliveryRoute('store'), $payload)
        ->assertUnprocessable();

    $this->assertDatabaseMissing('inventories_operations', [
        'operation_type_id' => $operationTypeB->id,
    ]);
});

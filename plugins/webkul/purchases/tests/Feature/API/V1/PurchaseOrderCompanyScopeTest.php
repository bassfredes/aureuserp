<?php

use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Partner\Models\Partner;
use Webkul\Product\Models\Product;
use Webkul\Purchase\Models\Order;
use Webkul\Purchase\Models\Requisition;
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

// Bypasses SecurityHelper on purpose: it grants the acting user access to
// every company that already exists at authentication time, which would
// silently defeat these cross-company guard tests — same reasoning as
// inventories' WarehouseTest.php/LocationTest.php.
function actingAsScopedPurchaseOrderUser(Company $company, array $permissions): User
{
    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $company->id,
    ]));

    $user->forceFill([
        'resource_permission' => PermissionType::GLOBAL,
    ])->saveQuietly();

    $records = collect($permissions)
        ->map(fn (string $name) => ['name' => $name, 'guard_name' => 'web'])
        ->all();

    Permission::query()->upsert($records, uniqueBy: ['name', 'guard_name'], update: []);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $user->givePermissionTo(
        Permission::query()->whereIn('name', $permissions)->where('guard_name', 'web')->get()
    );

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Auth::guard('web')->login($user);
    Auth::guard('web')->setUser($user);
    Auth::guard('sanctum')->setUser($user);
    Auth::shouldUse('sanctum');
    Sanctum::actingAs($user, ['*']);

    return $user;
}

function scopedPurchaseOrderPayload(Company $company, array $overrides = []): array
{
    $currency = Currency::first() ?? Currency::factory()->create();
    $partner = Partner::factory()->create();
    $product = Product::factory()->create(['is_configurable' => false, 'company_id' => $company->id]);

    return array_replace_recursive([
        'partner_id'  => $partner->id,
        'currency_id' => $currency->id,
        'ordered_at'  => now()->format('Y-m-d'),
        'company_id'  => $company->id,
        'lines'       => [
            [
                'product_id'  => $product->id,
                'planned_at'  => now()->addDays(7)->format('Y-m-d'),
                'product_qty' => 10,
                'price_unit'  => 50.00,
            ],
        ],
    ], $overrides);
}

it('forbids a user from company A creating a purchase order in company B', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    actingAsScopedPurchaseOrderUser($companyA, ['create_purchase_purchase::order']);

    $this->postJson(route('admin.api.v1.purchases.purchase-orders.store'), scopedPurchaseOrderPayload($companyB))
        ->assertForbidden();

    $this->assertDatabaseMissing('purchases_orders', ['company_id' => $companyB->id]);
});

it('forbids a user from changing an existing purchase order from company A to company B', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = actingAsScopedPurchaseOrderUser($companyA, ['create_purchase_purchase::order', 'update_purchase_purchase::order']);

    $order = Order::factory()->create(['company_id' => $companyA->id]);

    $this->patchJson(route('admin.api.v1.purchases.purchase-orders.update', $order), [
        'company_id' => $companyB->id,
    ])->assertForbidden();

    $this->assertDatabaseHas('purchases_orders', [
        'id'         => $order->id,
        'company_id' => $companyA->id,
    ]);
});

it('rejects an explicit null company_id on purchase order update', function () {
    // company_id is a required field on PurchaseOrderRequest (never
    // nullable, unlike VendorPriceListRequest) — an explicit null fails
    // FormRequest validation before assertCompanyIdImmutable() is ever
    // reached, so this is a 422, not a 403. Still verifies null can't be
    // used to evade immutability.
    $companyA = Company::factory()->create();

    actingAsScopedPurchaseOrderUser($companyA, ['create_purchase_purchase::order', 'update_purchase_purchase::order']);

    $order = Order::factory()->create(['company_id' => $companyA->id]);

    $this->patchJson(route('admin.api.v1.purchases.purchase-orders.update', $order), [
        'company_id' => null,
    ])->assertUnprocessable();

    $this->assertDatabaseHas('purchases_orders', [
        'id'         => $order->id,
        'company_id' => $companyA->id,
    ]);
});

it('forbids relating a purchase order to a purchase agreement from a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    actingAsScopedPurchaseOrderUser($companyA, ['create_purchase_purchase::order']);

    $requisitionB = Requisition::factory()->create(['company_id' => $companyB->id]);

    $this->postJson(
        route('admin.api.v1.purchases.purchase-orders.store'),
        scopedPurchaseOrderPayload($companyA, ['requisition_id' => $requisitionB->id])
    )->assertForbidden();

    $this->assertDatabaseMissing('purchases_orders', ['requisition_id' => $requisitionB->id]);
});

it('creates purchase order lines in the same company as their order, not the acting user default', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = actingAsScopedPurchaseOrderUser($companyA, ['create_purchase_purchase::order']);
    $user->allowedCompanies()->attach($companyB->id);

    $response = $this->postJson(
        route('admin.api.v1.purchases.purchase-orders.store'),
        scopedPurchaseOrderPayload($companyB)
    )->assertCreated();

    $orderId = $response->json('data.id');

    $this->assertDatabaseHas('purchases_orders', ['id' => $orderId, 'company_id' => $companyB->id]);
    $this->assertDatabaseMissing('purchases_order_lines', ['order_id' => $orderId, 'company_id' => $companyA->id]);
    $this->assertDatabaseHas('purchases_order_lines', ['order_id' => $orderId, 'company_id' => $companyB->id]);
});

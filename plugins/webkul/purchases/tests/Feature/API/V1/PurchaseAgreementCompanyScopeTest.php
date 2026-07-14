<?php

use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Partner\Models\Partner;
use Webkul\Product\Models\Product;
use Webkul\Purchase\Enums\RequisitionType;
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

// Bypasses SecurityHelper on purpose: see PurchaseOrderCompanyScopeTest.php.
function actingAsScopedPurchaseAgreementUser(Company $company, array $permissions): User
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

function scopedPurchaseAgreementPayload(Company $company, array $overrides = []): array
{
    $currency = Currency::first() ?? Currency::factory()->create();
    $partner = Partner::factory()->create();
    $product = Product::factory()->create(['is_configurable' => false]);

    return array_replace_recursive([
        'partner_id'  => $partner->id,
        'type'        => RequisitionType::PURCHASE_TEMPLATE->value,
        'currency_id' => $currency->id,
        'company_id'  => $company->id,
        'lines'       => [
            [
                'product_id' => $product->id,
                'qty'        => 10,
                'price_unit' => 50.00,
            ],
        ],
    ], $overrides);
}

it('forbids a user from company A creating a purchase agreement in company B', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    actingAsScopedPurchaseAgreementUser($companyA, ['create_purchase_purchase::agreement']);

    $this->postJson(
        route('admin.api.v1.purchases.purchase-agreements.store'),
        scopedPurchaseAgreementPayload($companyB)
    )->assertForbidden();

    $this->assertDatabaseMissing('purchases_requisitions', ['company_id' => $companyB->id]);
});

it('forbids a user from changing an existing purchase agreement from company A to company B', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    actingAsScopedPurchaseAgreementUser($companyA, ['create_purchase_purchase::agreement', 'update_purchase_purchase::agreement']);

    $agreement = Requisition::factory()->create(['company_id' => $companyA->id]);

    $this->patchJson(route('admin.api.v1.purchases.purchase-agreements.update', $agreement), [
        'company_id' => $companyB->id,
    ])->assertForbidden();

    $this->assertDatabaseHas('purchases_requisitions', [
        'id'         => $agreement->id,
        'company_id' => $companyA->id,
    ]);
});

it('rejects an explicit null company_id on purchase agreement update', function () {
    // company_id is required on PurchaseAgreementRequest, so an explicit
    // null fails validation (422) before reaching assertCompanyIdImmutable().
    $companyA = Company::factory()->create();

    actingAsScopedPurchaseAgreementUser($companyA, ['create_purchase_purchase::agreement', 'update_purchase_purchase::agreement']);

    $agreement = Requisition::factory()->create(['company_id' => $companyA->id]);

    $this->patchJson(route('admin.api.v1.purchases.purchase-agreements.update', $agreement), [
        'company_id' => null,
    ])->assertUnprocessable();

    $this->assertDatabaseHas('purchases_requisitions', [
        'id'         => $agreement->id,
        'company_id' => $companyA->id,
    ]);
});

it('creates purchase agreement lines in the same company as their agreement, not the acting user default', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = actingAsScopedPurchaseAgreementUser($companyA, ['create_purchase_purchase::agreement']);
    $user->allowedCompanies()->attach($companyB->id);

    $response = $this->postJson(
        route('admin.api.v1.purchases.purchase-agreements.store'),
        scopedPurchaseAgreementPayload($companyB)
    )->assertCreated();

    $agreementId = $response->json('data.id');

    $this->assertDatabaseHas('purchases_requisitions', ['id' => $agreementId, 'company_id' => $companyB->id]);
    $this->assertDatabaseMissing('purchases_requisition_lines', ['requisition_id' => $agreementId, 'company_id' => $companyA->id]);
    $this->assertDatabaseHas('purchases_requisition_lines', ['requisition_id' => $agreementId, 'company_id' => $companyB->id]);
});

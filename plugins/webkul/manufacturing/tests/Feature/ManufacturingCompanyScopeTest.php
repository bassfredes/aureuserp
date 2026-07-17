<?php

use Webkul\Inventory\Models\OperationType;
use Webkul\Manufacturing\Models\BillOfMaterial;
use Webkul\Manufacturing\Models\WorkCenter;
use Webkul\Product\Models\Product;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\UOM;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('manufacturing');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

// ── WorkCenter (strict_company) ─────────────────────────────────────────────

it('hides WorkCenters from a company the user is not allowed to see', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $userA = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));

    $workCenterA = WorkCenter::factory()->create(['company_id' => $companyA->id]);
    $workCenterB = WorkCenter::factory()->create(['company_id' => $companyB->id]);

    test()->actingAs($userA);

    expect(WorkCenter::find($workCenterA->id))->not->toBeNull();
    expect(WorkCenter::find($workCenterB->id))->toBeNull();
});

// ── BillOfMaterial (company_or_shared — global templates via ADR 0007) ─────

it('shows a user their own company BillOfMaterials plus the shared/global ones', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $userA = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->create(['company_id' => $companyB->id]);
    $bomA = BillOfMaterial::factory()->create(['company_id' => $companyA->id, 'product_id' => $productA->id]);
    $bomB = BillOfMaterial::factory()->create(['company_id' => $companyB->id, 'product_id' => $productB->id]);

    $globalBom = CompanyContext::runForAllCompanies(
        reason: 'test fixture setup — global BOM template',
        caller: __FILE__,
        callback: fn () => BillOfMaterial::factory()->create(['company_id' => null]),
    );

    test()->actingAs($userA);

    $visibleIds = BillOfMaterial::query()->pluck('id');

    expect($visibleIds)->toContain($bomA->id, $globalBom->id);
    expect($visibleIds)->not->toContain($bomB->id);
});

it('forbids a regular authenticated user from creating a global (company_id null) BillOfMaterial', function () {
    $company = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id]));

    test()->actingAs($user);

    expect(fn () => BillOfMaterial::factory()->create(['company_id' => null]))
        ->toThrow(Exception::class);
});

it('forbids a regular authenticated user from modifying or deleting a global BillOfMaterial template', function () {
    $company = Company::factory()->create();

    $globalBom = CompanyContext::runForAllCompanies(
        reason: 'test fixture setup — global BOM template',
        caller: __FILE__,
        callback: fn () => BillOfMaterial::factory()->create(['company_id' => null]),
    );

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id]));
    test()->actingAs($user);

    expect(fn () => $globalBom->update(['code' => 'HACKED']))->toThrow(Exception::class);
    expect(fn () => $globalBom->delete())->toThrow(Exception::class);
});

it('lets a super_admin create and modify a global BillOfMaterial template', function () {
    // default_company_id must stay null here: BillOfMaterial's own
    // creating() hook does `$billOfMaterial->company_id ??= $authUser
    // ?->default_company_id`, so a super_admin WITH a default company
    // would have an explicit `company_id => null` silently overwritten
    // by their own company before guardSharedRowMutation ever runs.
    $superAdmin = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => null]));
    $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));

    // Every foreign key is pre-created explicitly (no
    // Query::value('id') ?? factory() fallback in the middle of
    // BillOfMaterialFactory's own definition()) so this test only
    // exercises the guard itself, not incidental nested-factory state.
    $product = Product::factory()->create();
    $uom = UOM::factory()->create();
    $operationType = OperationType::factory()->create();

    test()->actingAs($superAdmin);

    $globalBom = BillOfMaterial::factory()->create([
        'company_id'        => null,
        'product_id'        => $product->id,
        'uom_id'            => $uom->id,
        'operation_type_id' => $operationType->id,
        'creator_id'        => $superAdmin->id,
    ]);

    expect($globalBom->exists)->toBeTrue()
        ->and($globalBom->company_id)->toBeNull();

    $globalBom->update(['code' => 'BOM-GLOBAL']);

    expect($globalBom->fresh()->code)->toBe('BOM-GLOBAL');
});

it('lets a super_admin bypass company isolation for BillOfMaterials via forAllCompanies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $superAdmin = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->create(['company_id' => $companyB->id]);
    $bomA = BillOfMaterial::factory()->create(['company_id' => $companyA->id, 'product_id' => $productA->id]);
    $bomB = BillOfMaterial::factory()->create(['company_id' => $companyB->id, 'product_id' => $productB->id]);

    test()->actingAs($superAdmin);

    expect(BillOfMaterial::find($bomB->id))->toBeNull();

    $bypassedIds = BillOfMaterial::forAllCompanies()->pluck('id')->all();

    expect($bypassedIds)->toContain($bomA->id, $bomB->id);
});

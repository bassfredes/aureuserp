<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Manufacturing\Models\BillOfMaterial;
use Webkul\Manufacturing\Models\WorkCenter;
use Webkul\Product\Models\Product;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
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

    // No acting user yet — WorkCenter's write-authorization check needs a
    // system context for this fixture (#138 review round 2, 2026-07-18).
    [$workCenterA, $workCenterB] = TestBootstrapHelper::withSystemContextIfNoUser(fn () => [
        WorkCenter::factory()->create(['company_id' => $companyA->id]),
        WorkCenter::factory()->create(['company_id' => $companyB->id]),
    ]);

    test()->actingAs($userA);

    expect(WorkCenter::find($workCenterA->id))->not->toBeNull();
    expect(WorkCenter::find($workCenterB->id))->toBeNull();
});

// ── BillOfMaterial (strict_company — D2, no shared/global rows) ────────────

it('hides BillOfMaterials from a company the user is not allowed to see', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $userA = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));

    // No acting user yet — BillOfMaterial's write-authorization check
    // (via resolveEffectiveCompanyIdOrFail()) needs a system context for
    // this fixture (#138 review round 2, 2026-07-18).
    [$bomA, $bomB] = TestBootstrapHelper::withSystemContextIfNoUser(function () use ($companyA, $companyB) {
        $productA = Product::factory()->create(['company_id' => $companyA->id]);
        $productB = Product::factory()->create(['company_id' => $companyB->id]);

        return [
            BillOfMaterial::factory()->create(['company_id' => $companyA->id, 'product_id' => $productA->id]),
            BillOfMaterial::factory()->create(['company_id' => $companyB->id, 'product_id' => $productB->id]),
        ];
    });

    test()->actingAs($userA);

    $visibleIds = BillOfMaterial::query()->pluck('id');

    expect($visibleIds)->toContain($bomA->id);
    expect($visibleIds)->not->toContain($bomB->id);
});

it('forbids creating a BillOfMaterial for a Product that has no company of its own (D2: strict_company, company_id NULL is never persisted)', function () {
    $company = Company::factory()->create();

    $productWithNoCompany = CompanyContext::runForAllCompanies(
        reason: 'test fixture setup — Product with no company',
        caller: __FILE__,
        callback: fn () => Product::factory()->create(['company_id' => null]),
    );

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id]));
    test()->actingAs($user);

    expect(fn () => BillOfMaterial::factory()->create(['company_id' => null, 'product_id' => $productWithNoCompany->id]))
        ->toThrow(AuthorizationException::class);
});

it('derives BillOfMaterial.company_id from its Product, not the acting user\'s default', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyB->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $productA = Product::factory()->create(['company_id' => $companyA->id]);

    $bom = BillOfMaterial::factory()->create(['company_id' => null, 'product_id' => $productA->id]);

    expect($bom->company_id)->toBe($companyA->id);
});

it('forbids an explicit BillOfMaterial company_id that mismatches its Product\'s company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $productA = Product::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => BillOfMaterial::factory()->create(['company_id' => $companyB->id, 'product_id' => $productA->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids creating a BillOfMaterial when the Product cannot be resolved', function () {
    $company = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id]));
    test()->actingAs($user);

    expect(fn () => BillOfMaterial::factory()->create(['company_id' => null, 'product_id' => 999999999]))
        ->toThrow(AuthorizationException::class);
});

it('lets a super_admin bypass company isolation for BillOfMaterials via forAllCompanies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $superAdmin = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));

    // No acting user yet — same system-context requirement as above
    // (#138 review round 2, 2026-07-18).
    [$bomA, $bomB] = TestBootstrapHelper::withSystemContextIfNoUser(function () use ($companyA, $companyB) {
        $productA = Product::factory()->create(['company_id' => $companyA->id]);
        $productB = Product::factory()->create(['company_id' => $companyB->id]);

        return [
            BillOfMaterial::factory()->create(['company_id' => $companyA->id, 'product_id' => $productA->id]),
            BillOfMaterial::factory()->create(['company_id' => $companyB->id, 'product_id' => $productB->id]),
        ];
    });

    test()->actingAs($superAdmin);

    expect(BillOfMaterial::find($bomB->id))->toBeNull();

    $bypassedIds = BillOfMaterial::forAllCompanies()->pluck('id')->all();

    expect($bypassedIds)->toContain($bomA->id, $bomB->id);
});

<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Maintenance\Models\EquipmentCategory;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('maintenance');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

// ── company_id obligatorio, autorización en create ──────────────────────

it('fails closed when creating an EquipmentCategory with no authenticated user and no active CompanyContext', function () {
    $company = Company::factory()->create();

    expect(fn () => EquipmentCategory::factory()->create(['company_id' => $company->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('maintenance_equipment_categories', ['company_id' => $company->id]);
});

it('forbids a user in company A from creating an EquipmentCategory directly under company B by knowing its id', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => EquipmentCategory::factory()->create(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('maintenance_equipment_categories', ['company_id' => $companyB->id]);
});

it('derives an EquipmentCategory.company_id from the acting user\'s default_company_id when omitted', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $category = EquipmentCategory::factory()->create(['company_id' => null]);

    expect($category->company_id)->toBe($companyA->id);
});

// ── inmutable, reautorización en cada save ───────────────────────────────

it('forbids changing an EquipmentCategory\'s company_id, even for a user authorized in both companies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $category = EquipmentCategory::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => $category->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('maintenance_equipment_categories', ['id' => $category->id, 'company_id' => $companyA->id]);
});

it('forbids a user in company A from updating an unrelated field on an EquipmentCategory obtained from company B via an unscoped query', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $categoryB = CompanyContext::runForAllCompanies(
        reason: 'fixture', caller: __FILE__,
        callback: fn () => EquipmentCategory::factory()->create(['company_id' => $companyB->id]),
    );

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $categoryBUnscoped = EquipmentCategory::withoutGlobalScope(CompanyScope::class)->findOrFail($categoryB->id);

    expect(fn () => $categoryBUnscoped->update(['name' => 'Renamed by A']))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('maintenance_equipment_categories', ['id' => $categoryB->id, 'name' => 'Renamed by A']);
});

// ── read isolation (HasCompanyScope) ─────────────────────────────────────

it('lets a user see only EquipmentCategories in their allowed companies', function () {
    $companyA = Company::factory()->create();
    $companyC = Company::factory()->create();

    $categoryA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => EquipmentCategory::factory()->create(['company_id' => $companyA->id]));
    $categoryC = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => EquipmentCategory::factory()->create(['company_id' => $companyC->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->syncWithoutDetaching([$companyA->id]);
    test()->actingAs($user);

    $visibleIds = EquipmentCategory::query()->pluck('id');

    expect($visibleIds)->toContain($categoryA->id)
        ->not->toContain($categoryC->id);
});

it('shows an authenticated user with no allowed companies an empty EquipmentCategory list', function () {
    Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => null]));
    test()->actingAs($user);

    expect(EquipmentCategory::query()->count())->toBe(0);
});

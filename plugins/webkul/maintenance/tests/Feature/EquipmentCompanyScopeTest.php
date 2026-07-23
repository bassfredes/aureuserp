<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Maintenance\Models\Equipment;
use Webkul\Maintenance\Models\EquipmentCategory;
use Webkul\Maintenance\Models\Team;
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

it('fails closed when creating an Equipment with no authenticated user and no active CompanyContext', function () {
    $company = Company::factory()->create();

    expect(fn () => Equipment::factory()->create(['company_id' => $company->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('maintenance_equipments', ['company_id' => $company->id]);
});

it('forbids a user in company A from creating an Equipment directly under company B by knowing its id', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => Equipment::factory()->create(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('maintenance_equipments', ['company_id' => $companyB->id]);
});

it('derives an Equipment.company_id from the acting user\'s default_company_id when omitted', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $equipment = Equipment::factory()->create(['company_id' => null]);

    expect($equipment->company_id)->toBe($companyA->id);
});

// ── inmutable, reautorización en cada save ───────────────────────────────

it('forbids changing an Equipment\'s company_id, even for a user authorized in both companies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $equipment = Equipment::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => $equipment->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('maintenance_equipments', ['id' => $equipment->id, 'company_id' => $companyA->id]);
});

it('forbids a user in company A from updating an unrelated field on an Equipment obtained from company B via an unscoped query', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $equipmentB = CompanyContext::runForAllCompanies(
        reason: 'fixture', caller: __FILE__,
        callback: fn () => Equipment::factory()->create(['company_id' => $companyB->id]),
    );

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $equipmentBUnscoped = Equipment::withoutGlobalScope(CompanyScope::class)->findOrFail($equipmentB->id);

    expect(fn () => $equipmentBUnscoped->update(['name' => 'Renamed by A']))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('maintenance_equipments', ['id' => $equipmentB->id, 'name' => 'Renamed by A']);
});

// ── relation-integrity: category_id / maintenance_team_id must match company_id ──

it('forbids creating an Equipment whose category_id belongs to a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $categoryB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => EquipmentCategory::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => Equipment::factory()->create(['company_id' => $companyA->id, 'category_id' => $categoryB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('maintenance_equipments', ['category_id' => $categoryB->id]);
});

it('forbids creating an Equipment whose maintenance_team_id belongs to a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $teamB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Team::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => Equipment::factory()->create(['company_id' => $companyA->id, 'maintenance_team_id' => $teamB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('maintenance_equipments', ['maintenance_team_id' => $teamB->id]);
});

it('allows creating an Equipment whose category_id and maintenance_team_id belong to the same company', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $categoryA = EquipmentCategory::factory()->create(['company_id' => $companyA->id]);
    $teamA = Team::factory()->create(['company_id' => $companyA->id]);

    $equipment = Equipment::factory()->create([
        'company_id'           => $companyA->id,
        'category_id'          => $categoryA->id,
        'maintenance_team_id'  => $teamA->id,
    ]);

    expect($equipment->exists)->toBeTrue();
});

// ── read isolation (HasCompanyScope) ─────────────────────────────────────

it('lets a user see only Equipments in their allowed companies', function () {
    $companyA = Company::factory()->create();
    $companyC = Company::factory()->create();

    $equipmentA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Equipment::factory()->create(['company_id' => $companyA->id]));
    $equipmentC = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Equipment::factory()->create(['company_id' => $companyC->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->syncWithoutDetaching([$companyA->id]);
    test()->actingAs($user);

    $visibleIds = Equipment::query()->pluck('id');

    expect($visibleIds)->toContain($equipmentA->id)
        ->not->toContain($equipmentC->id);
});

it('shows an authenticated user with no allowed companies an empty Equipment list', function () {
    Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => null]));
    test()->actingAs($user);

    expect(Equipment::query()->count())->toBe(0);
});

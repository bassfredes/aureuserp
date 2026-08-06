<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Employee\Models\WorkLocation;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('employees');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

it('shows a user only WorkLocations in their own company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $locationA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => WorkLocation::factory()->create(['company_id' => $companyA->id]));
    $locationB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => WorkLocation::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $ids = WorkLocation::query()->pluck('id');

    expect($ids)->toContain($locationA->id)
        ->not->toContain($locationB->id);
});

it('shows nothing with no authenticated user and no active CompanyContext', function () {
    $company = Company::factory()->create();
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => WorkLocation::factory()->create(['company_id' => $company->id]));

    expect(WorkLocation::query()->count())->toBe(0);
});

it('preserves scopeActive() filtering only active locations, still scoped by company', function () {
    $companyA = Company::factory()->create();

    $activeA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => WorkLocation::factory()->create(['company_id' => $companyA->id, 'is_active' => true]));
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => WorkLocation::factory()->create(['company_id' => $companyA->id, 'is_active' => false]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $activeIds = WorkLocation::active()->pluck('id');

    expect($activeIds)->toContain($activeA->id)
        ->toHaveCount(1);
});

it('forbids a user in company A from creating a WorkLocation directly under company B', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => WorkLocation::factory()->create(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids changing a WorkLocation company_id, even for a user authorized in both companies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->syncWithoutDetaching([$companyB->id]);
    test()->actingAs($user);

    $location = WorkLocation::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => $location->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids a user in company A from updating a WorkLocation in company B obtained via withoutGlobalScope', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $locationB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => WorkLocation::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $locationBUnscoped = WorkLocation::withoutGlobalScope(CompanyScope::class)->findOrFail($locationB->id);

    expect(fn () => $locationBUnscoped->update(['name' => 'Renamed by A']))
        ->toThrow(AuthorizationException::class);
});

it('forbids a user in company A from deleting a WorkLocation in company B obtained via withoutGlobalScope', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $locationB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => WorkLocation::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $locationBUnscoped = WorkLocation::withoutGlobalScope(CompanyScope::class)->findOrFail($locationB->id);

    expect(fn () => $locationBUnscoped->delete())->toThrow(AuthorizationException::class);
    $this->assertDatabaseHas('employees_work_locations', ['id' => $locationB->id, 'deleted_at' => null]);
});

it('allows a user in company A to delete, restore and force-delete their own WorkLocation', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $location = WorkLocation::factory()->create(['company_id' => $companyA->id]);

    $location->delete();
    $this->assertSoftDeleted('employees_work_locations', ['id' => $location->id]);

    $location->restore();
    $this->assertDatabaseHas('employees_work_locations', ['id' => $location->id, 'deleted_at' => null]);

    $location->forceDelete();
    $this->assertDatabaseMissing('employees_work_locations', ['id' => $location->id]);
});

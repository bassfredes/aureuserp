<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Employee\Models\Employee;
use Webkul\Recruitment\Filament\Clusters\Applications\Resources\JobByPositionResource;
use Webkul\Recruitment\Filament\Clusters\Configurations\Resources\DepartmentResource;
use Webkul\Recruitment\Filament\Clusters\Configurations\Resources\JobPositionResource as JobPositionResourceCluster;
use Webkul\Recruitment\Models\Department as RecruitmentDepartment;
use Webkul\Recruitment\Models\JobByPosition;
use Webkul\Recruitment\Models\JobPosition as RecruitmentJobPosition;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('recruitments');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

// No dedicated factory exists for these 3 aliases (they have no logic of
// their own beyond what's tested here) — and calling <Alias>::factory()
// would silently resolve to the OWNER's factory, whose hardcoded $model
// property instantiates the owner class regardless of which subclass's
// ::factory() was called (same gotcha already documented for
// Project\ActivityPlan in ola 4B). Created directly via ::create() with
// explicit attributes instead, so the object saved really is an instance
// of the alias class under test, and its own boot()-registered listeners
// (if any) actually fire.

// ── alias propagation: each subclass inherits the owner's scope automatically ──

it('scopes Recruitment\Department (a thin subclass) the same way as its Employee base', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $departmentA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => RecruitmentDepartment::create(['name' => 'Dept A', 'company_id' => $companyA->id]));
    $departmentB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => RecruitmentDepartment::create(['name' => 'Dept B', 'company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $ids = RecruitmentDepartment::query()->pluck('id');

    expect($ids)->toContain($departmentA->id)
        ->not->toContain($departmentB->id);
});

it('scopes Recruitment\JobPosition (extends EmployeeJobPosition with its own added logic) the same way as its base', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $jobA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => RecruitmentJobPosition::create(['name' => 'Job A', 'company_id' => $companyA->id]));
    $jobB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => RecruitmentJobPosition::create(['name' => 'Job B', 'company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $ids = RecruitmentJobPosition::query()->pluck('id');

    expect($ids)->toContain($jobA->id)
        ->not->toContain($jobB->id);
});

it('scopes Recruitment\JobByPosition (two levels of extends from the owner) the same way as its base', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $jobA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => JobByPosition::create(['name' => 'Job A', 'company_id' => $companyA->id]));
    $jobB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => JobByPosition::create(['name' => 'Job B', 'company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $ids = JobByPosition::query()->pluck('id');

    expect($ids)->toContain($jobA->id)
        ->not->toContain($jobB->id);
});

// ── Recruitment\JobPosition's own added manager_id (not inherited) ────────

it('forbids Recruitment\JobPosition.manager_id pointing at an Employee in a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $managerB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => RecruitmentJobPosition::create(['name' => 'Job A', 'company_id' => $companyA->id, 'manager_id' => $managerB->id]))
        ->toThrow(AuthorizationException::class);
});

it('allows Recruitment\JobPosition.manager_id pointing at an Employee in the same company', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $managerA = Employee::factory()->create(['company_id' => $companyA->id]);

    $job = RecruitmentJobPosition::create(['name' => 'Job A', 'company_id' => $companyA->id, 'manager_id' => $managerA->id]);

    expect($job->manager_id)->toBe($managerA->id);
});

// ── Resource query surfaces: none call withoutGlobalScope(), verified directly ──

it('does not let DepartmentResource enumerate a Department from a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $departmentA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => RecruitmentDepartment::create(['name' => 'Dept A', 'company_id' => $companyA->id]));
    $departmentB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => RecruitmentDepartment::create(['name' => 'Dept B', 'company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $ids = DepartmentResource::getEloquentQuery()->pluck('id');

    expect($ids)->toContain($departmentA->id)
        ->not->toContain($departmentB->id);
});

it('does not let JobPositionResource enumerate an EmployeeJobPosition from a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $jobA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => RecruitmentJobPosition::create(['name' => 'Job A', 'company_id' => $companyA->id]));
    $jobB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => RecruitmentJobPosition::create(['name' => 'Job B', 'company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $ids = JobPositionResourceCluster::getEloquentQuery()->pluck('id');

    expect($ids)->toContain($jobA->id)
        ->not->toContain($jobB->id);
});

it('does not let JobByPositionResource enumerate a JobByPosition from a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $jobA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => JobByPosition::create(['name' => 'Job A', 'company_id' => $companyA->id]));
    $jobB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => JobByPosition::create(['name' => 'Job B', 'company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $ids = JobByPositionResource::getEloquentQuery()->pluck('id');

    expect($ids)->toContain($jobA->id)
        ->not->toContain($jobB->id);
});

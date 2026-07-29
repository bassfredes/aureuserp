<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\EmployeeJobPosition;
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

// ── read isolation ─────────────────────────────────────────────────────

it('shows a user only EmployeeJobPositions in their own company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $jobA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => EmployeeJobPosition::factory()->create(['company_id' => $companyA->id]));
    $jobB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => EmployeeJobPosition::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $ids = EmployeeJobPosition::query()->pluck('id');

    expect($ids)->toContain($jobA->id)
        ->not->toContain($jobB->id);
});

it('shows nothing with no authenticated user and no active CompanyContext', function () {
    $company = Company::factory()->create();
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => EmployeeJobPosition::factory()->create(['company_id' => $company->id]));

    expect(EmployeeJobPosition::query()->count())->toBe(0);
});

// ── write: create/update reauthorize the effective company ───────────────

it('forbids a user in company A from creating an EmployeeJobPosition directly under company B', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => EmployeeJobPosition::factory()->create(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids changing an EmployeeJobPosition company_id, even for a user authorized in both companies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->syncWithoutDetaching([$companyB->id]);
    test()->actingAs($user);

    $job = EmployeeJobPosition::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => $job->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids a user in company A from updating an EmployeeJobPosition obtained from company B via withoutGlobalScope', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $jobB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => EmployeeJobPosition::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $jobBUnscoped = EmployeeJobPosition::withoutGlobalScope(CompanyScope::class)->findOrFail($jobB->id);

    expect(fn () => $jobBUnscoped->update(['name' => 'Renamed by A']))
        ->toThrow(AuthorizationException::class);
});

// ── lifecycle ──────────────────────────────────────────────────────────

it('forbids a user in company A from deleting an EmployeeJobPosition in company B obtained via withoutGlobalScope', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $jobB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => EmployeeJobPosition::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $jobBUnscoped = EmployeeJobPosition::withoutGlobalScope(CompanyScope::class)->findOrFail($jobB->id);

    expect(fn () => $jobBUnscoped->delete())->toThrow(AuthorizationException::class);
    $this->assertDatabaseHas('employees_job_positions', ['id' => $jobB->id, 'deleted_at' => null]);
});

it('allows a user in company A to delete, restore and force-delete their own EmployeeJobPosition', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $job = EmployeeJobPosition::factory()->create(['company_id' => $companyA->id]);

    $job->delete();
    $this->assertSoftDeleted('employees_job_positions', ['id' => $job->id]);

    $job->restore();
    $this->assertDatabaseHas('employees_job_positions', ['id' => $job->id, 'deleted_at' => null]);

    $job->forceDelete();
    $this->assertDatabaseMissing('employees_job_positions', ['id' => $job->id]);
});

// ── department_id: same-company or reject ─────────────────────────────────

it('forbids an EmployeeJobPosition.department_id pointing at a Department in a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $departmentB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Department::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => EmployeeJobPosition::factory()->create(['company_id' => $companyA->id, 'department_id' => $departmentB->id]))
        ->toThrow(AuthorizationException::class);
});

it('allows an EmployeeJobPosition.department_id pointing at a Department in the same company', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $departmentA = Department::factory()->create(['company_id' => $companyA->id]);

    $job = EmployeeJobPosition::factory()->create(['company_id' => $companyA->id, 'department_id' => $departmentA->id]);

    expect($job->department_id)->toBe($departmentA->id);
});

// ── recruiter_id: User membership, not equality ───────────────────────────

it('forbids an EmployeeJobPosition.recruiter_id referencing a User with no membership in the job company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $recruiterB = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyB->id]));

    expect(fn () => EmployeeJobPosition::factory()->create(['company_id' => $companyA->id, 'recruiter_id' => $recruiterB->id]))
        ->toThrow(AuthorizationException::class);
});

it('allows an EmployeeJobPosition.recruiter_id referencing a User with membership in the job company', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $recruiterA = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));

    $job = EmployeeJobPosition::factory()->create(['company_id' => $companyA->id, 'recruiter_id' => $recruiterA->id]);

    expect($job->recruiter_id)->toBe($recruiterA->id);
});

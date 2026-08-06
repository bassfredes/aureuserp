<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeJobPosition;
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

it('creates an Employee under context A with every tenant-aware default relation also in A', function () {
    $companyA = Company::factory()->create();

    $employee = CompanyContext::runForCompany($companyA->id, reason: 'test', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $companyA->id]));

    expect($employee->company_id)->toBe($companyA->id);
    expect(Department::withoutGlobalScope(CompanyScope::class)->find($employee->department_id)->company_id)->toBe($companyA->id);
    expect(EmployeeJobPosition::withoutGlobalScope(CompanyScope::class)->find($employee->job_id)->company_id)->toBe($companyA->id);
    expect(WorkLocation::withoutGlobalScope(CompanyScope::class)->find($employee->work_location_id)->company_id)->toBe($companyA->id);
});

it('creates the EmployeeJobPosition default with its Department in the same company', function () {
    $companyA = Company::factory()->create();

    $employee = CompanyContext::runForCompany($companyA->id, reason: 'test', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $companyA->id]));

    $job = EmployeeJobPosition::withoutGlobalScope(CompanyScope::class)->find($employee->job_id);

    expect($job->department_id)->toBe($employee->department_id);
});

it('defaults parent_id and coach_id to null, never assigning a User id to an Employee self-relation', function () {
    $companyA = Company::factory()->create();

    $employee = CompanyContext::runForCompany($companyA->id, reason: 'test', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $companyA->id]));

    expect($employee->parent_id)->toBeNull();
    expect($employee->coach_id)->toBeNull();
});

it('gives user_id and attendance_manager_id a User whose default_company_id matches the Employee company', function () {
    $companyA = Company::factory()->create();

    $employee = CompanyContext::runForCompany($companyA->id, reason: 'test', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $companyA->id]));

    expect(User::find($employee->user_id)->default_company_id)->toBe($companyA->id);
    expect(User::find($employee->attendance_manager_id)->default_company_id)->toBe($companyA->id);
});

it('honors an explicit same-company override for department_id/job_id/work_location_id', function () {
    $companyA = Company::factory()->create();

    [$employee, $department] = CompanyContext::runForCompany($companyA->id, reason: 'test', caller: __FILE__, callback: function () use ($companyA) {
        $department = Department::factory()->create(['company_id' => $companyA->id]);
        $employee = Employee::factory()->create(['company_id' => $companyA->id, 'department_id' => $department->id]);

        return [$employee, $department];
    });

    expect($employee->department_id)->toBe($department->id);
});

it('rejects an explicit cross-company override — the model, not the factory, is the authority', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $departmentB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Department::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => Employee::factory()->create(['company_id' => $companyA->id, 'department_id' => $departmentB->id]))
        ->toThrow(AuthorizationException::class);
});

it('fails closed with no authenticated user and no active CompanyContext — the factory grants no implicit access', function () {
    expect(fn () => Employee::factory()->create())
        ->toThrow(AuthorizationException::class);
});

it('leaves no CompanyContext open after the factory runs, success or failure', function () {
    $companyA = Company::factory()->create();

    CompanyContext::runForCompany($companyA->id, reason: 'test', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $companyA->id]));

    expect(CompanyContext::current())->toBeNull();

    try {
        Employee::factory()->create();
    } catch (AuthorizationException) {
        // expected: no context, no actor
    }

    expect(CompanyContext::current())->toBeNull();
});

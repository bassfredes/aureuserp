<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Services\CompanyContext;
use Webkul\TimeOff\Models\LeaveAllocation;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('time-off');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

// Employee itself carries no scope trait yet — no CompanyContext needed to
// create one directly.
function allocationEmployeeIn(int $companyId): Employee
{
    return Employee::factory()->create(['company_id' => $companyId]);
}

function allocationCompanylessEmployeeFixture(): Employee
{
    $id = DB::table('employees_employees')->insertGetId([
        'name'       => 'companyless fixture',
        'company_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return Employee::withoutGlobalScope(CompanyScope::class)->findOrFail($id);
}

it('derives LeaveAllocation.employee_company_id from the persisted Employee, not the acting user', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $employeeA = allocationEmployeeIn($companyA->id);

    $allocation = LeaveAllocation::factory()->create(['employee_id' => $employeeA->id]);

    expect($allocation->employee_company_id)->toBe($companyA->id);
});

it('forbids creating a LeaveAllocation under an Employee the acting user is not authorized for', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $employeeB = allocationEmployeeIn($companyB->id);

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => LeaveAllocation::factory()->create(['employee_id' => $employeeB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('time_off_leave_allocations', ['employee_id' => $employeeB->id]);
});

it('fails closed when creating a LeaveAllocation under an Employee that itself has no company', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $companylessEmployee = allocationCompanylessEmployeeFixture();

    expect(fn () => LeaveAllocation::factory()->create(['employee_id' => $companylessEmployee->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids creating a LeaveAllocation whose department belongs to a different company than the Employee', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $employeeA = allocationEmployeeIn($companyA->id);
    $departmentB = Department::factory()->create(['company_id' => $companyB->id]);

    expect(fn () => LeaveAllocation::factory()->create(['employee_id' => $employeeA->id, 'department_id' => $departmentB->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids a user in company A from updating an unrelated field on a LeaveAllocation whose Employee is hidden in company B, obtained via an unscoped query', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $employeeB = allocationEmployeeIn($companyB->id);
    $allocationB = CompanyContext::runForAllCompanies(
        reason: 'fixture', caller: __FILE__,
        callback: fn () => LeaveAllocation::factory()->create(['employee_id' => $employeeB->id]),
    );

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(LeaveAllocation::find($allocationB->id))->toBeNull();

    $allocationBUnscoped = LeaveAllocation::withoutGlobalScope('viaEmployeeCompany')->findOrFail($allocationB->id);

    expect(fn () => $allocationBUnscoped->update(['notes' => 'Renamed by A']))
        ->toThrow(AuthorizationException::class);
});

it('forbids retargeting a LeaveAllocation hidden in company B to an Employee in company A, obtained via an unscoped query', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $employeeB = allocationEmployeeIn($companyB->id);
    $allocationB = CompanyContext::runForAllCompanies(
        reason: 'fixture', caller: __FILE__,
        callback: fn () => LeaveAllocation::factory()->create(['employee_id' => $employeeB->id]),
    );

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);
    $employeeA = allocationEmployeeIn($companyA->id);

    $allocationBUnscoped = LeaveAllocation::withoutGlobalScope('viaEmployeeCompany')->findOrFail($allocationB->id);

    expect(fn () => $allocationBUnscoped->update(['employee_id' => $employeeA->id]))
        ->toThrow(AuthorizationException::class);
});

it('lets a user see only LeaveAllocations whose Employee is in their allowed companies', function () {
    $companyA = Company::factory()->create();
    $companyC = Company::factory()->create();

    $employeeA = allocationEmployeeIn($companyA->id);
    $employeeC = allocationEmployeeIn($companyC->id);

    $allocationA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => LeaveAllocation::factory()->create(['employee_id' => $employeeA->id]));
    $allocationC = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => LeaveAllocation::factory()->create(['employee_id' => $employeeC->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->syncWithoutDetaching([$companyA->id]);
    test()->actingAs($user);

    $visibleIds = LeaveAllocation::query()->pluck('id');

    expect($visibleIds)->toContain($allocationA->id)
        ->not->toContain($allocationC->id);
});

it('shows an authenticated user with no allowed companies an empty LeaveAllocation list', function () {
    $company = Company::factory()->create();
    $employee = allocationEmployeeIn($company->id);
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => LeaveAllocation::factory()->create(['employee_id' => $employee->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => null]));
    test()->actingAs($user);

    expect(LeaveAllocation::query()->count())->toBe(0);
});

it('fails closed when creating a LeaveAllocation with no authenticated user and no active CompanyContext', function () {
    $company = Company::factory()->create();
    $employee = CompanyContext::runForBootstrap(reason: 'fixture', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $company->id]));

    expect(fn () => LeaveAllocation::factory()->create(['employee_id' => $employee->id]))
        ->toThrow(AuthorizationException::class);
});

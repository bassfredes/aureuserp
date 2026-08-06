<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Calendar;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Services\CompanyContext;
use Webkul\TimeOff\Models\Leave;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('time-off');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

// Employee now enforces HasStrictCompanyId (#138 PR4 A4D employees family):
// creating one needs an authenticated actor or an active CompanyContext.
// withSystemContextIfNoUser() opens a system context only when no actor is
// authenticated yet; every already-authenticated call site in this file
// requests the acting user's own company, so no context is needed there
// (opening one while authenticated is refused outright, ADR 0007).
function leaveEmployeeIn(int $companyId): Employee
{
    return TestBootstrapHelper::withSystemContextIfNoUser(fn () => Employee::factory()->create(['company_id' => $companyId]));
}

// Employee.company_id is NOT NULL in this rollout's write paths, but
// employee_id itself is a mandatory FK on time_off_leaves (restrictOnDelete)
// — a companyless Employee can only exist as corrupted/legacy data,
// simulated here with a raw insert bypassing Eloquent entirely, matching
// the ola4A milestoneCompanylessProjectFixture() precedent.
function leaveCompanylessEmployeeFixture(): Employee
{
    $id = DB::table('employees_employees')->insertGetId([
        'name'       => 'companyless fixture',
        'company_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return Employee::withoutGlobalScope(CompanyScope::class)->findOrFail($id);
}

it('derives Leave.company_id and employee_company_id from the persisted Employee, not the acting user', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $employeeA = Employee::factory()->create(['company_id' => $companyA->id]);

    $leave = Leave::factory()->create(['employee_id' => $employeeA->id]);

    expect($leave->company_id)->toBe($companyA->id)
        ->and($leave->employee_company_id)->toBe($companyA->id);
});

it('forbids creating a Leave under an Employee the acting user is not authorized for', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $employeeB = leaveEmployeeIn($companyB->id);

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => Leave::factory()->create(['employee_id' => $employeeB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('time_off_leaves', ['employee_id' => $employeeB->id]);
});

it('fails closed when creating a Leave whose employee_id does not resolve to any Employee', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => Leave::factory()->create(['employee_id' => 999999]))
        ->toThrow(AuthorizationException::class);
});

it('fails closed when creating a Leave under an Employee that itself has no company', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $companylessEmployee = leaveCompanylessEmployeeFixture();

    expect(fn () => Leave::factory()->create(['employee_id' => $companylessEmployee->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids creating a Leave whose manager belongs to a different company than the Employee', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $managerB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $employeeA = leaveEmployeeIn($companyA->id);

    expect(fn () => Leave::factory()->create(['employee_id' => $employeeA->id, 'manager_id' => $managerB->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids creating a Leave whose Calendar belongs to a different company than the Employee', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $calendarB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Calendar::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $employeeA = leaveEmployeeIn($companyA->id);

    expect(fn () => Leave::factory()->create(['employee_id' => $employeeA->id, 'calendar_id' => $calendarB->id]))
        ->toThrow(AuthorizationException::class);
});

it('allows creating a Leave whose Calendar is a shared Calendar (#138 A4I)', function () {
    $companyA = Company::factory()->create();

    $shared = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Calendar::factory()->create(['company_id' => null]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $employeeA = leaveEmployeeIn($companyA->id);

    $leave = Leave::factory()->create(['employee_id' => $employeeA->id, 'calendar_id' => $shared->id]);

    expect($leave->calendar_id)->toBe($shared->id);
});

it('forbids creating a Leave whose department belongs to a different company than the Employee', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    // Department now enforces HasStrictCompanyId too (#138 PR4 A4D) —
    // created under a system context before authenticating, since the
    // acting user (company A) is never authorized to write company B.
    $departmentB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Department::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $employeeA = leaveEmployeeIn($companyA->id);

    expect(fn () => Leave::factory()->create(['employee_id' => $employeeA->id, 'department_id' => $departmentB->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids a user in company A from updating an unrelated field on a Leave whose Employee is hidden in company B, obtained via an unscoped query', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $employeeB = leaveEmployeeIn($companyB->id);
    $leaveB = CompanyContext::runForAllCompanies(
        reason: 'fixture', caller: __FILE__,
        callback: fn () => Leave::factory()->create(['employee_id' => $employeeB->id]),
    );

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(Leave::find($leaveB->id))->toBeNull();

    $leaveBUnscoped = Leave::withoutGlobalScope(CompanyScope::class)->findOrFail($leaveB->id);

    expect(fn () => $leaveBUnscoped->update(['notes' => 'Renamed by A']))
        ->toThrow(AuthorizationException::class);
});

it('forbids retargeting a Leave hidden in company B to an Employee in company A, obtained via an unscoped query', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $employeeB = leaveEmployeeIn($companyB->id);
    $leaveB = CompanyContext::runForAllCompanies(
        reason: 'fixture', caller: __FILE__,
        callback: fn () => Leave::factory()->create(['employee_id' => $employeeB->id]),
    );

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);
    $employeeA = leaveEmployeeIn($companyA->id);

    $leaveBUnscoped = Leave::withoutGlobalScope(CompanyScope::class)->findOrFail($leaveB->id);

    expect(fn () => $leaveBUnscoped->update(['employee_id' => $employeeA->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('time_off_leaves', ['id' => $leaveB->id, 'employee_id' => $employeeB->id]);
});

it('allows reassigning a Leave between two Employees in the same authorized company', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $employeeA1 = leaveEmployeeIn($companyA->id);
    $employeeA2 = leaveEmployeeIn($companyA->id);
    $leave = Leave::factory()->create(['employee_id' => $employeeA1->id]);

    $leave->update(['employee_id' => $employeeA2->id]);

    expect($leave->fresh()->employee_id)->toBe($employeeA2->id);
});

it('lets a user see only Leaves whose Employee is in their allowed companies', function () {
    $companyA = Company::factory()->create();
    $companyC = Company::factory()->create();

    $employeeA = leaveEmployeeIn($companyA->id);
    $employeeC = leaveEmployeeIn($companyC->id);

    $leaveA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Leave::factory()->create(['employee_id' => $employeeA->id]));
    $leaveC = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Leave::factory()->create(['employee_id' => $employeeC->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->syncWithoutDetaching([$companyA->id]);
    test()->actingAs($user);

    $visibleIds = Leave::query()->pluck('id');

    expect($visibleIds)->toContain($leaveA->id)
        ->not->toContain($leaveC->id);
});

it('shows an authenticated user with no allowed companies an empty Leave list', function () {
    $company = Company::factory()->create();
    $employee = leaveEmployeeIn($company->id);
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Leave::factory()->create(['employee_id' => $employee->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => null]));
    test()->actingAs($user);

    expect(Leave::query()->count())->toBe(0);
});

it('fails closed when creating a Leave with no authenticated user and no active CompanyContext', function () {
    $company = Company::factory()->create();
    $employee = CompanyContext::runForBootstrap(reason: 'fixture', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $company->id]));

    expect(fn () => Leave::factory()->create(['employee_id' => $employee->id]))
        ->toThrow(AuthorizationException::class);
});

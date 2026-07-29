<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeJobPosition;
use Webkul\Employee\Models\WorkLocation;
use Webkul\Partner\Models\Partner;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Calendar;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('employees');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

function employeesActingUser(Company $company): User
{
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id]));
    test()->actingAs($user);

    return $user;
}

// ── tenant-aware FK relations: same-company or reject ────────────────────

it('forbids an Employee.department_id pointing at a Department in a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $departmentB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Department::factory()->create(['company_id' => $companyB->id]));

    employeesActingUser($companyA);

    expect(fn () => Employee::factory()->create(['company_id' => $companyA->id, 'department_id' => $departmentB->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids an Employee.job_id pointing at an EmployeeJobPosition in a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $jobB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => EmployeeJobPosition::factory()->create(['company_id' => $companyB->id]));

    employeesActingUser($companyA);

    expect(fn () => Employee::factory()->create(['company_id' => $companyA->id, 'job_id' => $jobB->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids an Employee.work_location_id pointing at a WorkLocation in a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $workLocationB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => WorkLocation::factory()->create(['company_id' => $companyB->id]));

    employeesActingUser($companyA);

    expect(fn () => Employee::factory()->create(['company_id' => $companyA->id, 'work_location_id' => $workLocationB->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids an Employee.calendar_id pointing at a Calendar in a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $calendarB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Calendar::factory()->create(['company_id' => $companyB->id]));

    employeesActingUser($companyA);

    expect(fn () => Employee::factory()->create(['company_id' => $companyA->id, 'calendar_id' => $calendarB->id]))
        ->toThrow(AuthorizationException::class);
});

it('allows an Employee.calendar_id pointing at a Calendar in the same company', function () {
    $companyA = Company::factory()->create();

    $calendarA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Calendar::factory()->create(['company_id' => $companyA->id]));

    employeesActingUser($companyA);

    $employee = Employee::factory()->create(['company_id' => $companyA->id, 'calendar_id' => $calendarA->id]);

    expect($employee->calendar_id)->toBe($calendarA->id);
});

// ── parent_id/coach_id: self-relations, same company, no self-reference ──

it('forbids an Employee.parent_id pointing at an Employee in a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $managerB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $companyB->id]));

    employeesActingUser($companyA);

    expect(fn () => Employee::factory()->create(['company_id' => $companyA->id, 'parent_id' => $managerB->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids an Employee.coach_id pointing at an Employee in a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $coachB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $companyB->id]));

    employeesActingUser($companyA);

    expect(fn () => Employee::factory()->create(['company_id' => $companyA->id, 'coach_id' => $coachB->id]))
        ->toThrow(AuthorizationException::class);
});

it('allows an Employee.parent_id pointing at an Employee in the same company', function () {
    $companyA = Company::factory()->create();
    employeesActingUser($companyA);

    $managerA = Employee::factory()->create(['company_id' => $companyA->id]);

    $employee = Employee::factory()->create(['company_id' => $companyA->id, 'parent_id' => $managerA->id]);

    expect($employee->parent_id)->toBe($managerA->id);
});

it('forbids an Employee from being its own parent', function () {
    $companyA = Company::factory()->create();
    employeesActingUser($companyA);

    $employee = Employee::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => $employee->update(['parent_id' => $employee->id]))
        ->toThrow(AuthorizationException::class);
});

// ── user_id/attendance_manager_id/leave_manager_id: membership, not equality ──

it('forbids an Employee.user_id referencing a User with no membership in the Employee company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    employeesActingUser($companyA);

    $userB = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyB->id]));

    expect(fn () => Employee::factory()->create(['company_id' => $companyA->id, 'user_id' => $userB->id]))
        ->toThrow(AuthorizationException::class);
});

it('allows an Employee.user_id referencing a User with membership in the Employee company via allowedCompanies, not just default_company_id', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    employeesActingUser($companyA);

    $userB = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyB->id]));
    $userB->allowedCompanies()->syncWithoutDetaching([$companyA->id]);

    $employee = Employee::factory()->create(['company_id' => $companyA->id, 'user_id' => $userB->id]);

    expect($employee->user_id)->toBe($userB->id);
});

it('forbids an Employee.attendance_manager_id referencing a User with no membership in the Employee company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    employeesActingUser($companyA);

    $managerB = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyB->id]));

    expect(fn () => Employee::factory()->create(['company_id' => $companyA->id, 'attendance_manager_id' => $managerB->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids an Employee.leave_manager_id referencing a User with no membership in the Employee company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    employeesActingUser($companyA);

    $managerB = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyB->id]));

    expect(fn () => Employee::factory()->create(['company_id' => $companyA->id, 'leave_manager_id' => $managerB->id]))
        ->toThrow(AuthorizationException::class);
});

// ── Partner sync: create/second-save/update, no recursion, no duplicates ──

it('creates exactly one Partner on a normal Employee create, without infinite recursion', function () {
    $companyA = Company::factory()->create();
    employeesActingUser($companyA);

    $employee = Employee::factory()->create(['company_id' => $companyA->id]);

    expect($employee->partner_id)->not->toBeNull();
    expect(Partner::where('id', $employee->partner_id)->count())->toBe(1);
});

it('updates the same Partner on a subsequent Employee save, without creating a duplicate', function () {
    $companyA = Company::factory()->create();
    employeesActingUser($companyA);

    $employee = Employee::factory()->create(['company_id' => $companyA->id]);
    $originalPartnerId = $employee->partner_id;
    $partnerCountBefore = Partner::count();

    $employee->update(['job_title' => 'Updated Title']);

    expect($employee->fresh()->partner_id)->toBe($originalPartnerId);
    expect(Partner::count())->toBe($partnerCountBefore);
    expect(Partner::find($originalPartnerId)->job_title)->toBe('Updated Title');
});

it('forbids replacing an Employee linked partner_id with an arbitrary different Partner', function () {
    $companyA = Company::factory()->create();
    employeesActingUser($companyA);

    $employee = Employee::factory()->create(['company_id' => $companyA->id]);
    $otherPartner = Partner::factory()->create(['sub_type' => 'employee']);

    expect(fn () => $employee->update(['partner_id' => $otherPartner->id]))
        ->toThrow(AuthorizationException::class);

    expect($employee->fresh()->partner_id)->not->toBe($otherPartner->id);
});

it('forbids clearing an Employee linked partner_id back to null', function () {
    // #138 PR4 A4D review 4811425870, finding 3: clearing an existing
    // partner_id to null used to slip past the immutability check (it
    // only rejected existing-to-different-non-null), and a null
    // partner_id then made handlePartnerCreation() silently create a
    // brand new Partner, replacing the supposedly-immutable link.
    $companyA = Company::factory()->create();
    employeesActingUser($companyA);

    $employee = Employee::factory()->create(['company_id' => $companyA->id]);
    $originalPartnerId = $employee->partner_id;
    $partnerCountBefore = Partner::count();

    expect(fn () => $employee->update(['partner_id' => null]))
        ->toThrow(AuthorizationException::class);

    expect($employee->fresh()->partner_id)->toBe($originalPartnerId);
    expect(Partner::count())->toBe($partnerCountBefore);
});

it('keeps the Partner company synced with the Employee immutable company_id', function () {
    $companyA = Company::factory()->create();
    employeesActingUser($companyA);

    $employee = Employee::factory()->create(['company_id' => $companyA->id]);

    expect(Partner::find($employee->partner_id)->company_id)->toBe($companyA->id);
});

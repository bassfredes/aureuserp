<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeResume;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('employees');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

function employeeResumeEmployeeIn(int $companyId): Employee
{
    return CompanyContext::runForAllCompanies(
        reason: 'fixture',
        caller: __FILE__,
        callback: fn () => Employee::factory()->create(['company_id' => $companyId]),
    );
}

// ── read: parent_scoped via whereHas('employee') ────────────────────────────

it('shows an EmployeeResume of the user\'s own company, not company B\'s', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $employeeA = employeeResumeEmployeeIn($companyA->id);
    $employeeB = employeeResumeEmployeeIn($companyB->id);

    $resumeA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => EmployeeResume::factory()->create(['employee_id' => $employeeA->id]));
    $resumeB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => EmployeeResume::factory()->create(['employee_id' => $employeeB->id]));

    test()->actingAs(User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id])));

    $ids = EmployeeResume::query()->pluck('id');

    expect($ids)->toContain($resumeA->id)
        ->not->toContain($resumeB->id);
});

it('shows nothing to a companyless user', function () {
    $employee = employeeResumeEmployeeIn(Company::factory()->create()->id);
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => EmployeeResume::factory()->create(['employee_id' => $employee->id]));

    test()->actingAs(User::withoutEvents(fn () => User::factory()->create(['default_company_id' => null])));

    expect(EmployeeResume::query()->count())->toBe(0);
});

it('fails closed on EmployeeResume reads with no authenticated user and no active CompanyContext', function () {
    $employee = employeeResumeEmployeeIn(Company::factory()->create()->id);
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => EmployeeResume::factory()->create(['employee_id' => $employee->id]));

    expect(EmployeeResume::query()->count())->toBe(0);
});

// ── write: resolve persisted Employee, authorize its company, reject spoofed/dirty ──

it('allows creating an EmployeeResume under an Employee the acting user is authorized for', function () {
    $company = Company::factory()->create();
    $employee = employeeResumeEmployeeIn($company->id);

    test()->actingAs(User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id])));

    $resume = EmployeeResume::factory()->create(['employee_id' => $employee->id]);

    expect($resume->exists)->toBeTrue();
});

it('forbids creating an EmployeeResume under an Employee the acting user is not authorized for', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $employeeB = employeeResumeEmployeeIn($companyB->id);

    test()->actingAs(User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id])));

    expect(fn () => EmployeeResume::factory()->create(['employee_id' => $employeeB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('employees_employee_resumes', ['employee_id' => $employeeB->id]);
});

it('forbids creating an EmployeeResume with a nonexistent employee_id', function () {
    $company = Company::factory()->create();
    test()->actingAs(User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id])));

    expect(fn () => EmployeeResume::factory()->create(['employee_id' => 999999999]))
        ->toThrow(AuthorizationException::class);
});

it('forbids retargeting an EmployeeResume to an Employee in a different company, leaving the original row intact', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $employeeA = employeeResumeEmployeeIn($companyA->id);
    $employeeB = employeeResumeEmployeeIn($companyB->id);

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->syncWithoutDetaching([$companyB->id]);
    test()->actingAs($user);

    $resume = EmployeeResume::factory()->create(['employee_id' => $employeeA->id]);

    expect(fn () => $resume->update(['employee_id' => $employeeB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('employees_employee_resumes', ['id' => $resume->id, 'employee_id' => $employeeA->id]);
});

it('forbids a user in company A from updating an EmployeeResume of company B obtained via withoutGlobalScope', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $employeeB = employeeResumeEmployeeIn($companyB->id);
    $resumeB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => EmployeeResume::factory()->create(['employee_id' => $employeeB->id]));

    test()->actingAs(User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id])));

    $unscoped = EmployeeResume::withoutGlobalScope('companyViaEmployee')->findOrFail($resumeB->id);

    expect(fn () => $unscoped->update(['name' => 'Renamed']))
        ->toThrow(AuthorizationException::class);
});

it('fails closed when creating an EmployeeResume with no authenticated user and no active CompanyContext', function () {
    $employee = employeeResumeEmployeeIn(Company::factory()->create()->id);

    expect(fn () => EmployeeResume::factory()->create(['employee_id' => $employee->id]))
        ->toThrow(AuthorizationException::class);
});

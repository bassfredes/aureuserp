<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\Role;
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

// ── read isolation: HasCompanyScope (#138 PR4 A4D) ────────────────────────

it('shows a user only Employees in their own company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $employeeA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $companyA->id]));
    $employeeB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $ids = Employee::query()->pluck('id');

    expect($ids)->toContain($employeeA->id)
        ->not->toContain($employeeB->id);
});

it('shows nothing to a user with no allowed companies', function () {
    $company = Company::factory()->create();
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $company->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => null]));
    test()->actingAs($user);

    expect(Employee::query()->count())->toBe(0);
});

it('shows nothing with no authenticated user and no active CompanyContext', function () {
    $company = Company::factory()->create();
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $company->id]));

    expect(Employee::query()->count())->toBe(0);
});

it('throws when an authenticated user is active while a CompanyContext is still open', function () {
    $company = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id]));

    CompanyContext::runForAllCompanies(reason: 'test: simulate an unexpected concurrent actor', caller: __FILE__, callback: function () use ($user) {
        test()->actingAs($user);

        expect(fn () => Employee::query()->count())->toThrow(LogicException::class);
    });
});

// ── write: create/update reauthorize the effective company ───────────────

it('creates an Employee for the actor own explicit company', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $employee = Employee::factory()->create(['company_id' => $companyA->id]);

    expect($employee->company_id)->toBe($companyA->id);
});

it('forbids a user in company A from creating an Employee directly under company B by knowing its id', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => Employee::factory()->create(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('employees_employees', ['company_id' => $companyB->id]);
});

it('forbids a user in company A from updating an unrelated field on an Employee obtained from company B via withoutGlobalScope', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $employeeB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $employeeBUnscoped = Employee::withoutGlobalScope(CompanyScope::class)->findOrFail($employeeB->id);

    expect(fn () => $employeeBUnscoped->update(['job_title' => 'Renamed by A']))
        ->toThrow(AuthorizationException::class);
});

it('forbids changing an Employee company_id, even for a user authorized in both companies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->syncWithoutDetaching([$companyB->id]);
    test()->actingAs($user);

    $employee = Employee::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => $employee->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('employees_employees', ['id' => $employee->id, 'company_id' => $companyA->id]);
});

// ── lifecycle: delete/restore/forceDelete reauthorize the persisted company ──

it('forbids a user in company A from deleting an Employee in company B obtained via withoutGlobalScope', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $employeeB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $employeeBUnscoped = Employee::withoutGlobalScope(CompanyScope::class)->findOrFail($employeeB->id);

    expect(fn () => $employeeBUnscoped->delete())
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('employees_employees', ['id' => $employeeB->id, 'deleted_at' => null]);
});

it('forbids a user in company A from deleting an Employee in company B fetched via a partial column projection', function () {
    // #138 PR4 A4D review 4811425870, finding 1: getOriginal('company_id')
    // silently returns null (not the real value) when the model was
    // fetched via a limited column projection, since the column was never
    // populated on the instance at all — this must not be mistaken for
    // "no company_id yet" and skip authorization.
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $employeeB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $employeeBPartial = Employee::withoutGlobalScope(CompanyScope::class)->select('id')->findOrFail($employeeB->id);

    expect($employeeBPartial->getOriginal('company_id'))->toBeNull();
    expect(fn () => $employeeBPartial->delete())->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('employees_employees', ['id' => $employeeB->id, 'deleted_at' => null]);
});

it('allows a user in company A to delete and restore their own Employee', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $employee = Employee::factory()->create(['company_id' => $companyA->id]);

    $employee->delete();
    $this->assertSoftDeleted('employees_employees', ['id' => $employee->id]);

    $employee->restore();
    $this->assertDatabaseHas('employees_employees', ['id' => $employee->id, 'deleted_at' => null]);
});

it('forbids a user in company A from restoring an Employee soft-deleted in company B, obtained via withoutGlobalScope', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $employeeB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $companyB->id]));

    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Employee::withoutGlobalScope(CompanyScope::class)->findOrFail($employeeB->id)->delete());

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $trashedUnscoped = Employee::withoutGlobalScope(CompanyScope::class)->withTrashed()->findOrFail($employeeB->id);

    expect(fn () => $trashedUnscoped->restore())
        ->toThrow(AuthorizationException::class);

    $this->assertSoftDeleted('employees_employees', ['id' => $employeeB->id]);
});

it('forbids a user in company A from force-deleting an Employee in company B obtained via withoutGlobalScope', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $employeeB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $employeeBUnscoped = Employee::withoutGlobalScope(CompanyScope::class)->findOrFail($employeeB->id);

    expect(fn () => $employeeBUnscoped->forceDelete())
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('employees_employees', ['id' => $employeeB->id]);
});

it('allows a user in company A to force-delete their own Employee', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $employee = Employee::factory()->create(['company_id' => $companyA->id]);

    $employee->forceDelete();

    $this->assertDatabaseMissing('employees_employees', ['id' => $employee->id]);
});

it('leaves the database intact after a rejected cross-company update, delete, and restore', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $employeeB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $companyB->id, 'job_title' => 'Original Title']));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $employeeBUnscoped = Employee::withoutGlobalScope(CompanyScope::class)->findOrFail($employeeB->id);

    expect(fn () => $employeeBUnscoped->update(['job_title' => 'Hacked']))->toThrow(AuthorizationException::class);
    expect(fn () => Employee::withoutGlobalScope(CompanyScope::class)->findOrFail($employeeB->id)->delete())->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('employees_employees', [
        'id'         => $employeeB->id,
        'job_title'  => 'Original Title',
        'deleted_at' => null,
    ]);
});

// ── super_admin follows the same company rules — no generic bypass ───────

it('still forbids a super_admin from writing to a company they are not authorized for', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $superAdmin = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));
    test()->actingAs($superAdmin);

    expect(fn () => Employee::factory()->create(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);
});

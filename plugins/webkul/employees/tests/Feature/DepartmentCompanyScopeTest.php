<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
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

it('shows a user only Departments in their own company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $departmentA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Department::factory()->create(['company_id' => $companyA->id]));
    $departmentB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Department::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $ids = Department::query()->pluck('id');

    expect($ids)->toContain($departmentA->id)
        ->not->toContain($departmentB->id);
});

it('shows nothing with no authenticated user and no active CompanyContext', function () {
    $company = Company::factory()->create();
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Department::factory()->create(['company_id' => $company->id]));

    expect(Department::query()->count())->toBe(0);
});

// ── write: create/update reauthorize the effective company ───────────────

it('forbids a user in company A from creating a Department directly under company B', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => Department::factory()->create(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids changing a Department company_id, even for a user authorized in both companies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->syncWithoutDetaching([$companyB->id]);
    test()->actingAs($user);

    $department = Department::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => $department->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);
});

// ── lifecycle ──────────────────────────────────────────────────────────

it('forbids a user in company A from deleting a Department in company B obtained via withoutGlobalScope', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $departmentB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Department::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $departmentBUnscoped = Department::withoutGlobalScope(CompanyScope::class)->findOrFail($departmentB->id);

    expect(fn () => $departmentBUnscoped->delete())->toThrow(AuthorizationException::class);
    $this->assertDatabaseHas('employees_departments', ['id' => $departmentB->id, 'deleted_at' => null]);
});

it('allows a user in company A to delete, restore and force-delete their own Department', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $department = Department::factory()->create(['company_id' => $companyA->id]);

    $department->delete();
    $this->assertSoftDeleted('employees_departments', ['id' => $department->id]);

    $department->restore();
    $this->assertDatabaseHas('employees_departments', ['id' => $department->id, 'deleted_at' => null]);

    $department->forceDelete();
    $this->assertDatabaseMissing('employees_departments', ['id' => $department->id]);
});

// ── hierarchy: parent_id/master_department_id/manager_id ──────────────────

it('forbids a Department.parent_id pointing at a Department in a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $parentB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Department::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => Department::factory()->create(['company_id' => $companyA->id, 'parent_id' => $parentB->id]))
        ->toThrow(AuthorizationException::class);
});

it('rejects a Department.parent_id that does not exist at all, rather than silently treating it as no parent', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => Department::factory()->create(['company_id' => $companyA->id, 'parent_id' => 999999]))
        ->toThrow(AuthorizationException::class);
});

it('correctly builds parent_path/complete_name/master_department_id for a valid same-company hierarchy', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $root = Department::factory()->create(['company_id' => $companyA->id, 'name' => 'Root']);
    $child = Department::factory()->create(['company_id' => $companyA->id, 'name' => 'Child', 'parent_id' => $root->id]);
    $grandchild = Department::factory()->create(['company_id' => $companyA->id, 'name' => 'Grandchild', 'parent_id' => $child->id]);

    expect($grandchild->complete_name)->toBe('Root / Child / Grandchild');
    expect($grandchild->master_department_id)->toBe($root->id);
    expect($grandchild->parent_path)->toBe("/{$root->id}/{$child->id}/");
});

it('rejects a direct self-parent Department', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $department = Department::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => $department->update(['parent_id' => $department->id]))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects an indirect Department parent cycle', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $a = Department::factory()->create(['company_id' => $companyA->id]);
    $b = Department::factory()->create(['company_id' => $companyA->id, 'parent_id' => $a->id]);

    expect(fn () => $a->update(['parent_id' => $b->id]))
        ->toThrow(InvalidArgumentException::class);
});

it('forbids a Department.manager_id pointing at an Employee in a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $managerB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => Department::factory()->create(['company_id' => $companyA->id, 'manager_id' => $managerB->id]))
        ->toThrow(AuthorizationException::class);
});

it('allows a Department.manager_id pointing at an Employee in the same company', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $managerA = Employee::factory()->create(['company_id' => $companyA->id]);

    $department = Department::factory()->create(['company_id' => $companyA->id, 'manager_id' => $managerA->id]);

    expect($department->manager_id)->toBe($managerA->id);
});

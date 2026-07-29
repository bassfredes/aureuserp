<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeSkill;
use Webkul\Employee\Models\Scopes\EmployeeSkillCompanyScope;
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

// ── read: parent-derived from Employee (#138 PR4 A4D) ─────────────────────

it('shows a user only EmployeeSkills of Employees in their own company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $employeeA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $companyA->id]));
    $employeeB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $companyB->id]));

    $skillA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => EmployeeSkill::create(['employee_id' => $employeeA->id, 'skill_id' => null, 'skill_level_id' => null]));
    $skillB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => EmployeeSkill::create(['employee_id' => $employeeB->id, 'skill_id' => null, 'skill_level_id' => null]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $ids = EmployeeSkill::query()->pluck('id');

    expect($ids)->toContain($skillA->id)
        ->not->toContain($skillB->id);
});

it('shows nothing to a user with no allowed companies', function () {
    $company = Company::factory()->create();
    $employee = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $company->id]));
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => EmployeeSkill::create(['employee_id' => $employee->id, 'skill_id' => null, 'skill_level_id' => null]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => null]));
    test()->actingAs($user);

    expect(EmployeeSkill::query()->count())->toBe(0);
});

it('shows nothing with no authenticated user and no active CompanyContext', function () {
    $company = Company::factory()->create();
    $employee = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $company->id]));
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => EmployeeSkill::create(['employee_id' => $employee->id, 'skill_id' => null, 'skill_level_id' => null]));

    expect(EmployeeSkill::query()->count())->toBe(0);
});

it('shows only the exact company\'s EmployeeSkills under CompanyContext::runForCompany, with no user', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $employeeA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $companyA->id]));
    $employeeB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $companyB->id]));

    $skillA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => EmployeeSkill::create(['employee_id' => $employeeA->id, 'skill_id' => null, 'skill_level_id' => null]));
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => EmployeeSkill::create(['employee_id' => $employeeB->id, 'skill_id' => null, 'skill_level_id' => null]));

    CompanyContext::runForCompany($companyA->id, reason: 'test', caller: __FILE__, callback: function () use ($skillA) {
        $ids = EmployeeSkill::query()->pluck('id');

        expect($ids)->toContain($skillA->id)
            ->and($ids)->toHaveCount(1);
    });
});

it('shows every EmployeeSkill under CompanyContext::runForAllCompanies regardless of company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $employeeA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $companyA->id]));
    $employeeB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $companyB->id]));

    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => EmployeeSkill::create(['employee_id' => $employeeA->id, 'skill_id' => null, 'skill_level_id' => null]));
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => EmployeeSkill::create(['employee_id' => $employeeB->id, 'skill_id' => null, 'skill_level_id' => null]));

    CompanyContext::runForAllCompanies(reason: 'test', caller: __FILE__, callback: function () {
        expect(EmployeeSkill::query()->count())->toBe(2);
    });
});

it('shows every EmployeeSkill under CompanyContext::runForBootstrap regardless of company', function () {
    $company = Company::factory()->create();
    $employee = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $company->id]));
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => EmployeeSkill::create(['employee_id' => $employee->id, 'skill_id' => null, 'skill_level_id' => null]));

    CompanyContext::runForBootstrap(reason: 'test', caller: __FILE__, callback: function () {
        expect(EmployeeSkill::query()->count())->toBe(1);
    });
});

it('throws when an authenticated user is active while a CompanyContext is still open', function () {
    $company = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id]));

    CompanyContext::runForAllCompanies(reason: 'test: simulate an unexpected concurrent actor', caller: __FILE__, callback: function () use ($user) {
        test()->actingAs($user);

        expect(fn () => EmployeeSkill::query()->count())->toThrow(LogicException::class);
    });
});

it('does not let a soft-deleted Employee turn its EmployeeSkills into an accidental cross-company bypass', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $employeeB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $companyB->id]));
    $skillB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => EmployeeSkill::create(['employee_id' => $employeeB->id, 'skill_id' => null, 'skill_level_id' => null]));

    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => $employeeB->delete());

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(EmployeeSkill::query()->pluck('id'))->not->toContain($skillB->id);
});

// ── write: create/update/delete/restore/forceDelete reauthorize the persisted Employee ──

it('forbids creating an EmployeeSkill under an Employee the acting user is not authorized for', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $employeeB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => EmployeeSkill::create(['employee_id' => $employeeB->id, 'skill_id' => null, 'skill_level_id' => null]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('employees_employee_skills', ['employee_id' => $employeeB->id]);
});

it('allows creating an EmployeeSkill under an Employee the acting user is authorized for', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $employeeA = Employee::factory()->create(['company_id' => $companyA->id]);

    $skill = EmployeeSkill::create(['employee_id' => $employeeA->id, 'skill_id' => null, 'skill_level_id' => null]);

    expect($skill->employee_id)->toBe($employeeA->id);
});

it('forbids updating an unrelated field on an EmployeeSkill whose Employee is hidden in another company, obtained via runForAllCompanies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $employeeB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $companyB->id]));
    $skillB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => EmployeeSkill::create(['employee_id' => $employeeB->id, 'skill_id' => null, 'skill_level_id' => null]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $skillBUnscoped = EmployeeSkill::withoutGlobalScope(EmployeeSkillCompanyScope::class)->findOrFail($skillB->id);

    expect(fn () => $skillBUnscoped->update(['creator_id' => $user->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids moving an EmployeeSkill to an Employee in a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $employeeA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $companyA->id]));
    $employeeB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->syncWithoutDetaching([$companyB->id]);
    test()->actingAs($user);

    $skill = EmployeeSkill::create(['employee_id' => $employeeA->id, 'skill_id' => null, 'skill_level_id' => null]);

    expect(fn () => $skill->update(['employee_id' => $employeeB->id]))
        ->toThrow(AuthorizationException::class);
});

it('allows moving an EmployeeSkill between two Employees in the same company', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $employeeA1 = Employee::factory()->create(['company_id' => $companyA->id]);
    $employeeA2 = Employee::factory()->create(['company_id' => $companyA->id]);

    $skill = EmployeeSkill::create(['employee_id' => $employeeA1->id, 'skill_id' => null, 'skill_level_id' => null]);
    $skill->update(['employee_id' => $employeeA2->id]);

    expect($skill->fresh()->employee_id)->toBe($employeeA2->id);
});

it('forbids deleting an EmployeeSkill whose Employee is hidden in another company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $employeeB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $companyB->id]));
    $skillB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => EmployeeSkill::create(['employee_id' => $employeeB->id, 'skill_id' => null, 'skill_level_id' => null]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $skillBUnscoped = EmployeeSkill::withoutGlobalScope(EmployeeSkillCompanyScope::class)->findOrFail($skillB->id);

    expect(fn () => $skillBUnscoped->delete())->toThrow(AuthorizationException::class);
    $this->assertDatabaseHas('employees_employee_skills', ['id' => $skillB->id, 'deleted_at' => null]);
});

it('forbids deleting an EmployeeSkill whose Employee is hidden in another company, fetched via a partial column projection', function () {
    // #138 PR4 A4D review 4811425870, finding 2: getOriginal('employee_id')
    // silently returns null (not the real value) when the model was
    // fetched via a limited column projection, since the column was never
    // populated on the instance at all — this must not be mistaken for
    // "no employee_id" and skip authorization against the persisted Employee.
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $employeeB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $companyB->id]));
    $skillB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => EmployeeSkill::create(['employee_id' => $employeeB->id, 'skill_id' => null, 'skill_level_id' => null]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $skillBPartial = EmployeeSkill::withoutGlobalScope(EmployeeSkillCompanyScope::class)->select('id')->findOrFail($skillB->id);

    expect($skillBPartial->getOriginal('employee_id'))->toBeNull();
    expect(fn () => $skillBPartial->delete())->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('employees_employee_skills', ['id' => $skillB->id, 'deleted_at' => null]);
});

it('allows delete, restore and forceDelete for an EmployeeSkill under the acting user own company', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $employeeA = Employee::factory()->create(['company_id' => $companyA->id]);
    $skill = EmployeeSkill::create(['employee_id' => $employeeA->id, 'skill_id' => null, 'skill_level_id' => null]);

    $skill->delete();
    $this->assertSoftDeleted('employees_employee_skills', ['id' => $skill->id]);

    $skill->restore();
    $this->assertDatabaseHas('employees_employee_skills', ['id' => $skill->id, 'deleted_at' => null]);

    $skill->forceDelete();
    $this->assertDatabaseMissing('employees_employee_skills', ['id' => $skill->id]);
});

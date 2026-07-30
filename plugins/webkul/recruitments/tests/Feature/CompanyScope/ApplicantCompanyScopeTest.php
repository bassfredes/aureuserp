<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('recruitments');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

// ── read isolation: HasCompanyScope (#138 PR4 A4E) ────────────────────────

it('shows a user only Applicants in their own company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $applicantA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Applicant::factory()->create(['company_id' => $companyA->id]));
    $applicantB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Applicant::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $ids = Applicant::query()->pluck('id');

    expect($ids)->toContain($applicantA->id)
        ->not->toContain($applicantB->id);
});

it('shows nothing to a user with no allowed companies', function () {
    $company = Company::factory()->create();
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Applicant::factory()->create(['company_id' => $company->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => null]));
    test()->actingAs($user);

    expect(Applicant::query()->count())->toBe(0);
});

it('shows nothing with no authenticated user and no active CompanyContext', function () {
    $company = Company::factory()->create();
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Applicant::factory()->create(['company_id' => $company->id]));

    expect(Applicant::query()->count())->toBe(0);
});

it('throws when an authenticated user is active while a CompanyContext is still open', function () {
    $company = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id]));

    CompanyContext::runForAllCompanies(reason: 'test: simulate an unexpected concurrent actor', caller: __FILE__, callback: function () use ($user) {
        test()->actingAs($user);

        expect(fn () => Applicant::query()->count())->toThrow(LogicException::class);
    });
});

// ── write: create/update reauthorize the effective company ───────────────

it('creates an Applicant for the actor own explicit company', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $applicant = Applicant::factory()->create(['company_id' => $companyA->id]);

    expect($applicant->company_id)->toBe($companyA->id);
});

it('forbids a user in company A from creating an Applicant directly under company B by knowing its id', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => Applicant::factory()->create(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('recruitments_applicants', ['company_id' => $companyB->id]);
});

it('forbids a user in company A from updating an unrelated field on an Applicant obtained from company B via withoutGlobalScope', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $applicantB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Applicant::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $applicantBUnscoped = Applicant::withoutGlobalScope(CompanyScope::class)->findOrFail($applicantB->id);

    expect(fn () => $applicantBUnscoped->update(['priority' => '9']))
        ->toThrow(AuthorizationException::class);
});

it('forbids changing an Applicant company_id, even for a user authorized in both companies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->syncWithoutDetaching([$companyB->id]);
    test()->actingAs($user);

    $applicant = Applicant::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => $applicant->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('recruitments_applicants', ['id' => $applicant->id, 'company_id' => $companyA->id]);
});

// ── lifecycle: delete/restore/forceDelete reauthorize the persisted company ──

it('forbids a user in company A from deleting an Applicant in company B obtained via withoutGlobalScope', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $applicantB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Applicant::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $applicantBUnscoped = Applicant::withoutGlobalScope(CompanyScope::class)->findOrFail($applicantB->id);

    expect(fn () => $applicantBUnscoped->delete())->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('recruitments_applicants', ['id' => $applicantB->id, 'deleted_at' => null]);
});

it('forbids a user in company A from deleting an Applicant in company B fetched via a partial column projection', function () {
    // #138 PR4 A4D review 4811425870, finding 1, applied here from the
    // start: getOriginal('company_id') silently returns null (not the
    // real value) under a partial column projection — this must not be
    // mistaken for "no company_id yet" and skip authorization.
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $applicantB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Applicant::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $applicantBPartial = Applicant::withoutGlobalScope(CompanyScope::class)->select('id')->findOrFail($applicantB->id);

    expect($applicantBPartial->getOriginal('company_id'))->toBeNull();
    expect(fn () => $applicantBPartial->delete())->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('recruitments_applicants', ['id' => $applicantB->id, 'deleted_at' => null]);
});

it('allows a user in company A to delete and restore their own Applicant', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $applicant = Applicant::factory()->create(['company_id' => $companyA->id]);

    $applicant->delete();
    $this->assertSoftDeleted('recruitments_applicants', ['id' => $applicant->id]);

    $applicant->restore();
    $this->assertDatabaseHas('recruitments_applicants', ['id' => $applicant->id, 'deleted_at' => null]);
});

it('forbids a user in company A from restoring an Applicant soft-deleted in company B, obtained via withoutGlobalScope', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $applicantB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Applicant::factory()->create(['company_id' => $companyB->id]));

    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Applicant::withoutGlobalScope(CompanyScope::class)->findOrFail($applicantB->id)->delete());

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $trashedUnscoped = Applicant::withoutGlobalScope(CompanyScope::class)->withTrashed()->findOrFail($applicantB->id);

    expect(fn () => $trashedUnscoped->restore())->toThrow(AuthorizationException::class);

    $this->assertSoftDeleted('recruitments_applicants', ['id' => $applicantB->id]);
});

it('forbids a user in company A from force-deleting an Applicant in company B obtained via withoutGlobalScope', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $applicantB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Applicant::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $applicantBUnscoped = Applicant::withoutGlobalScope(CompanyScope::class)->findOrFail($applicantB->id);

    expect(fn () => $applicantBUnscoped->forceDelete())->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('recruitments_applicants', ['id' => $applicantB->id]);
});

it('allows a user in company A to force-delete their own Applicant', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $applicant = Applicant::factory()->create(['company_id' => $companyA->id]);

    $applicant->forceDelete();

    $this->assertDatabaseMissing('recruitments_applicants', ['id' => $applicant->id]);
});

it('forbids deleting a historically corrupted Applicant whose company_id is null', function () {
    // #138 PR4 A4D review 4811942781,
    // CHANGES_REQUIRED_A4D_EMPLOYEES_FAIL_CLOSED_OWNER_RESOLUTION, applied
    // here from the start: a null persisted company_id (e.g. after a
    // Company FK onDelete('set null')) must reject the lifecycle
    // mutation, never "unauthorized-but-unblocked".
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $applicant = Applicant::factory()->create(['company_id' => $companyA->id]);

    DB::table('recruitments_applicants')->where('id', $applicant->id)->update(['company_id' => null]);

    $corrupted = Applicant::withoutGlobalScope(CompanyScope::class)->findOrFail($applicant->id);

    expect(fn () => $corrupted->delete())->toThrow(AuthorizationException::class);
    $this->assertDatabaseHas('recruitments_applicants', ['id' => $applicant->id, 'deleted_at' => null]);
});

it('forbids restoring a historically corrupted Applicant whose company_id is null', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $applicant = Applicant::factory()->create(['company_id' => $companyA->id]);

    DB::table('recruitments_applicants')->where('id', $applicant->id)->update(['company_id' => null, 'deleted_at' => now()]);

    $trashed = Applicant::withoutGlobalScope(CompanyScope::class)->withTrashed()->findOrFail($applicant->id);

    expect(fn () => $trashed->restore())->toThrow(AuthorizationException::class);
    $this->assertSoftDeleted('recruitments_applicants', ['id' => $applicant->id]);
});

it('forbids force-deleting a historically corrupted Applicant whose company_id is null', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $applicant = Applicant::factory()->create(['company_id' => $companyA->id]);

    DB::table('recruitments_applicants')->where('id', $applicant->id)->update(['company_id' => null]);

    $corrupted = Applicant::withoutGlobalScope(CompanyScope::class)->findOrFail($applicant->id);

    expect(fn () => $corrupted->forceDelete())->toThrow(AuthorizationException::class);
    $this->assertDatabaseHas('recruitments_applicants', ['id' => $applicant->id]);
});

// ── relations: candidate_id/job_id/department_id/recruiter_id ────────────

it('forbids an Applicant.candidate_id pointing at a Candidate in a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $candidateB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Candidate::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => Applicant::factory()->create(['company_id' => $companyA->id, 'candidate_id' => $candidateB->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids an Applicant.job_id pointing at a JobPosition in a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $jobB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => JobPosition::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => Applicant::factory()->create(['company_id' => $companyA->id, 'job_id' => $jobB->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids an Applicant.department_id pointing at a Department in a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $departmentB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Department::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => Applicant::factory()->create(['company_id' => $companyA->id, 'department_id' => $departmentB->id]))
        ->toThrow(AuthorizationException::class);
});

it('allows an Applicant.department_id pointing at a Department in the same company', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $departmentA = Department::factory()->create(['company_id' => $companyA->id]);

    $applicant = Applicant::factory()->create(['company_id' => $companyA->id, 'department_id' => $departmentA->id]);

    expect($applicant->department_id)->toBe($departmentA->id);
});

it('forbids an Applicant.recruiter_id referencing a User with no membership in the Applicant company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $outsiderUser = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyB->id]));

    expect(fn () => Applicant::factory()->create(['company_id' => $companyA->id, 'recruiter_id' => $outsiderUser->id]))
        ->toThrow(AuthorizationException::class);
});

it('allows an Applicant.recruiter_id referencing a User with membership in the Applicant company via allowedCompanies, not just default_company_id', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $recruiterUser = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyB->id]));
    $recruiterUser->allowedCompanies()->syncWithoutDetaching([$companyA->id]);

    $applicant = Applicant::factory()->create(['company_id' => $companyA->id, 'recruiter_id' => $recruiterUser->id]);

    expect($applicant->recruiter_id)->toBe($recruiterUser->id);
});

// ── createEmployee(): fixed duplicate company_id key + divergence guard ──

it('forbids createEmployee() when the Applicant and its Candidate belong to different companies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    // Membership in both companies, so the corrupted Candidate below stays
    // visible under CompanyScope — the guard under test is the divergence
    // check inside createEmployee(), not read isolation hiding the
    // Candidate entirely (which would return null before ever reaching
    // it, a different code path already covered elsewhere).
    $user->allowedCompanies()->syncWithoutDetaching([$companyB->id]);
    test()->actingAs($user);

    $candidateA = Candidate::factory()->create(['company_id' => $companyA->id]);
    $applicant = Applicant::factory()->create(['company_id' => $companyA->id, 'candidate_id' => $candidateA->id]);

    // Corrupt the candidate's company after the fact (raw update, bypassing
    // model events) to simulate the divergence this guard must catch.
    DB::table('recruitments_candidates')->where('id', $candidateA->id)->update(['company_id' => $companyB->id]);

    expect(fn () => $applicant->fresh()->createEmployee())->toThrow(AuthorizationException::class);
    $this->assertDatabaseMissing('employees_employees', ['work_email' => $candidateA->email_from]);
});

it('createEmployee() succeeds and uses the shared company_id when Applicant and Candidate agree', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $candidateA = Candidate::factory()->create(['company_id' => $companyA->id]);
    $applicant = Applicant::factory()->create(['company_id' => $companyA->id, 'candidate_id' => $candidateA->id]);

    $employee = $applicant->createEmployee();

    expect($employee)->not->toBeNull()
        ->and($employee->company_id)->toBe($companyA->id)
        ->and(Employee::where('company_id', $companyA->id)->where('id', $employee->id)->exists())->toBeTrue();
});

// ── super_admin follows the same company rules — no generic bypass ───────

it('still forbids a super_admin from writing to a company they are not authorized for', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $superAdmin = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));
    test()->actingAs($superAdmin);

    expect(fn () => Applicant::factory()->create(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);
});

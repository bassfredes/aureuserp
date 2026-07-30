<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Webkul\Employee\Models\Employee;
use Webkul\Partner\Models\Partner;
use Webkul\Recruitment\Models\Candidate;
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

it('shows a user only Candidates in their own company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $candidateA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Candidate::factory()->create(['company_id' => $companyA->id]));
    $candidateB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Candidate::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $ids = Candidate::query()->pluck('id');

    expect($ids)->toContain($candidateA->id)
        ->not->toContain($candidateB->id);
});

it('shows nothing to a user with no allowed companies', function () {
    $company = Company::factory()->create();
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Candidate::factory()->create(['company_id' => $company->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => null]));
    test()->actingAs($user);

    expect(Candidate::query()->count())->toBe(0);
});

it('shows nothing with no authenticated user and no active CompanyContext', function () {
    $company = Company::factory()->create();
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Candidate::factory()->create(['company_id' => $company->id]));

    expect(Candidate::query()->count())->toBe(0);
});

it('throws when an authenticated user is active while a CompanyContext is still open', function () {
    $company = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id]));

    CompanyContext::runForAllCompanies(reason: 'test: simulate an unexpected concurrent actor', caller: __FILE__, callback: function () use ($user) {
        test()->actingAs($user);

        expect(fn () => Candidate::query()->count())->toThrow(LogicException::class);
    });
});

// ── write: create/update reauthorize the effective company ───────────────

it('creates a Candidate for the actor own explicit company, auto-creating exactly one Partner', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $candidate = Candidate::factory()->create(['company_id' => $companyA->id]);

    expect($candidate->company_id)->toBe($companyA->id)
        ->and($candidate->partner_id)->not->toBeNull();
});

it('forbids a user in company A from creating a Candidate directly under company B by knowing its id', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => Candidate::factory()->create(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('recruitments_candidates', ['company_id' => $companyB->id]);
});

it('forbids a user in company A from updating an unrelated field on a Candidate obtained from company B via withoutGlobalScope', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $candidateB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Candidate::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $candidateBUnscoped = Candidate::withoutGlobalScope(CompanyScope::class)->findOrFail($candidateB->id);

    expect(fn () => $candidateBUnscoped->update(['priority' => 9]))
        ->toThrow(AuthorizationException::class);
});

it('forbids changing a Candidate company_id, even for a user authorized in both companies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->syncWithoutDetaching([$companyB->id]);
    test()->actingAs($user);

    $candidate = Candidate::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => $candidate->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('recruitments_candidates', ['id' => $candidate->id, 'company_id' => $companyA->id]);
});

// ── lifecycle: delete/restore/forceDelete reauthorize the persisted company ──

it('forbids a user in company A from deleting a Candidate in company B obtained via withoutGlobalScope', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $candidateB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Candidate::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $candidateBUnscoped = Candidate::withoutGlobalScope(CompanyScope::class)->findOrFail($candidateB->id);

    expect(fn () => $candidateBUnscoped->delete())->toThrow(AuthorizationException::class);
    $this->assertDatabaseHas('recruitments_candidates', ['id' => $candidateB->id, 'deleted_at' => null]);
});

it('forbids a user in company A from deleting a Candidate in company B fetched via a partial column projection', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $candidateB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Candidate::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $candidateBPartial = Candidate::withoutGlobalScope(CompanyScope::class)->select('id')->findOrFail($candidateB->id);

    expect($candidateBPartial->getOriginal('company_id'))->toBeNull();
    expect(fn () => $candidateBPartial->delete())->toThrow(AuthorizationException::class);
    $this->assertDatabaseHas('recruitments_candidates', ['id' => $candidateB->id, 'deleted_at' => null]);
});

it('allows a user in company A to delete and restore their own Candidate', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $candidate = Candidate::factory()->create(['company_id' => $companyA->id]);

    $candidate->delete();
    $this->assertSoftDeleted('recruitments_candidates', ['id' => $candidate->id]);

    $candidate->restore();
    $this->assertDatabaseHas('recruitments_candidates', ['id' => $candidate->id, 'deleted_at' => null]);
});

it('forbids a user in company A from restoring a Candidate soft-deleted in company B, obtained via withoutGlobalScope', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $candidateB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Candidate::factory()->create(['company_id' => $companyB->id]));

    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Candidate::withoutGlobalScope(CompanyScope::class)->findOrFail($candidateB->id)->delete());

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $trashedUnscoped = Candidate::withoutGlobalScope(CompanyScope::class)->withTrashed()->findOrFail($candidateB->id);

    expect(fn () => $trashedUnscoped->restore())->toThrow(AuthorizationException::class);
    $this->assertSoftDeleted('recruitments_candidates', ['id' => $candidateB->id]);
});

it('forbids a user in company A from force-deleting a Candidate in company B obtained via withoutGlobalScope', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $candidateB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Candidate::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $candidateBUnscoped = Candidate::withoutGlobalScope(CompanyScope::class)->findOrFail($candidateB->id);

    expect(fn () => $candidateBUnscoped->forceDelete())->toThrow(AuthorizationException::class);
    $this->assertDatabaseHas('recruitments_candidates', ['id' => $candidateB->id]);
});

it('allows a user in company A to force-delete their own Candidate', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $candidate = Candidate::factory()->create(['company_id' => $companyA->id]);

    $candidate->forceDelete();

    $this->assertDatabaseMissing('recruitments_candidates', ['id' => $candidate->id]);
});

it('forbids deleting a historically corrupted Candidate whose company_id is null', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $candidate = Candidate::factory()->create(['company_id' => $companyA->id]);

    DB::table('recruitments_candidates')->where('id', $candidate->id)->update(['company_id' => null]);

    $corrupted = Candidate::withoutGlobalScope(CompanyScope::class)->findOrFail($candidate->id);

    expect(fn () => $corrupted->delete())->toThrow(AuthorizationException::class);
    $this->assertDatabaseHas('recruitments_candidates', ['id' => $candidate->id, 'deleted_at' => null]);
});

it('forbids restoring a historically corrupted Candidate whose company_id is null', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $candidate = Candidate::factory()->create(['company_id' => $companyA->id]);

    DB::table('recruitments_candidates')->where('id', $candidate->id)->update(['company_id' => null, 'deleted_at' => now()]);

    $trashed = Candidate::withoutGlobalScope(CompanyScope::class)->withTrashed()->findOrFail($candidate->id);

    expect(fn () => $trashed->restore())->toThrow(AuthorizationException::class);
    $this->assertSoftDeleted('recruitments_candidates', ['id' => $candidate->id]);
});

it('forbids force-deleting a historically corrupted Candidate whose company_id is null', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $candidate = Candidate::factory()->create(['company_id' => $companyA->id]);

    DB::table('recruitments_candidates')->where('id', $candidate->id)->update(['company_id' => null]);

    $corrupted = Candidate::withoutGlobalScope(CompanyScope::class)->findOrFail($candidate->id);

    expect(fn () => $corrupted->forceDelete())->toThrow(AuthorizationException::class);
    $this->assertDatabaseHas('recruitments_candidates', ['id' => $candidate->id]);
});

// ── relations: manager_id/employee_id ─────────────────────────────────────

it('forbids a Candidate.manager_id referencing a User with no membership in the Candidate company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $outsiderUser = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyB->id]));

    expect(fn () => Candidate::factory()->create(['company_id' => $companyA->id, 'manager_id' => $outsiderUser->id]))
        ->toThrow(AuthorizationException::class);
});

it('allows a Candidate.manager_id referencing a User with membership in the Candidate company', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $managerUser = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));

    $candidate = Candidate::factory()->create(['company_id' => $companyA->id, 'manager_id' => $managerUser->id]);

    expect($candidate->manager_id)->toBe($managerUser->id);
});

it('forbids a Candidate.employee_id pointing at an Employee in a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $employeeB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Employee::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => Candidate::factory()->create(['company_id' => $companyA->id, 'employee_id' => $employeeB->id]))
        ->toThrow(AuthorizationException::class);
});

it('allows a Candidate.employee_id pointing at an Employee in the same company', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $employeeA = Employee::factory()->create(['company_id' => $companyA->id]);

    $candidate = Candidate::factory()->create(['company_id' => $companyA->id, 'employee_id' => $employeeA->id]);

    expect($candidate->employee_id)->toBe($employeeA->id);
});

// ── partner_id: model-managed, immutable once linked (#138 PR4 A4D contract) ──

it('forbids creating a Candidate with a pre-existing same-company Partner id, leaving that Partner untouched', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $existingPartner = Partner::factory()->create(['sub_type' => 'partner', 'company_id' => $companyA->id, 'name' => 'Original Name']);

    expect(fn () => Candidate::factory()->create(['company_id' => $companyA->id, 'partner_id' => $existingPartner->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('recruitments_candidates', ['partner_id' => $existingPartner->id]);
    $this->assertDatabaseHas('partners_partners', ['id' => $existingPartner->id, 'name' => 'Original Name']);
});

it('forbids creating a Candidate with a pre-existing cross-company Partner id, leaving that Partner untouched', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $existingPartnerB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Partner::factory()->create(['sub_type' => 'partner', 'company_id' => $companyB->id, 'name' => 'Original Name B']));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => Candidate::factory()->create(['company_id' => $companyA->id, 'partner_id' => $existingPartnerB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('recruitments_candidates', ['partner_id' => $existingPartnerB->id]);
    $this->assertDatabaseHas('partners_partners', ['id' => $existingPartnerB->id, 'name' => 'Original Name B']);
});

it('forbids replacing a Candidate linked partner_id with an arbitrary different Partner', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $candidate = Candidate::factory()->create(['company_id' => $companyA->id]);
    $otherPartner = Partner::factory()->create(['sub_type' => 'partner']);

    expect(fn () => $candidate->update(['partner_id' => $otherPartner->id]))
        ->toThrow(AuthorizationException::class);

    expect($candidate->fresh()->partner_id)->not->toBe($otherPartner->id);
});

it('forbids clearing a Candidate linked partner_id back to null', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $candidate = Candidate::factory()->create(['company_id' => $companyA->id]);
    $originalPartnerId = $candidate->partner_id;

    expect(fn () => $candidate->update(['partner_id' => null]))
        ->toThrow(AuthorizationException::class);

    expect($candidate->fresh()->partner_id)->toBe($originalPartnerId);
});

it('updates the same Partner on a subsequent Candidate save, without creating a duplicate', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $candidate = Candidate::factory()->create(['company_id' => $companyA->id]);
    $originalPartnerId = $candidate->partner_id;
    $partnerCountBefore = Partner::count();

    $candidate->update(['name' => 'Updated Name']);

    expect($candidate->fresh()->partner_id)->toBe($originalPartnerId)
        ->and(Partner::count())->toBe($partnerCountBefore);
});

// ── super_admin follows the same company rules — no generic bypass ───────

it('still forbids a super_admin from writing to a company they are not authorized for', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $superAdmin = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));
    test()->actingAs($superAdmin);

    expect(fn () => Candidate::factory()->create(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);
});

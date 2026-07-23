<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Project\Models\ProjectStage;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('projects');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

// ── read: company_or_shared (IncludesSharedCompanyRows) ──────────────────

it('shows a user their own company ProjectStages plus the shared/global ones', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));

    $stageB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => ProjectStage::factory()->create(['company_id' => $companyB->id]));
    $sharedStage = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => ProjectStage::factory()->create(['company_id' => null]));

    test()->actingAs($user);

    $stageA = ProjectStage::factory()->create(['company_id' => $companyA->id]);

    $visibleIds = ProjectStage::query()->pluck('id');

    expect($visibleIds)->toContain($stageA->id, $sharedStage->id)
        ->not->toContain($stageB->id);
});

it('hides all ProjectStages, including shared ones, from an authenticated user without company access', function () {
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => ProjectStage::factory()->create(['company_id' => null]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => null]));
    test()->actingAs($user);

    expect(ProjectStage::query()->count())->toBe(0);
});

// ── write: create/update own company ──────────────────────────────────────

it('derives a ProjectStage.company_id from the acting user\'s default_company_id when omitted', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $stage = ProjectStage::factory()->create(['company_id' => null]);

    expect($stage->company_id)->toBe($companyA->id);
});

it('forbids a user in company A from creating a ProjectStage directly under company B by knowing its id', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => ProjectStage::factory()->create(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('projects_project_stages', ['company_id' => $companyB->id]);
});

it('forbids changing a ProjectStage\'s company_id, even for a user authorized in both companies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $stage = ProjectStage::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => $stage->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('projects_project_stages', ['id' => $stage->id, 'company_id' => $companyA->id]);
});

it('forbids a user in company A from updating an unrelated field on a ProjectStage obtained from company B via an unscoped query', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $stageB = CompanyContext::runForAllCompanies(
        reason: 'fixture', caller: __FILE__,
        callback: fn () => ProjectStage::factory()->create(['company_id' => $companyB->id]),
    );

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $stageBUnscoped = ProjectStage::withoutGlobalScope(CompanyScope::class)->findOrFail($stageB->id);

    expect(fn () => $stageBUnscoped->update(['name' => 'Renamed by A']))
        ->toThrow(AuthorizationException::class);
});

// ── shared-row mutation guard ──────────────────────────────────────────────

it('forbids a regular authenticated user from creating, modifying, or deleting a shared ProjectStage', function () {
    $shared = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => ProjectStage::factory()->create(['company_id' => null]));

    $company = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id]));
    test()->actingAs($user);

    // An authenticated actor's company_id always gets defaulted in
    // (guardSharedRowMutation only ever blocks EXISTING shared rows from
    // being mutated) — a regular user simply cannot end up with a
    // company_id-null row via this path at all, the same as Route/Location.
    expect(ProjectStage::factory()->create(['company_id' => null])->company_id)->toBe($company->id);

    expect(fn () => $shared->update(['name' => 'Hacked']))
        ->toThrow(AuthorizationException::class);

    expect(fn () => $shared->delete())
        ->toThrow(AuthorizationException::class);
});

it('lets a super_admin modify a shared ProjectStage that a regular user cannot touch', function () {
    $shared = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => ProjectStage::factory()->create(['company_id' => null]));

    $company = Company::factory()->create();
    $superAdmin = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id]));
    $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));
    test()->actingAs($superAdmin);

    $shared->update(['name' => 'Renamed by super_admin']);

    expect($shared->fresh()->name)->toBe('Renamed by super_admin');
});

it('allows a system process (no user, CompanyContext::runForBootstrap) to create a shared ProjectStage', function () {
    $shared = CompanyContext::runForBootstrap(
        reason: 'test: seeder-style shared stage creation', caller: __FILE__,
        callback: fn () => ProjectStage::factory()->create(['company_id' => null]),
    );

    expect($shared->company_id)->toBeNull();
});

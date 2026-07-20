<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Webkul\Project\Models\Milestone;
use Webkul\Project\Models\Project;
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

function milestoneProjectIn(int $companyId): Project
{
    return TestBootstrapHelper::withSystemContextIfNoUser(
        fn () => Project::factory()->create(['company_id' => $companyId]),
    );
}

// Project::HasStrictCompanyId makes company_id mandatory through every
// normal write path, so a companyless Project can only exist as corrupted/
// legacy data — simulated here with a raw insert that bypasses Eloquent
// (and its saving hooks) entirely (#138 PR4 ola4A).
function milestoneCompanylessProjectFixture(): Project
{
    $id = DB::table('projects_projects')->insertGetId([
        'name'       => 'companyless fixture',
        'company_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return Project::withoutGlobalScope(CompanyScope::class)->findOrFail($id);
}

// ── write: resolve persisted Project, authorize its company, reject dirty/spoofed ──

it('allows creating a Milestone under a Project the acting user is authorized for', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $projectA = milestoneProjectIn($companyA->id);

    $milestone = Milestone::factory()->create(['project_id' => $projectA->id]);

    expect($milestone->exists)->toBeTrue();
});

it('forbids creating a Milestone under a Project the acting user is not authorized for', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $projectB = milestoneProjectIn($companyB->id);

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => Milestone::factory()->create(['project_id' => $projectB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('projects_milestones', ['project_id' => $projectB->id]);
});

it('fails closed when creating a Milestone whose project_id does not resolve to any Project', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => Milestone::factory()->create(['project_id' => 999999]))
        ->toThrow(AuthorizationException::class);
});

it('fails closed when creating a Milestone under a Project that itself has no company', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $companylessProject = milestoneCompanylessProjectFixture();

    expect(fn () => Milestone::factory()->create(['project_id' => $companylessProject->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids a user in company A from updating an unrelated field on a Milestone whose Project is hidden in company B, obtained via an unscoped query', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $projectB = milestoneProjectIn($companyB->id);
    $milestoneB = CompanyContext::runForAllCompanies(
        reason: 'fixture', caller: __FILE__,
        callback: fn () => Milestone::factory()->create(['project_id' => $projectB->id]),
    );

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    // CompanyScope hides milestoneB entirely (its Project is not visible).
    expect(Milestone::find($milestoneB->id))->toBeNull();

    $milestoneBUnscoped = Milestone::withoutGlobalScope('companyViaProject')->findOrFail($milestoneB->id);

    expect(fn () => $milestoneBUnscoped->update(['name' => 'Renamed by A']))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('projects_milestones', ['id' => $milestoneB->id, 'name' => 'Renamed by A']);
});

// ── read: whereHas Project bajo CompanyScope ──────────────────────────────

it('lets a user see only Milestones whose Project is in their allowed companies', function () {
    $companyA = Company::factory()->create();
    $companyC = Company::factory()->create();

    $projectA = milestoneProjectIn($companyA->id);
    $projectC = milestoneProjectIn($companyC->id);

    $milestoneA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Milestone::factory()->create(['project_id' => $projectA->id]));
    $milestoneC = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Milestone::factory()->create(['project_id' => $projectC->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->syncWithoutDetaching([$companyA->id]);
    test()->actingAs($user);

    $visibleIds = Milestone::query()->pluck('id');

    expect($visibleIds)->toContain($milestoneA->id)
        ->not->toContain($milestoneC->id);
});

it('shows an authenticated user with no allowed companies an empty Milestone list', function () {
    $company = Company::factory()->create();
    $project = milestoneProjectIn($company->id);
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Milestone::factory()->create(['project_id' => $project->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => null]));
    test()->actingAs($user);

    expect(Milestone::query()->count())->toBe(0);
});

it('fails closed when creating a Milestone with no authenticated user and no active CompanyContext', function () {
    $company = Company::factory()->create();
    $project = CompanyContext::runForBootstrap(reason: 'fixture', caller: __FILE__, callback: fn () => Project::factory()->create(['company_id' => $company->id]));

    expect(fn () => Milestone::factory()->create(['project_id' => $project->id]))
        ->toThrow(AuthorizationException::class);
});

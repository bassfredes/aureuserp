<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Webkul\Project\Models\Project;
use Webkul\Project\Models\TaskStage;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('projects');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

// Every fixture is built before the restricted actingAs() user logs in —
// see TaskCompanyScopeTest.php for why.
function stageProjectIn(int $companyId): Project
{
    return TestBootstrapHelper::withSystemContextIfNoUser(
        fn () => Project::factory()->create(['company_id' => $companyId]),
    );
}

function companylessProjectFixture(): Project
{
    $id = DB::table('projects_projects')->insertGetId([
        'name'       => 'companyless fixture',
        'company_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return Project::withoutGlobalScope(CompanyScope::class)->findOrFail($id);
}

it('derives a TaskStage\'s company_id from its Project when omitted', function () {
    $companyA = Company::factory()->create();
    $projectA = stageProjectIn($companyA->id);

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $stage = TaskStage::factory()->create(['project_id' => $projectA->id, 'company_id' => null]);

    expect($stage->company_id)->toBe($companyA->id);
});

it('forbids creating a TaskStage whose explicit company_id mismatches its Project\'s company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $projectA = stageProjectIn($companyA->id);

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->syncWithoutDetaching([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    expect(fn () => TaskStage::factory()->create(['project_id' => $projectA->id, 'company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('projects_task_stages', ['project_id' => $projectA->id, 'company_id' => $companyB->id]);
});

it('forbids reassigning a TaskStage to a Project in a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $projectA = stageProjectIn($companyA->id);
    $projectB = stageProjectIn($companyB->id);

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->syncWithoutDetaching([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $stage = TaskStage::factory()->create(['project_id' => $projectA->id, 'company_id' => $companyA->id]);

    expect(fn () => $stage->update(['project_id' => $projectB->id, 'company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('projects_task_stages', ['id' => $stage->id, 'project_id' => $projectA->id, 'company_id' => $companyA->id]);
});

it('fails closed when creating a TaskStage under a Project that itself has no company', function () {
    $companylessProject = companylessProjectFixture();

    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => TaskStage::factory()->create(['project_id' => $companylessProject->id, 'company_id' => null]))
        ->toThrow(AuthorizationException::class);
});

it('forbids a user in company A from updating an unrelated field on a TaskStage obtained from company B via an unscoped query', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $projectB = stageProjectIn($companyB->id);
    $stageB = TestBootstrapHelper::withSystemContextIfNoUser(
        fn () => TaskStage::factory()->create(['project_id' => $projectB->id, 'company_id' => $companyB->id]),
    );

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $stageBUnscoped = TaskStage::withoutGlobalScope(CompanyScope::class)->findOrFail($stageB->id);

    expect(fn () => $stageBUnscoped->update(['name' => 'Renamed by A']))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('projects_task_stages', ['id' => $stageB->id, 'name' => 'Renamed by A']);
});

it('lets a user see only TaskStages in their allowed companies', function () {
    $companyA = Company::factory()->create();
    $companyC = Company::factory()->create();

    $projectA = stageProjectIn($companyA->id);
    $projectC = stageProjectIn($companyC->id);

    $stageA = TestBootstrapHelper::withSystemContextIfNoUser(fn () => TaskStage::factory()->create(['project_id' => $projectA->id, 'company_id' => $companyA->id]));
    $stageC = TestBootstrapHelper::withSystemContextIfNoUser(fn () => TaskStage::factory()->create(['project_id' => $projectC->id, 'company_id' => $companyC->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->syncWithoutDetaching([$companyA->id]);
    test()->actingAs($user);

    $visibleIds = TaskStage::query()->pluck('id');

    expect($visibleIds)->toContain($stageA->id)
        ->not->toContain($stageC->id);
});

it('fails closed when creating a TaskStage with no authenticated user and no active CompanyContext', function () {
    $company = Company::factory()->create();
    $project = stageProjectIn($company->id);

    expect(fn () => TaskStage::factory()->create(['project_id' => $project->id, 'company_id' => $company->id]))
        ->toThrow(AuthorizationException::class);
});

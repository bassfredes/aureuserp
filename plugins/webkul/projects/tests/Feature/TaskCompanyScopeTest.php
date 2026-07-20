<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Webkul\Project\Models\Project;
use Webkul\Project\Models\Task;
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

// Every fixture in this file is built before the restricted actingAs() user
// logs in (matching plugins/webkul/accounts/tests/Feature/RelationIntegrityTest.php's
// established pattern) — building a "foreign company" fixture while an
// unprivileged user is already authenticated would itself be rejected by
// that same user's own write authorization, before the test even reaches
// the behavior under test.
function makeProjectIn(int $companyId): Project
{
    return TestBootstrapHelper::withSystemContextIfNoUser(
        fn () => Project::factory()->create(['company_id' => $companyId]),
    );
}

// stage_id is left null everywhere: TaskFactory's default (TaskStage::factory())
// nests an entirely independent random Project AND an entirely independent
// random Company for the stage — two unrelated fixtures that now trip
// TaskStage's own company/project consistency check the moment either
// Project or TaskStage gains real enforcement. Nothing in this suite
// exercises stage_id, so it stays out of every fixture here.
//
// afterMaking() unsets 'visibility': TaskFactory::definition() carries a
// pre-existing 'visibility' key that projects_tasks has no column for
// (copy-paste from ProjectFactory) — the same workaround already used by
// plugins/webkul/projects/tests/Feature/API/V1/TaskTest.php's
// createTaskRecord(), unrelated to company scope.
function makeTaskFor(int $projectId, ?int $companyId): Task
{
    return Task::factory()
        ->afterMaking(function (Task $task): void {
            unset($task['visibility']);
        })
        ->create([
            'project_id' => $projectId,
            'company_id' => $companyId,
            'stage_id'   => null,
        ]);
}

// Project::HasStrictCompanyId makes company_id mandatory through every
// normal write path, so a companyless Project can only exist as corrupted/
// legacy data — simulated here with a raw insert that bypasses Eloquent
// (and its saving hooks) entirely, to exercise resolveEffectiveCompanyIdOrFail()'s
// own defense-in-depth branch for that state (#138 PR4 ola4A).
function taskCompanylessProjectFixture(): Project
{
    $id = DB::table('projects_projects')->insertGetId([
        'name'       => 'companyless fixture',
        'company_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return Project::withoutGlobalScope(CompanyScope::class)->findOrFail($id);
}

// ── create sin company_id → deriva Project.company_id ───────────────────

it('derives a Task\'s company_id from its Project when omitted', function () {
    $companyA = Company::factory()->create();
    $projectA = makeProjectIn($companyA->id);

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $task = makeTaskFor($projectA->id, null);

    expect($task->company_id)->toBe($companyA->id);
});

// ── create con company_id coincidente → permitir ─────────────────────────

it('allows creating a Task with a company_id that matches its Project', function () {
    $companyA = Company::factory()->create();
    $projectA = makeProjectIn($companyA->id);

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $task = makeTaskFor($projectA->id, $companyA->id);

    expect($task->company_id)->toBe($companyA->id);
});

// ── create/update con mismatch → rechazar ────────────────────────────────

it('forbids creating a Task whose explicit company_id mismatches its Project\'s company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $projectA = makeProjectIn($companyA->id);

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->syncWithoutDetaching([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    expect(fn () => makeTaskFor($projectA->id, $companyB->id))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('projects_tasks', ['project_id' => $projectA->id, 'company_id' => $companyB->id]);
});

it('forbids updating a Task\'s company_id to mismatch its (unchanged) Project', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $projectA = makeProjectIn($companyA->id);

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->syncWithoutDetaching([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $task = makeTaskFor($projectA->id, $companyA->id);

    expect(fn () => $task->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('projects_tasks', ['id' => $task->id, 'company_id' => $companyA->id]);
});

// ── cambio simultáneo de project_id y company_id → rechazar si implica traslado tenant ──

it('forbids reassigning a Task to a Project in a different company, even with a matching explicit company_id', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $projectA = makeProjectIn($companyA->id);
    $projectB = makeProjectIn($companyB->id);

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->syncWithoutDetaching([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $task = makeTaskFor($projectA->id, $companyA->id);

    expect(fn () => $task->update(['project_id' => $projectB->id, 'company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('projects_tasks', ['id' => $task->id, 'project_id' => $projectA->id, 'company_id' => $companyA->id]);
});

it('forbids reassigning a Task\'s project_id alone to a Project in a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $projectA = makeProjectIn($companyA->id);
    $projectB = makeProjectIn($companyB->id);

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->syncWithoutDetaching([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $task = makeTaskFor($projectA->id, $companyA->id);

    expect(fn () => $task->update(['project_id' => $projectB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('projects_tasks', ['id' => $task->id, 'project_id' => $projectA->id]);
});

// ── project inexistente o sin compañía → fallar cerrado ──────────────────

it('fails closed when creating a Task whose project_id does not resolve to any Project', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => makeTaskFor(999999, null))
        ->toThrow(AuthorizationException::class);
});

it('fails closed when creating a Task under a Project that itself has no company', function () {
    $companylessProject = taskCompanylessProjectFixture();

    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => makeTaskFor($companylessProject->id, null))
        ->toThrow(AuthorizationException::class);
});

// ── withoutGlobalScope() must not bypass write authorization ─────────────

it('forbids a user in company A from updating an unrelated field on a Task obtained from company B via an unscoped query', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $projectB = makeProjectIn($companyB->id);
    $taskB = TestBootstrapHelper::withSystemContextIfNoUser(fn () => makeTaskFor($projectB->id, $companyB->id));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $taskBUnscoped = Task::withoutGlobalScope(CompanyScope::class)->findOrFail($taskB->id);

    expect(fn () => $taskBUnscoped->update(['title' => 'Renamed by A']))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('projects_tasks', ['id' => $taskB->id, 'title' => 'Renamed by A']);
});

// ── read isolation ────────────────────────────────────────────────────────

it('lets a user see only Tasks in their allowed companies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $companyC = Company::factory()->create();

    $projectA = makeProjectIn($companyA->id);
    $projectB = makeProjectIn($companyB->id);
    $projectC = makeProjectIn($companyC->id);

    $taskA = TestBootstrapHelper::withSystemContextIfNoUser(fn () => makeTaskFor($projectA->id, $companyA->id));
    $taskB = TestBootstrapHelper::withSystemContextIfNoUser(fn () => makeTaskFor($projectB->id, $companyB->id));
    $taskC = TestBootstrapHelper::withSystemContextIfNoUser(fn () => makeTaskFor($projectC->id, $companyC->id));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->syncWithoutDetaching([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $visibleIds = Task::query()->pluck('id');

    expect($visibleIds)->toContain($taskA->id)
        ->toContain($taskB->id)
        ->not->toContain($taskC->id);
});

it('shows an authenticated user with no allowed companies an empty Task list', function () {
    $company = Company::factory()->create();
    $project = makeProjectIn($company->id);
    TestBootstrapHelper::withSystemContextIfNoUser(fn () => makeTaskFor($project->id, $company->id));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => null]));
    test()->actingAs($user);

    expect(Task::query()->count())->toBe(0);
});

// ── fails closed with no actor/context ───────────────────────────────────

it('fails closed when creating a Task with no authenticated user and no active CompanyContext', function () {
    $company = Company::factory()->create();
    $project = CompanyContext::runForBootstrap(reason: 'fixture', caller: __FILE__, callback: fn () => Project::factory()->create(['company_id' => $company->id]));

    expect(fn () => makeTaskFor($project->id, $company->id))
        ->toThrow(AuthorizationException::class);
});

// ── CompanyContext::runForAllCompanies/runForBootstrap allow valid ops ───

it('allows creating a Task under CompanyContext::runForAllCompanies with an explicit company_id', function () {
    $company = Company::factory()->create();
    $project = CompanyContext::runForBootstrap(reason: 'fixture', caller: __FILE__, callback: fn () => Project::factory()->create(['company_id' => $company->id]));

    $task = CompanyContext::runForAllCompanies(
        reason: 'test: write positive under all_companies', caller: __FILE__,
        callback: fn () => makeTaskFor($project->id, $company->id),
    );

    expect($task->company_id)->toBe($company->id);
});

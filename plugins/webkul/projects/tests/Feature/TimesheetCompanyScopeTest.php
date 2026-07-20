<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Project\Models\Project;
use Webkul\Project\Models\Task;
use Webkul\Project\Models\Timesheet;
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

function timesheetProjectIn(int $companyId): Project
{
    return TestBootstrapHelper::withSystemContextIfNoUser(
        fn () => Project::factory()->create(['company_id' => $companyId]),
    );
}

// stage_id null + afterMaking('visibility' unset) — see
// TaskCompanyScopeTest.php's makeTaskFor() for why both are needed.
function timesheetTaskIn(int $projectId, int $companyId): Task
{
    return TestBootstrapHelper::withSystemContextIfNoUser(
        fn () => Task::factory()
            ->afterMaking(function (Task $task): void {
                unset($task['visibility']);
            })
            ->create(['project_id' => $projectId, 'company_id' => $companyId, 'stage_id' => null]),
    );
}

// Timesheet (Analytic\Models\Record) does not expose project_id/task_id in
// its $fillable — direct property assignment bypasses that restriction,
// same as the rest of this suite's non-factory fixtures.
function makeTimesheet(array $attributes): Timesheet
{
    $timesheet = new Timesheet;

    foreach ($attributes as $key => $value) {
        $timesheet->{$key} = $value;
    }

    $timesheet->save();

    return $timesheet;
}

// ── graph: Timesheet → Task → Project → company must all be compatible ──

it('allows creating a Timesheet under a Task the acting user is authorized for', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $projectA = timesheetProjectIn($companyA->id);
    $taskA = timesheetTaskIn($projectA->id, $companyA->id);

    $timesheet = makeTimesheet(['type' => 'hours', 'unit_amount' => 1, 'task_id' => $taskA->id, 'project_id' => $projectA->id]);

    expect($timesheet->company_id)->toBe($companyA->id);
});

it('derives a Timesheet\'s company_id from its Task when omitted', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $projectA = timesheetProjectIn($companyA->id);
    $taskA = timesheetTaskIn($projectA->id, $companyA->id);

    $timesheet = makeTimesheet(['type' => 'hours', 'unit_amount' => 1, 'task_id' => $taskA->id]);

    expect($timesheet->company_id)->toBe($companyA->id);
});

// ── Timesheet A apuntando a Task B (de otra compañía) → rechazar ─────────

it('forbids creating a Timesheet whose explicit company_id mismatches its Task\'s company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->syncWithoutDetaching([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $projectA = timesheetProjectIn($companyA->id);
    $taskA = timesheetTaskIn($projectA->id, $companyA->id);

    expect(fn () => makeTimesheet(['type' => 'hours', 'unit_amount' => 1, 'task_id' => $taskA->id, 'company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('analytic_records', ['task_id' => $taskA->id, 'company_id' => $companyB->id]);
});

// ── Task A con Project B: Timesheet.project_id inconsistente con Task.project_id → rechazar ──

it('forbids a Timesheet whose project_id does not match its Task\'s project', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $projectA1 = timesheetProjectIn($companyA->id);
    $projectA2 = timesheetProjectIn($companyA->id);
    $taskA = timesheetTaskIn($projectA1->id, $companyA->id);

    expect(fn () => makeTimesheet(['type' => 'hours', 'unit_amount' => 1, 'task_id' => $taskA->id, 'project_id' => $projectA2->id]))
        ->toThrow(AuthorizationException::class);
});

// ── cambio de Task que implique traslado de compañía → rechazar ──────────

it('forbids reassigning a Timesheet\'s task_id to a Task in a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->syncWithoutDetaching([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $projectA = timesheetProjectIn($companyA->id);
    $projectB = timesheetProjectIn($companyB->id);
    $taskA = timesheetTaskIn($projectA->id, $companyA->id);
    $taskB = timesheetTaskIn($projectB->id, $companyB->id);

    $timesheet = makeTimesheet(['type' => 'hours', 'unit_amount' => 1, 'task_id' => $taskA->id, 'company_id' => $companyA->id]);

    expect(fn () => $timesheet->update(['task_id' => $taskB->id, 'company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('analytic_records', ['id' => $timesheet->id, 'task_id' => $taskA->id, 'company_id' => $companyA->id]);
});

// ── objetos dirty en memoria usados para falsificar compañías ────────────

it('forbids a user in company A from updating an unrelated field on a Timesheet obtained from company B via an unscoped query', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $projectB = timesheetProjectIn($companyB->id);
    $taskB = timesheetTaskIn($projectB->id, $companyB->id);
    $timesheetB = TestBootstrapHelper::withSystemContextIfNoUser(
        fn () => makeTimesheet(['type' => 'hours', 'unit_amount' => 1, 'task_id' => $taskB->id, 'company_id' => $companyB->id]),
    );

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $timesheetBUnscoped = Timesheet::withoutGlobalScope(CompanyScope::class)->findOrFail($timesheetB->id);

    expect(fn () => $timesheetBUnscoped->update(['name' => 'Renamed by A']))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('analytic_records', ['id' => $timesheetB->id, 'name' => 'Renamed by A']);
});

// ── operaciones sin actor ni contexto → fail closed ───────────────────────

it('fails closed when creating a Timesheet with no authenticated user and no active CompanyContext', function () {
    $company = Company::factory()->create();
    $project = timesheetProjectIn($company->id);
    $task = timesheetTaskIn($project->id, $company->id);

    expect(fn () => makeTimesheet(['type' => 'hours', 'unit_amount' => 1, 'task_id' => $task->id]))
        ->toThrow(AuthorizationException::class);
});

// ── read isolation ────────────────────────────────────────────────────────

it('lets a user see only Timesheets in their allowed companies', function () {
    $companyA = Company::factory()->create();
    $companyC = Company::factory()->create();

    $projectA = timesheetProjectIn($companyA->id);
    $projectC = timesheetProjectIn($companyC->id);
    $taskA = timesheetTaskIn($projectA->id, $companyA->id);
    $taskC = timesheetTaskIn($projectC->id, $companyC->id);

    $timesheetA = TestBootstrapHelper::withSystemContextIfNoUser(fn () => makeTimesheet(['type' => 'hours', 'unit_amount' => 1, 'task_id' => $taskA->id, 'company_id' => $companyA->id]));
    $timesheetC = TestBootstrapHelper::withSystemContextIfNoUser(fn () => makeTimesheet(['type' => 'hours', 'unit_amount' => 1, 'task_id' => $taskC->id, 'company_id' => $companyC->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->syncWithoutDetaching([$companyA->id]);
    test()->actingAs($user);

    $visibleIds = Timesheet::query()->pluck('id');

    expect($visibleIds)->toContain($timesheetA->id)
        ->not->toContain($timesheetC->id);
});

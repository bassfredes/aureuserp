<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Webkul\Analytic\Models\Record;
use Webkul\Project\Models\Project;
use Webkul\Project\Models\Task;
use Webkul\Project\Models\Timesheet as ProjectTimesheet;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Timesheet\Models\Timesheet as TimesheetPluginTimesheet;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('projects');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

function makeRecordIn(int $companyId): Record
{
    return TestBootstrapHelper::withSystemContextIfNoUser(
        fn () => Record::create(['type' => 'expense', 'name' => 'fixture', 'company_id' => $companyId]),
    );
}

// The physical table owner itself must isolate — not just its Timesheet
// aliases (#138 PR4 ola4A round 2 review).

it('lets a user see only Records in their allowed companies via the physical Record owner', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $recordA = makeRecordIn($companyA->id);
    $recordB = makeRecordIn($companyB->id);

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $visibleIds = Record::query()->pluck('id');

    expect($visibleIds)->toContain($recordA->id)
        ->not->toContain($recordB->id);
});

it('forbids a user in company A from updating an unrelated field on a Record obtained from company B via an unscoped query', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $recordB = makeRecordIn($companyB->id);

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $recordBUnscoped = Record::withoutGlobalScope(CompanyScope::class)->findOrFail($recordB->id);

    expect(fn () => $recordBUnscoped->update(['name' => 'Renamed by A']))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('analytic_records', ['id' => $recordB->id, 'name' => 'Renamed by A']);
});

it('forbids a user in company A from creating a Record directly under company B', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => Record::create(['type' => 'expense', 'name' => 'fixture', 'company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('analytic_records', ['type' => 'expense', 'name' => 'fixture', 'company_id' => $companyB->id]);
});

it('rejects a Timesheet whose Task has a corrupted company_id that no longer matches its Project', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->syncWithoutDetaching([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $project = Project::factory()->create(['company_id' => $companyA->id]);
    $task = Task::factory()
        ->afterMaking(function (Task $task): void {
            unset($task['visibility']);
        })
        ->create(['project_id' => $project->id, 'company_id' => $companyA->id, 'stage_id' => null]);

    // Simulate corrupted/legacy data: Task.company_id no longer matches its
    // own Project's company. A raw update bypasses Task's own saving hook
    // entirely — the only way to reach this state without a real bug
    // elsewhere in the app.
    DB::table('projects_tasks')->where('id', $task->id)->update(['company_id' => $companyB->id]);

    $timesheet = new ProjectTimesheet;
    $timesheet->type = 'hours';
    $timesheet->unit_amount = 1;
    $timesheet->task_id = $task->id;

    expect(fn () => $timesheet->save())
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('analytic_records', ['task_id' => $task->id]);
});

it('applies the same company isolation to both Timesheet alias classes', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $projectA = TestBootstrapHelper::withSystemContextIfNoUser(fn () => Project::factory()->create(['company_id' => $companyA->id]));
    $taskA = TestBootstrapHelper::withSystemContextIfNoUser(fn () => Task::factory()
        ->afterMaking(function (Task $task): void {
            unset($task['visibility']);
        })
        ->create(['project_id' => $projectA->id, 'company_id' => $companyA->id, 'stage_id' => null]));

    $recordB = makeRecordIn($companyB->id);

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $timesheet = new ProjectTimesheet;
    $timesheet->type = 'hours';
    $timesheet->unit_amount = 1;
    $timesheet->task_id = $taskA->id;
    $timesheet->save();

    $visibleViaProjectAlias = ProjectTimesheet::query()->pluck('id');
    $visibleViaTimesheetPluginAlias = TimesheetPluginTimesheet::query()->pluck('id');

    expect($visibleViaProjectAlias)->toContain($timesheet->id)->not->toContain($recordB->id)
        ->and($visibleViaTimesheetPluginAlias)->toContain($timesheet->id)->not->toContain($recordB->id);
});

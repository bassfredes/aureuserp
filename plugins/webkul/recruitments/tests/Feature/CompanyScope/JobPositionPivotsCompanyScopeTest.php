<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Recruitment\Database\Factories\JobPositionFactory;
use Webkul\Recruitment\Database\Factories\StageFactory;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\JobPositionInterviewer;
use Webkul\Recruitment\Models\Scopes\ParentDerivedCompanyScope;
use Webkul\Recruitment\Models\StageJob;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('recruitments');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

// ── JobPositionInterviewer: parent_scoped via JobPosition + membership (#138 PR4 A4E) ──

it('shows a user only JobPositionInterviewer rows of JobPositions in their own company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $jobA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => JobPosition::factory()->create(['company_id' => $companyA->id]));
    $jobB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => JobPosition::factory()->create(['company_id' => $companyB->id]));

    $userA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id])));
    $userB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyB->id])));

    $rowA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => JobPositionInterviewer::create(['job_position_id' => $jobA->id, 'user_id' => $userA->id]));
    $rowB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => JobPositionInterviewer::create(['job_position_id' => $jobB->id, 'user_id' => $userB->id]));

    $actor = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($actor);

    $ids = JobPositionInterviewer::query()->pluck('job_position_id');

    expect($ids)->toContain($rowA->job_position_id)
        ->not->toContain($rowB->job_position_id);
});

it('forbids creating a JobPositionInterviewer whose interviewer has no membership in the JobPosition company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $jobA = JobPosition::factory()->create(['company_id' => $companyA->id]);
    $outsiderUser = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyB->id]));

    expect(fn () => JobPositionInterviewer::create(['job_position_id' => $jobA->id, 'user_id' => $outsiderUser->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids deleting a JobPositionInterviewer whose JobPosition is hidden in another company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $jobB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => JobPosition::factory()->create(['company_id' => $companyB->id]));
    $userB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyB->id])));
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => JobPositionInterviewer::create(['job_position_id' => $jobB->id, 'user_id' => $userB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $rowBUnscoped = JobPositionInterviewer::withoutGlobalScope(ParentDerivedCompanyScope::class)->where('job_position_id', $jobB->id)->firstOrFail();

    expect(fn () => $rowBUnscoped->delete())->toThrow(AuthorizationException::class);
});

it('attaches and detaches an interviewer through the real JobPosition::interviewers() relation, respecting company scope', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    // JobPositionFactory::new() directly, not JobPosition::factory():
    // newFactory() is inherited unmodified from EmployeeJobPosition (the
    // owner), whose own factory hardcodes $model = EmployeeJobPosition::
    // class — JobPosition::factory()->create() would silently return an
    // EmployeeJobPosition instance with no interviewers() method at all
    // (dormant, pre-existing, out of authorized scope to fix on the model
    // itself; matches the ProjectActivityPlan/RecruitmentEmployeeAliases
    // precedent from earlier waves).
    $job = JobPositionFactory::new()->create(['company_id' => $companyA->id]);
    $interviewerUser = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));

    $job->interviewers()->attach($interviewerUser->id);

    $this->assertDatabaseHas('recruitments_job_position_interviewers', [
        'job_position_id' => $job->id,
        'user_id'         => $interviewerUser->id,
    ]);

    $job->interviewers()->detach($interviewerUser->id);

    $this->assertDatabaseMissing('recruitments_job_position_interviewers', [
        'job_position_id' => $job->id,
        'user_id'         => $interviewerUser->id,
    ]);
});

it('forbids attaching an interviewer with no membership in the JobPosition company through the real relation', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $job = JobPositionFactory::new()->create(['company_id' => $companyA->id]);
    $outsiderUser = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyB->id]));

    expect(fn () => $job->interviewers()->attach($outsiderUser->id))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('recruitments_job_position_interviewers', [
        'job_position_id' => $job->id,
        'user_id'         => $outsiderUser->id,
    ]);
});

it('forbids retargeting a JobPositionInterviewer to a different JobPosition', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $job = JobPositionFactory::new()->create(['company_id' => $companyA->id]);
    $otherJob = JobPositionFactory::new()->create(['company_id' => $companyA->id]);
    $interviewerUser = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $row = JobPositionInterviewer::create(['job_position_id' => $job->id, 'user_id' => $interviewerUser->id]);

    expect(fn () => $row->update(['job_position_id' => $otherJob->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('recruitments_job_position_interviewers', ['job_position_id' => $job->id, 'user_id' => $interviewerUser->id]);
    $this->assertDatabaseMissing('recruitments_job_position_interviewers', ['job_position_id' => $otherJob->id, 'user_id' => $interviewerUser->id]);
});

it('forbids retargeting a JobPositionInterviewer to a different user', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $job = JobPositionFactory::new()->create(['company_id' => $companyA->id]);
    $interviewerUser = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $otherInterviewerUser = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $row = JobPositionInterviewer::create(['job_position_id' => $job->id, 'user_id' => $interviewerUser->id]);

    expect(fn () => $row->update(['user_id' => $otherInterviewerUser->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('recruitments_job_position_interviewers', ['job_position_id' => $job->id, 'user_id' => $interviewerUser->id]);
    $this->assertDatabaseMissing('recruitments_job_position_interviewers', ['job_position_id' => $job->id, 'user_id' => $otherInterviewerUser->id]);
});

// ── StageJob: parent_scoped via JobPosition, NOT via the global Stage (#138 PR4 A4E) ──

it('shows a user only StageJob rows of JobPositions in their own company, regardless of the shared global Stage', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $stage = StageFactory::new()->withLegend()->create();
    $jobA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => JobPosition::factory()->create(['company_id' => $companyA->id]));
    $jobB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => JobPosition::factory()->create(['company_id' => $companyB->id]));

    $rowA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => StageJob::create(['stage_id' => $stage->id, 'job_id' => $jobA->id]));
    $rowB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => StageJob::create(['stage_id' => $stage->id, 'job_id' => $jobB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $ids = StageJob::query()->pluck('job_id');

    expect($ids)->toContain($rowA->job_id)
        ->not->toContain($rowB->job_id);
});

it('forbids creating a StageJob under a JobPosition the acting user is not authorized for', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $stage = StageFactory::new()->withLegend()->create();
    $jobB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => JobPosition::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => StageJob::create(['stage_id' => $stage->id, 'job_id' => $jobB->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids deleting a StageJob whose JobPosition is hidden in another company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $stage = StageFactory::new()->withLegend()->create();
    $jobB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => JobPosition::factory()->create(['company_id' => $companyB->id]));
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => StageJob::create(['stage_id' => $stage->id, 'job_id' => $jobB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $rowBUnscoped = StageJob::withoutGlobalScope(ParentDerivedCompanyScope::class)->where('job_id', $jobB->id)->firstOrFail();

    expect(fn () => $rowBUnscoped->delete())->toThrow(AuthorizationException::class);
});

it('attaches and detaches a JobPosition through the real Stage::jobs() relation, respecting company scope', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $stage = StageFactory::new()->withLegend()->create();
    $job = JobPosition::factory()->create(['company_id' => $companyA->id]);

    $stage->jobs()->attach($job->id);

    $this->assertDatabaseHas('recruitments_stages_jobs', [
        'stage_id' => $stage->id,
        'job_id'   => $job->id,
    ]);

    $stage->jobs()->detach($job->id);

    $this->assertDatabaseMissing('recruitments_stages_jobs', [
        'stage_id' => $stage->id,
        'job_id'   => $job->id,
    ]);
});

it('forbids attaching a JobPosition in another company through the real Stage::jobs() relation', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $jobB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => JobPosition::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $stage = StageFactory::new()->withLegend()->create();

    expect(fn () => $stage->jobs()->attach($jobB->id))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('recruitments_stages_jobs', [
        'stage_id' => $stage->id,
        'job_id'   => $jobB->id,
    ]);
});

it('forbids retargeting a StageJob to a different JobPosition', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $stage = StageFactory::new()->withLegend()->create();
    $job = JobPosition::factory()->create(['company_id' => $companyA->id]);
    $otherJob = JobPosition::factory()->create(['company_id' => $companyA->id]);
    $row = StageJob::create(['stage_id' => $stage->id, 'job_id' => $job->id]);

    expect(fn () => $row->update(['job_id' => $otherJob->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('recruitments_stages_jobs', ['stage_id' => $stage->id, 'job_id' => $job->id]);
    $this->assertDatabaseMissing('recruitments_stages_jobs', ['stage_id' => $stage->id, 'job_id' => $otherJob->id]);
});

it('forbids retargeting a StageJob to a different Stage', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $stage = StageFactory::new()->withLegend()->create();
    $otherStage = StageFactory::new()->withLegend()->create();
    $job = JobPosition::factory()->create(['company_id' => $companyA->id]);
    $row = StageJob::create(['stage_id' => $stage->id, 'job_id' => $job->id]);

    expect(fn () => $row->update(['stage_id' => $otherStage->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('recruitments_stages_jobs', ['stage_id' => $stage->id, 'job_id' => $job->id]);
    $this->assertDatabaseMissing('recruitments_stages_jobs', ['stage_id' => $otherStage->id, 'job_id' => $job->id]);
});

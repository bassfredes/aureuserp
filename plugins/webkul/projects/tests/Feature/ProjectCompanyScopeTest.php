<?php

use Illuminate\Auth\Access\AuthorizationException;
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

function makeProject(array $overrides = []): Project
{
    return Project::factory()->create(array_merge(['company_id' => null], $overrides));
}

// ── company_id obligatorio, autorización en create ──────────────────────

it('fails closed when creating a Project with no authenticated user and no active CompanyContext', function () {
    $company = Company::factory()->create();

    expect(fn () => makeProject(['company_id' => $company->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('projects_projects', ['company_id' => $company->id]);
});

it('forbids a user in company A from creating a Project directly under company B by knowing its id', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => makeProject(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('projects_projects', ['company_id' => $companyB->id]);
});

it('derives a Project.company_id from the acting user\'s default_company_id when omitted', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $project = makeProject();

    expect($project->company_id)->toBe($companyA->id);
});

// ── inmutable, reautorización en cada save ───────────────────────────────

it('forbids changing a Project\'s company_id, even for a user authorized in both companies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $project = makeProject(['company_id' => $companyA->id]);

    expect(fn () => $project->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('projects_projects', ['id' => $project->id, 'company_id' => $companyA->id]);
});

it('forbids a user in company A from updating an unrelated field on a Project obtained from company B via an unscoped query', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $projectB = CompanyContext::runForAllCompanies(
        reason: 'test fixture setup — Project in company B',
        caller: __FILE__,
        callback: fn () => makeProject(['company_id' => $companyB->id]),
    );

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $projectBUnscoped = Project::withoutGlobalScope(CompanyScope::class)->findOrFail($projectB->id);

    expect(fn () => $projectBUnscoped->update(['name' => 'Renamed by A']))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('projects_projects', ['id' => $projectB->id, 'name' => 'Renamed by A']);
});

// ── CompanyContext modes ─────────────────────────────────────────────────

it('lets CompanyContext::runForCompany(A) create a Project under A but rejects an explicit company_id for B', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $projectA = CompanyContext::runForCompany(
        $companyA->id,
        reason: 'test: write authorization under a company-mode context',
        caller: __FILE__,
        callback: fn () => makeProject(['company_id' => $companyA->id]),
    );

    expect($projectA->company_id)->toBe($companyA->id);

    expect(fn () => CompanyContext::runForCompany(
        $companyA->id,
        reason: 'test: write authorization under a company-mode context',
        caller: __FILE__,
        callback: fn () => makeProject(['company_id' => $companyB->id]),
    ))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('projects_projects', ['company_id' => $companyB->id]);
});

it('allows creating a Project under CompanyContext::runForAllCompanies with an explicit company_id', function () {
    $company = Company::factory()->create();

    $project = CompanyContext::runForAllCompanies(
        reason: 'test: write positive under all_companies',
        caller: __FILE__,
        callback: fn () => makeProject(['company_id' => $company->id]),
    );

    expect($project->company_id)->toBe($company->id);
});

it('allows creating a Project under CompanyContext::runForBootstrap with an explicit company_id', function () {
    $company = Company::factory()->create();

    $project = CompanyContext::runForBootstrap(
        reason: 'test: write positive under bootstrap',
        caller: __FILE__,
        callback: fn () => makeProject(['company_id' => $company->id]),
    );

    expect($project->company_id)->toBe($company->id);
});

// ── read isolation (HasCompanyScope) ─────────────────────────────────────

it('lets a user see only Projects in their allowed companies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $companyC = Company::factory()->create();

    $projectA = CompanyContext::runForAllCompanies(
        reason: 'fixture', caller: __FILE__,
        callback: fn () => makeProject(['company_id' => $companyA->id]),
    );
    $projectB = CompanyContext::runForAllCompanies(
        reason: 'fixture', caller: __FILE__,
        callback: fn () => makeProject(['company_id' => $companyB->id]),
    );
    $projectC = CompanyContext::runForAllCompanies(
        reason: 'fixture', caller: __FILE__,
        callback: fn () => makeProject(['company_id' => $companyC->id]),
    );

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $visibleIds = Project::query()->pluck('id');

    expect($visibleIds)->toContain($projectA->id)
        ->toContain($projectB->id)
        ->not->toContain($projectC->id);
});

it('shows an authenticated user with no allowed companies an empty Project list', function () {
    Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => null]));
    test()->actingAs($user);

    expect(Project::query()->count())->toBe(0);
});

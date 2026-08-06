<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Maintenance\Models\Team;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('maintenance');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

// ── company_id obligatorio, autorización en create ──────────────────────

it('fails closed when creating a Team with no authenticated user and no active CompanyContext', function () {
    $company = Company::factory()->create();

    expect(fn () => Team::factory()->create(['company_id' => $company->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('maintenance_teams', ['company_id' => $company->id]);
});

it('forbids a user in company A from creating a Team directly under company B by knowing its id', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => Team::factory()->create(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('maintenance_teams', ['company_id' => $companyB->id]);
});

it('derives a Team.company_id from the acting user\'s default_company_id when omitted', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $team = Team::factory()->create(['company_id' => null]);

    expect($team->company_id)->toBe($companyA->id);
});

// ── inmutable, reautorización en cada save ───────────────────────────────

it('forbids changing a Team\'s company_id, even for a user authorized in both companies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $team = Team::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => $team->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('maintenance_teams', ['id' => $team->id, 'company_id' => $companyA->id]);
});

it('forbids a user in company A from updating an unrelated field on a Team obtained from company B via an unscoped query', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $teamB = CompanyContext::runForAllCompanies(
        reason: 'fixture', caller: __FILE__,
        callback: fn () => Team::factory()->create(['company_id' => $companyB->id]),
    );

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $teamBUnscoped = Team::withoutGlobalScope(CompanyScope::class)->findOrFail($teamB->id);

    expect(fn () => $teamBUnscoped->update(['name' => 'Renamed by A']))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('maintenance_teams', ['id' => $teamB->id, 'name' => 'Renamed by A']);
});

// ── read isolation (HasCompanyScope) ─────────────────────────────────────

it('lets a user see only Teams in their allowed companies', function () {
    $companyA = Company::factory()->create();
    $companyC = Company::factory()->create();

    $teamA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Team::factory()->create(['company_id' => $companyA->id]));
    $teamC = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Team::factory()->create(['company_id' => $companyC->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->syncWithoutDetaching([$companyA->id]);
    test()->actingAs($user);

    $visibleIds = Team::query()->pluck('id');

    expect($visibleIds)->toContain($teamA->id)
        ->not->toContain($teamC->id);
});

it('shows an authenticated user with no allowed companies an empty Team list', function () {
    Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => null]));
    test()->actingAs($user);

    expect(Team::query()->count())->toBe(0);
});

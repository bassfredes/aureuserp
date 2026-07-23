<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Project\Models\ActivityPlan as ProjectActivityPlan;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ActivityPlan;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../Helpers/SecurityHelper.php';
require_once __DIR__.'/../Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('projects');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

// ── read: company_or_shared (IncludesSharedCompanyRows) ──────────────────

it('shows a user their own company ActivityPlans plus the shared/global ones', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));

    $planA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => ActivityPlan::factory()->create(['company_id' => $companyA->id]));
    $planB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => ActivityPlan::factory()->create(['company_id' => $companyB->id]));
    $sharedPlan = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => ActivityPlan::factory()->create(['company_id' => null]));

    test()->actingAs($user);

    $visibleIds = ActivityPlan::query()->pluck('id');

    expect($visibleIds)->toContain($planA->id, $sharedPlan->id)
        ->not->toContain($planB->id);
});

it('hides all ActivityPlans, including shared ones, from an authenticated user without company access', function () {
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => ActivityPlan::factory()->create(['company_id' => null]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => null]));
    test()->actingAs($user);

    expect(ActivityPlan::query()->count())->toBe(0);
});

// ── write: create/update own company ──────────────────────────────────────

it('derives an ActivityPlan.company_id from the acting user\'s default_company_id when omitted', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $plan = ActivityPlan::factory()->create(['company_id' => null]);

    expect($plan->company_id)->toBe($companyA->id);
});

it('forbids a user in company A from creating an ActivityPlan directly under company B by knowing its id', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => ActivityPlan::factory()->create(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('activity_plans', ['company_id' => $companyB->id]);
});

it('forbids changing an ActivityPlan\'s company_id, even for a user authorized in both companies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $plan = ActivityPlan::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => $plan->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('activity_plans', ['id' => $plan->id, 'company_id' => $companyA->id]);
});

it('forbids a user in company A from updating an unrelated field on an ActivityPlan obtained from company B via an unscoped query', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $planB = CompanyContext::runForAllCompanies(
        reason: 'fixture', caller: __FILE__,
        callback: fn () => ActivityPlan::factory()->create(['company_id' => $companyB->id]),
    );

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $planBUnscoped = ActivityPlan::withoutGlobalScope(CompanyScope::class)->findOrFail($planB->id);

    expect(fn () => $planBUnscoped->update(['name' => 'Renamed by A']))
        ->toThrow(AuthorizationException::class);
});

// ── shared-row mutation guard ──────────────────────────────────────────────

it('forbids a regular authenticated user from creating, modifying, or deleting a shared ActivityPlan', function () {
    $shared = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => ActivityPlan::factory()->create(['company_id' => null]));

    $company = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id]));
    test()->actingAs($user);

    expect(ActivityPlan::factory()->create(['company_id' => null])->company_id)->toBe($company->id);

    expect(fn () => $shared->update(['name' => 'Hacked']))
        ->toThrow(AuthorizationException::class);

    expect(fn () => $shared->delete())
        ->toThrow(AuthorizationException::class);
});

it('lets a super_admin modify a shared ActivityPlan that a regular user cannot touch', function () {
    $shared = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => ActivityPlan::factory()->create(['company_id' => null]));

    $company = Company::factory()->create();
    $superAdmin = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id]));
    $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));
    test()->actingAs($superAdmin);

    $shared->update(['name' => 'Renamed by super_admin']);

    expect($shared->fresh()->name)->toBe('Renamed by super_admin');
});

// ── alias propagation: subclass inherits the base's scope automatically ──

it('scopes Project\ActivityPlan (a thin subclass) the same way as its Support base', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    // No dedicated factory exists for this alias (it has no logic of its
    // own) — created directly to test the alias's inherited scope, not the
    // base class's own factory-resolution path.
    $planA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => ProjectActivityPlan::create([
        'name' => 'Plan A', 'plugin' => 'projects', 'is_active' => true, 'company_id' => $companyA->id,
    ]));
    $planB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => ProjectActivityPlan::create([
        'name' => 'Plan B', 'plugin' => 'projects', 'is_active' => true, 'company_id' => $companyB->id,
    ]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $visibleIds = ProjectActivityPlan::query()->pluck('id');

    expect($visibleIds)->toContain($planA->id)
        ->not->toContain($planB->id);
});

<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ActivityPlan;
use Webkul\Support\Models\ActivityPlanTemplate;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../Helpers/SecurityHelper.php';
require_once __DIR__.'/../Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('projects');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

function activityPlanIn(int $companyId): ActivityPlan
{
    return CompanyContext::runForAllCompanies(
        reason: 'fixture', caller: __FILE__,
        callback: fn () => ActivityPlan::factory()->create(['company_id' => $companyId]),
    );
}

it('allows creating an ActivityPlanTemplate under an ActivityPlan the acting user is authorized for', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $planA = ActivityPlan::factory()->create(['company_id' => $companyA->id]);

    $template = ActivityPlanTemplate::factory()->create(['plan_id' => $planA->id]);

    expect($template->exists)->toBeTrue();
});

it('forbids creating an ActivityPlanTemplate under an ActivityPlan the acting user is not authorized for', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $planB = activityPlanIn($companyB->id);

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => ActivityPlanTemplate::factory()->create(['plan_id' => $planB->id]))
        ->toThrow(AuthorizationException::class);
});

it('fails closed when creating an ActivityPlanTemplate whose plan_id does not resolve to any ActivityPlan', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => ActivityPlanTemplate::factory()->create(['plan_id' => 999999]))
        ->toThrow(AuthorizationException::class);
});

it('forbids a regular authenticated user from attaching a template to a shared ActivityPlan', function () {
    $sharedPlan = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => ActivityPlan::factory()->create(['company_id' => null]));

    $company = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id]));
    test()->actingAs($user);

    expect(fn () => ActivityPlanTemplate::factory()->create(['plan_id' => $sharedPlan->id]))
        ->toThrow(AuthorizationException::class);
});

it('lets a super_admin attach a template to a shared ActivityPlan', function () {
    $sharedPlan = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => ActivityPlan::factory()->create(['company_id' => null]));

    $company = Company::factory()->create();
    $superAdmin = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id]));
    $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));
    test()->actingAs($superAdmin);

    $template = ActivityPlanTemplate::factory()->create(['plan_id' => $sharedPlan->id]);

    expect($template->exists)->toBeTrue();
});

it('forbids a user in company A from updating an unrelated field on a Template whose ActivityPlan is hidden in company B, obtained via an unscoped query', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $planB = activityPlanIn($companyB->id);
    $templateB = CompanyContext::runForAllCompanies(
        reason: 'fixture', caller: __FILE__,
        callback: fn () => ActivityPlanTemplate::factory()->create(['plan_id' => $planB->id]),
    );

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(ActivityPlanTemplate::find($templateB->id))->toBeNull();

    $templateBUnscoped = ActivityPlanTemplate::withoutGlobalScope('companyViaActivityPlan')->findOrFail($templateB->id);

    expect(fn () => $templateBUnscoped->update(['summary' => 'Renamed by A']))
        ->toThrow(AuthorizationException::class);
});

it('lets a user see only ActivityPlanTemplates whose ActivityPlan is in their allowed companies', function () {
    $companyA = Company::factory()->create();
    $companyC = Company::factory()->create();

    $planA = activityPlanIn($companyA->id);
    $planC = activityPlanIn($companyC->id);

    $templateA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => ActivityPlanTemplate::factory()->create(['plan_id' => $planA->id]));
    $templateC = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => ActivityPlanTemplate::factory()->create(['plan_id' => $planC->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $visibleIds = ActivityPlanTemplate::query()->pluck('id');

    expect($visibleIds)->toContain($templateA->id)
        ->not->toContain($templateC->id);
});

it('fails closed when creating an ActivityPlanTemplate with no authenticated user and no active CompanyContext', function () {
    $company = Company::factory()->create();
    $plan = CompanyContext::runForBootstrap(reason: 'fixture', caller: __FILE__, callback: fn () => ActivityPlan::factory()->create(['company_id' => $company->id]));

    expect(fn () => ActivityPlanTemplate::factory()->create(['plan_id' => $plan->id]))
        ->toThrow(AuthorizationException::class);
});

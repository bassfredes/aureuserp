<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Services\CompanyContext;
use Webkul\TimeOff\Models\LeaveAccrualPlan;
use Webkul\TimeOff\Models\LeaveType;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('time-off');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

it('fails closed when creating a LeaveAccrualPlan with no authenticated user and no active CompanyContext', function () {
    $company = Company::factory()->create();

    expect(fn () => LeaveAccrualPlan::factory()->create(['company_id' => $company->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids a user in company A from creating a LeaveAccrualPlan directly under company B by knowing its id', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => LeaveAccrualPlan::factory()->create(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids changing a LeaveAccrualPlan\'s company_id, even for a user authorized in both companies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $plan = LeaveAccrualPlan::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => $plan->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids creating a LeaveAccrualPlan whose time_off_type_id belongs to a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $leaveTypeB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => LeaveType::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => LeaveAccrualPlan::factory()->create(['company_id' => $companyA->id, 'time_off_type_id' => $leaveTypeB->id]))
        ->toThrow(AuthorizationException::class);
});

it('allows creating a LeaveAccrualPlan whose time_off_type_id belongs to the same company', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $leaveTypeA = LeaveType::factory()->create(['company_id' => $companyA->id]);

    $plan = LeaveAccrualPlan::factory()->create(['company_id' => $companyA->id, 'time_off_type_id' => $leaveTypeA->id]);

    expect($plan->exists)->toBeTrue();
});

it('forbids a user in company A from updating an unrelated field on a LeaveAccrualPlan obtained from company B via an unscoped query', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $planB = CompanyContext::runForAllCompanies(
        reason: 'fixture', caller: __FILE__,
        callback: fn () => LeaveAccrualPlan::factory()->create(['company_id' => $companyB->id]),
    );

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $planBUnscoped = LeaveAccrualPlan::withoutGlobalScope(CompanyScope::class)->findOrFail($planB->id);

    expect(fn () => $planBUnscoped->update(['name' => 'Renamed by A']))
        ->toThrow(AuthorizationException::class);
});

it('lets a user see only LeaveAccrualPlans in their allowed companies', function () {
    $companyA = Company::factory()->create();
    $companyC = Company::factory()->create();

    $planA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => LeaveAccrualPlan::factory()->create(['company_id' => $companyA->id]));
    $planC = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => LeaveAccrualPlan::factory()->create(['company_id' => $companyC->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->syncWithoutDetaching([$companyA->id]);
    test()->actingAs($user);

    $visibleIds = LeaveAccrualPlan::query()->pluck('id');

    expect($visibleIds)->toContain($planA->id)
        ->not->toContain($planC->id);
});

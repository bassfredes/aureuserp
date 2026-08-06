<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\CompanyContext;
use Webkul\TimeOff\Models\LeaveAccrualLevel;
use Webkul\TimeOff\Models\LeaveAccrualPlan;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('time-off');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

function accrualPlanIn(int $companyId): LeaveAccrualPlan
{
    return CompanyContext::runForAllCompanies(
        reason: 'fixture', caller: __FILE__,
        callback: fn () => LeaveAccrualPlan::factory()->create(['company_id' => $companyId]),
    );
}

it('allows creating a LeaveAccrualLevel under a LeaveAccrualPlan the acting user is authorized for', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $planA = LeaveAccrualPlan::factory()->create(['company_id' => $companyA->id]);

    $level = LeaveAccrualLevel::factory()->create(['accrual_plan_id' => $planA->id]);

    expect($level->exists)->toBeTrue();
});

it('forbids creating a LeaveAccrualLevel under a LeaveAccrualPlan the acting user is not authorized for', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $planB = accrualPlanIn($companyB->id);

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => LeaveAccrualLevel::factory()->create(['accrual_plan_id' => $planB->id]))
        ->toThrow(AuthorizationException::class);
});

it('fails closed when creating a LeaveAccrualLevel whose accrual_plan_id does not resolve to any LeaveAccrualPlan', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => LeaveAccrualLevel::factory()->create(['accrual_plan_id' => 999999]))
        ->toThrow(AuthorizationException::class);
});

it('lets a user see only LeaveAccrualLevels whose LeaveAccrualPlan is in their allowed companies', function () {
    $companyA = Company::factory()->create();
    $companyC = Company::factory()->create();

    $planA = accrualPlanIn($companyA->id);
    $planC = accrualPlanIn($companyC->id);

    $levelA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => LeaveAccrualLevel::factory()->create(['accrual_plan_id' => $planA->id]));
    $levelC = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => LeaveAccrualLevel::factory()->create(['accrual_plan_id' => $planC->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $visibleIds = LeaveAccrualLevel::query()->pluck('id');

    expect($visibleIds)->toContain($levelA->id)
        ->not->toContain($levelC->id);
});

it('fails closed when creating a LeaveAccrualLevel with no authenticated user and no active CompanyContext', function () {
    $company = Company::factory()->create();
    $plan = CompanyContext::runForBootstrap(reason: 'fixture', caller: __FILE__, callback: fn () => LeaveAccrualPlan::factory()->create(['company_id' => $company->id]));

    expect(fn () => LeaveAccrualLevel::factory()->create(['accrual_plan_id' => $plan->id]))
        ->toThrow(AuthorizationException::class);
});

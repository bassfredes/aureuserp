<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\CompanyContext;
use Webkul\TimeOff\Models\LeaveType;
use Webkul\TimeOff\Models\UserLeaveType;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('time-off');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

function notifiableLeaveTypeIn(int $companyId): LeaveType
{
    return CompanyContext::runForAllCompanies(
        reason: 'fixture', caller: __FILE__,
        callback: fn () => LeaveType::factory()->create(['company_id' => $companyId]),
    );
}

it('allows notifying a User who belongs to the LeaveType\'s company', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $leaveTypeA = LeaveType::factory()->create(['company_id' => $companyA->id]);
    $officer = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));

    $pivot = UserLeaveType::factory()->create(['leave_type_id' => $leaveTypeA->id, 'user_id' => $officer->id]);

    expect($pivot->exists)->toBeTrue();
});

it('forbids notifying a User who has no access to the LeaveType\'s company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    // Created before authenticating — User itself carries no scope trait,
    // but CompanyContext::run*() refuses to open once an actor is active.
    $officerB = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $leaveTypeA = LeaveType::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => UserLeaveType::factory()->create(['leave_type_id' => $leaveTypeA->id, 'user_id' => $officerB->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids creating a pivot under a LeaveType the acting user is not authorized for', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $leaveTypeB = notifiableLeaveTypeIn($companyB->id);

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => UserLeaveType::factory()->create(['leave_type_id' => $leaveTypeB->id]))
        ->toThrow(AuthorizationException::class);
});

it('lets a user see only pivots whose LeaveType is in their allowed companies', function () {
    $companyA = Company::factory()->create();
    $companyC = Company::factory()->create();

    $leaveTypeA = notifiableLeaveTypeIn($companyA->id);
    $leaveTypeC = notifiableLeaveTypeIn($companyC->id);

    $pivotA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => UserLeaveType::factory()->create(['leave_type_id' => $leaveTypeA->id]));
    $pivotC = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => UserLeaveType::factory()->create(['leave_type_id' => $leaveTypeC->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $visibleIds = UserLeaveType::query()->pluck('leave_type_id');

    expect($visibleIds)->toContain($leaveTypeA->id)
        ->not->toContain($leaveTypeC->id);
});

it('fails closed when creating a pivot with no authenticated user and no active CompanyContext', function () {
    $company = Company::factory()->create();
    $leaveType = CompanyContext::runForBootstrap(reason: 'fixture', caller: __FILE__, callback: fn () => LeaveType::factory()->create(['company_id' => $company->id]));

    expect(fn () => UserLeaveType::factory()->create(['leave_type_id' => $leaveType->id]))
        ->toThrow(AuthorizationException::class);
});

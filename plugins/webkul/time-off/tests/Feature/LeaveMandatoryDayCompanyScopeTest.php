<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Services\CompanyContext;
use Webkul\TimeOff\Models\LeaveMandatoryDay;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('time-off');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

it('fails closed when creating a LeaveMandatoryDay with no authenticated user and no active CompanyContext', function () {
    $company = Company::factory()->create();

    expect(fn () => LeaveMandatoryDay::factory()->create(['company_id' => $company->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids a user in company A from creating a LeaveMandatoryDay directly under company B by knowing its id', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => LeaveMandatoryDay::factory()->create(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);
});

it('derives a LeaveMandatoryDay.company_id from the acting user\'s default_company_id when omitted', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $day = LeaveMandatoryDay::factory()->create(['company_id' => null]);

    expect($day->company_id)->toBe($companyA->id);
});

it('forbids changing a LeaveMandatoryDay\'s company_id, even for a user authorized in both companies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $day = LeaveMandatoryDay::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => $day->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids a user in company A from updating an unrelated field on a LeaveMandatoryDay obtained from company B via an unscoped query', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $dayB = CompanyContext::runForAllCompanies(
        reason: 'fixture', caller: __FILE__,
        callback: fn () => LeaveMandatoryDay::factory()->create(['company_id' => $companyB->id]),
    );

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $dayBUnscoped = LeaveMandatoryDay::withoutGlobalScope(CompanyScope::class)->findOrFail($dayB->id);

    expect(fn () => $dayBUnscoped->update(['name' => 'Renamed by A']))
        ->toThrow(AuthorizationException::class);
});

it('lets a user see only LeaveMandatoryDays in their allowed companies', function () {
    $companyA = Company::factory()->create();
    $companyC = Company::factory()->create();

    $dayA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => LeaveMandatoryDay::factory()->create(['company_id' => $companyA->id]));
    $dayC = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => LeaveMandatoryDay::factory()->create(['company_id' => $companyC->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->syncWithoutDetaching([$companyA->id]);
    test()->actingAs($user);

    $visibleIds = LeaveMandatoryDay::query()->pluck('id');

    expect($visibleIds)->toContain($dayA->id)
        ->not->toContain($dayC->id);
});

it('shows an authenticated user with no allowed companies an empty LeaveMandatoryDay list', function () {
    Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => null]));
    test()->actingAs($user);

    expect(LeaveMandatoryDay::query()->count())->toBe(0);
});

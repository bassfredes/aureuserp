<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Employee\Models\Calendar as EmployeeCalendar;
use Webkul\Employee\Models\CalendarAttendance as EmployeeCalendarAttendance;
use Webkul\Employee\Models\CalendarLeave as EmployeeCalendarLeave;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Calendar;
use Webkul\Support\Models\CalendarAttendance;
use Webkul\Support\Models\CalendarLeave;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Services\CompanyContext;
use Webkul\TimeOff\Models\CalendarLeave as TimeOffCalendarLeave;

/**
 * #138 A4I: Calendar (company_or_shared, shared rows super_admin/system-only
 * per CurrencyRate's strict precedent), CalendarAttendance (parent_scoped,
 * ParentDerivedCompanyOrSharedScope), CalendarLeave (strict_company, no
 * shared rows, resource pair validated against a positive whitelist).
 */
require_once __DIR__.'/../Helpers/SecurityHelper.php';
require_once __DIR__.'/../Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensureERPInstalled();
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

function calendarMemberOf(Company $company): User
{
    return User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id]));
}

function calendarFor(?int $companyId): Calendar
{
    return CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Calendar::factory()->create(['company_id' => $companyId]));
}

// ── Calendar: read (company_or_shared) ────────────────────────────────────

it('shows actor A their own Calendars plus shared Calendars, not company B', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $calendarA = calendarFor($companyA->id);
    $calendarB = calendarFor($companyB->id);
    $shared = calendarFor(null);

    test()->actingAs(calendarMemberOf($companyA));

    $ids = Calendar::query()->pluck('id');

    expect($ids)->toContain($calendarA->id, $shared->id)
        ->not->toContain($calendarB->id);
});

it('shows nothing to a Calendar-companyless user, including shared Calendars', function () {
    calendarFor(null);

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => null]));
    test()->actingAs($user);

    expect(Calendar::query()->count())->toBe(0);
});

it('fails closed on Calendar reads with no authenticated user and no active CompanyContext', function () {
    calendarFor(null);

    expect(Calendar::query()->count())->toBe(0);
});

// ── Calendar: write (create/update/delete reauthorize) ────────────────────

it('forbids a user in company A from creating a Calendar directly under company B by knowing its id', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    test()->actingAs(calendarMemberOf($companyA));

    expect(fn () => Calendar::factory()->create(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('calendars', ['company_id' => $companyB->id]);
});

it('forbids a user in company A from updating a Calendar of company B obtained via withoutGlobalScope', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $calendarB = calendarFor($companyB->id);

    test()->actingAs(calendarMemberOf($companyA));

    $unscoped = Calendar::withoutGlobalScope(CompanyScope::class)->findOrFail($calendarB->id);

    expect(fn () => $unscoped->update(['hours_per_day' => 4]))
        ->toThrow(AuthorizationException::class);
});

it('forbids a user in company A from deleting a Calendar of company B obtained via withoutGlobalScope', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $calendarB = calendarFor($companyB->id);

    test()->actingAs(calendarMemberOf($companyA));

    $unscoped = Calendar::withoutGlobalScope(CompanyScope::class)->findOrFail($calendarB->id);

    expect(fn () => $unscoped->delete())->toThrow(AuthorizationException::class);
    $this->assertDatabaseHas('calendars', ['id' => $calendarB->id, 'deleted_at' => null]);
});

// ── Calendar: company_id immutability ──────────────────────────────────────

it('forbids changing a Calendar\'s company_id from A to B', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = calendarMemberOf($companyA);
    $user->allowedCompanies()->syncWithoutDetaching([$companyB->id]);
    test()->actingAs($user);

    $calendar = Calendar::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => $calendar->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('calendars', ['id' => $calendar->id, 'company_id' => $companyA->id]);
});

// ── Calendar: shared-row mutation guard (CurrencyRate's strict precedent) ─

it('forbids a regular authenticated user from modifying or deleting a shared Calendar', function () {
    // Unlike CurrencyRate, Calendar's `creating` hook coalesces an
    // explicit company_id=null into the acting user's own default (same
    // as ActivityPlan) — so an authenticated regular user submitting null
    // never actually reaches a shared row on create at all, only on
    // update/delete of one that already exists (#138 A4I, matches
    // ActivityPlanCompanyScopeTest's own precedent test exactly).
    $shared = calendarFor(null);

    $company = Company::factory()->create();
    test()->actingAs(calendarMemberOf($company));

    expect(fn () => $shared->update(['hours_per_day' => 4]))
        ->toThrow(AuthorizationException::class);

    expect(fn () => $shared->delete())
        ->toThrow(AuthorizationException::class);
});

it('lets a super_admin modify and delete a shared Calendar that a regular user cannot touch', function () {
    $shared = calendarFor(null);

    $company = Company::factory()->create();
    $superAdmin = calendarMemberOf($company);
    $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));
    test()->actingAs($superAdmin);

    $shared->update(['hours_per_day' => 4]);
    expect((float) $shared->fresh()->hours_per_day)->toBe(4.0);

    $shared->delete();
    $this->assertSoftDeleted('calendars', ['id' => $shared->id]);
});

it('forbids creating a shared Calendar with no authenticated user and no active CompanyContext (CurrencyRate-strict, unlike ActivityPlan)', function () {
    expect(fn () => Calendar::factory()->create(['company_id' => null]))
        ->toThrow(AuthorizationException::class);
});

it('lets a no-user CompanyContext::ALL_COMPANIES process create a shared Calendar', function () {
    $shared = CompanyContext::runForAllCompanies(reason: 'test', caller: __FILE__, callback: fn () => Calendar::factory()->create(['company_id' => null]));

    expect($shared->company_id)->toBeNull();
});

it('lets a no-user CompanyContext::BOOTSTRAP process create a shared Calendar', function () {
    $shared = CompanyContext::runForBootstrap(reason: 'test', caller: __FILE__, callback: fn () => Calendar::factory()->create(['company_id' => null]));

    expect($shared->company_id)->toBeNull();
});

// ── Calendar: force-delete blocked while referenced by CalendarLeave ──────

it('forbids force-deleting a Calendar still referenced by a CalendarLeave', function () {
    $company = Company::factory()->create();
    test()->actingAs(calendarMemberOf($company));

    $calendar = Calendar::factory()->create(['company_id' => $company->id]);
    CalendarLeave::factory()->create(['company_id' => $company->id, 'calendar_id' => $calendar->id]);

    $calendar->delete();

    expect(fn () => $calendar->forceDelete())->toThrow(AuthorizationException::class);
    $this->assertSoftDeleted('calendars', ['id' => $calendar->id]);
});

// ── CalendarAttendance: parent_scoped read ─────────────────────────────────

it('shows CalendarAttendances of the user\'s own Calendar plus of a shared Calendar, not company B\'s', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $calendarA = calendarFor($companyA->id);
    $calendarB = calendarFor($companyB->id);
    $shared = calendarFor(null);

    $attendanceA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CalendarAttendance::factory()->create(['calendar_id' => $calendarA->id]));
    $attendanceB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CalendarAttendance::factory()->create(['calendar_id' => $calendarB->id]));
    $attendanceShared = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CalendarAttendance::factory()->create(['calendar_id' => $shared->id]));

    test()->actingAs(calendarMemberOf($companyA));

    $ids = CalendarAttendance::query()->pluck('id');

    expect($ids)->toContain($attendanceA->id, $attendanceShared->id)
        ->not->toContain($attendanceB->id);
});

it('shows nothing to a CalendarAttendance-companyless user, including attendances of a shared Calendar', function () {
    $shared = calendarFor(null);
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CalendarAttendance::factory()->create(['calendar_id' => $shared->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => null]));
    test()->actingAs($user);

    expect(CalendarAttendance::query()->count())->toBe(0);
});

// ── CalendarAttendance: write against a company-owned parent ──────────────

it('allows creating a CalendarAttendance against the acting user\'s own Calendar', function () {
    $company = Company::factory()->create();
    test()->actingAs(calendarMemberOf($company));

    $calendar = Calendar::factory()->create(['company_id' => $company->id]);
    $attendance = CalendarAttendance::factory()->create(['calendar_id' => $calendar->id]);

    expect($attendance->exists)->toBeTrue();
});

it('forbids creating a CalendarAttendance against a Calendar of another company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $calendarB = calendarFor($companyB->id);

    test()->actingAs(calendarMemberOf($companyA));

    expect(fn () => CalendarAttendance::factory()->create(['calendar_id' => $calendarB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('calendar_attendances', ['calendar_id' => $calendarB->id]);
});

it('forbids creating a CalendarAttendance against a nonexistent Calendar', function () {
    $company = Company::factory()->create();
    test()->actingAs(calendarMemberOf($company));

    expect(fn () => CalendarAttendance::factory()->create(['calendar_id' => 999999999]))
        ->toThrow(AuthorizationException::class);
});

// ── CalendarAttendance: write against a shared parent ──────────────────────

it('forbids a regular user from creating or modifying a CalendarAttendance of a shared Calendar', function () {
    $shared = calendarFor(null);
    $attendance = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CalendarAttendance::factory()->create(['calendar_id' => $shared->id]));

    $company = Company::factory()->create();
    test()->actingAs(calendarMemberOf($company));

    expect(fn () => CalendarAttendance::factory()->create(['calendar_id' => $shared->id]))
        ->toThrow(AuthorizationException::class);

    expect(fn () => $attendance->update(['name' => 'Renamed']))
        ->toThrow(AuthorizationException::class);
});

it('lets a super_admin create and modify a CalendarAttendance of a shared Calendar', function () {
    $shared = calendarFor(null);

    $company = Company::factory()->create();
    $superAdmin = calendarMemberOf($company);
    $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));
    test()->actingAs($superAdmin);

    $attendance = CalendarAttendance::factory()->create(['calendar_id' => $shared->id]);
    expect($attendance->exists)->toBeTrue();

    $attendance->update(['name' => 'Renamed']);
    expect($attendance->fresh()->name)->toBe('Renamed');
});

// ── CalendarAttendance: retargeting forbidden ──────────────────────────────

it('forbids retargeting a CalendarAttendance\'s calendar_id, leaving the original row intact', function () {
    $company = Company::factory()->create();
    test()->actingAs(calendarMemberOf($company));

    $calendarA = Calendar::factory()->create(['company_id' => $company->id]);
    $calendarA2 = Calendar::factory()->create(['company_id' => $company->id]);
    $attendance = CalendarAttendance::factory()->create(['calendar_id' => $calendarA->id]);

    expect(fn () => $attendance->update(['calendar_id' => $calendarA2->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('calendar_attendances', ['id' => $attendance->id, 'calendar_id' => $calendarA->id]);
});

// ── CalendarAttendance: resource fields and display_type whitelists ───────

it('forbids creating a CalendarAttendance with a non-null resource_type/resource_id', function () {
    $company = Company::factory()->create();
    test()->actingAs(calendarMemberOf($company));

    $calendar = Calendar::factory()->create(['company_id' => $company->id]);

    expect(fn () => CalendarAttendance::factory()->create([
        'calendar_id'   => $calendar->id,
        'resource_type' => 'Webkul\\Manufacturing\\Models\\WorkCenter',
        'resource_id'   => 1,
    ]))->toThrow(AuthorizationException::class);
});

it('forbids creating a CalendarAttendance with an invalid display_type', function () {
    $company = Company::factory()->create();
    test()->actingAs(calendarMemberOf($company));

    $calendar = Calendar::factory()->create(['company_id' => $company->id]);

    expect(fn () => CalendarAttendance::factory()->create(['calendar_id' => $calendar->id, 'display_type' => 'daily']))
        ->toThrow(AuthorizationException::class);
});

it('allows creating a CalendarAttendance with each valid display_type value, including null', function () {
    $company = Company::factory()->create();
    test()->actingAs(calendarMemberOf($company));

    $calendar = Calendar::factory()->create(['company_id' => $company->id]);

    foreach ([null, 'working', 'off', 'holiday'] as $displayType) {
        $attendance = CalendarAttendance::factory()->create(['calendar_id' => $calendar->id, 'display_type' => $displayType]);

        expect($attendance->display_type)->toBe($displayType);
    }
});

// ── CalendarLeave: strict_company, no shared rows ──────────────────────────

it('derives CalendarLeave.company_id from a company-owned Calendar', function () {
    $company = Company::factory()->create();
    test()->actingAs(calendarMemberOf($company));

    $calendar = Calendar::factory()->create(['company_id' => $company->id]);
    $leave = CalendarLeave::factory()->create(['calendar_id' => $calendar->id, 'company_id' => null]);

    expect($leave->company_id)->toBe($company->id);
});

it('forbids an explicit CalendarLeave company_id that mismatches its company-owned Calendar', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = calendarMemberOf($companyA);
    $user->allowedCompanies()->syncWithoutDetaching([$companyB->id]);
    test()->actingAs($user);

    $calendarA = Calendar::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => CalendarLeave::factory()->create(['calendar_id' => $calendarA->id, 'company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids a CalendarLeave against a shared Calendar with no explicit company_id', function () {
    $shared = calendarFor(null);
    $company = Company::factory()->create();
    test()->actingAs(calendarMemberOf($company));

    expect(fn () => CalendarLeave::factory()->create(['calendar_id' => $shared->id, 'company_id' => null]))
        ->toThrow(AuthorizationException::class);
});

it('allows a CalendarLeave against a shared Calendar with an explicit, authorized company_id', function () {
    $shared = calendarFor(null);
    $company = Company::factory()->create();
    test()->actingAs(calendarMemberOf($company));

    $leave = CalendarLeave::factory()->create(['calendar_id' => $shared->id, 'company_id' => $company->id]);

    expect($leave->company_id)->toBe($company->id)
        ->and($leave->calendar_id)->toBe($shared->id);
});

it('forbids a CalendarLeave against a shared Calendar with an explicit company_id the actor is not authorized for', function () {
    $shared = calendarFor(null);
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    test()->actingAs(calendarMemberOf($companyA));

    expect(fn () => CalendarLeave::factory()->create(['calendar_id' => $shared->id, 'company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);
});

// ── CalendarLeave: resource pair consistency and type whitelist ───────────

it('forbids a CalendarLeave with resource_type set but resource_id null', function () {
    $company = Company::factory()->create();
    test()->actingAs(calendarMemberOf($company));

    $calendar = Calendar::factory()->create(['company_id' => $company->id]);

    expect(fn () => CalendarLeave::factory()->create([
        'calendar_id'   => $calendar->id,
        'resource_type' => 'Webkul\\Manufacturing\\Models\\WorkCenter',
        'resource_id'   => null,
    ]))->toThrow(AuthorizationException::class);
});

it('forbids a CalendarLeave with resource_id set but resource_type null', function () {
    $company = Company::factory()->create();
    test()->actingAs(calendarMemberOf($company));

    $calendar = Calendar::factory()->create(['company_id' => $company->id]);

    expect(fn () => CalendarLeave::factory()->create([
        'calendar_id'   => $calendar->id,
        'resource_type' => null,
        'resource_id'   => 999,
    ]))->toThrow(AuthorizationException::class);
});

it('forbids a CalendarLeave resource_type outside the WorkCenter-only whitelist', function () {
    $company = Company::factory()->create();
    test()->actingAs(calendarMemberOf($company));

    $calendar = Calendar::factory()->create(['company_id' => $company->id]);

    expect(fn () => CalendarLeave::factory()->create([
        'calendar_id'   => $calendar->id,
        'resource_type' => Calendar::class,
        'resource_id'   => $calendar->id,
    ]))->toThrow(AuthorizationException::class);
});

// ── CalendarLeave: immutability ────────────────────────────────────────────

it('forbids changing a CalendarLeave\'s company_id after creation', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = calendarMemberOf($companyA);
    $user->allowedCompanies()->syncWithoutDetaching([$companyB->id]);
    test()->actingAs($user);

    $calendarA = Calendar::factory()->create(['company_id' => $companyA->id]);
    $leave = CalendarLeave::factory()->create(['calendar_id' => $calendarA->id, 'company_id' => null]);

    expect(fn () => $leave->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids changing a CalendarLeave\'s calendar_id after creation', function () {
    $company = Company::factory()->create();
    test()->actingAs(calendarMemberOf($company));

    $calendarA = Calendar::factory()->create(['company_id' => $company->id]);
    $calendarA2 = Calendar::factory()->create(['company_id' => $company->id]);
    $leave = CalendarLeave::factory()->create(['calendar_id' => $calendarA->id, 'company_id' => null]);

    expect(fn () => $leave->update(['calendar_id' => $calendarA2->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('calendar_leaves', ['id' => $leave->id, 'calendar_id' => $calendarA->id]);
});

it('fails closed when creating a CalendarLeave with no authenticated user and no active CompanyContext', function () {
    $calendar = CompanyContext::runForBootstrap(reason: 'fixture', caller: __FILE__, callback: fn () => Calendar::factory()->create());

    expect(fn () => CalendarLeave::factory()->create(['calendar_id' => $calendar->id, 'company_id' => null]))
        ->toThrow(AuthorizationException::class);
});

// ── Alias classification (#138 A4I) ────────────────────────────────────────

it('resolves the Employee/TimeOff Calendar family aliases to the same scoped/parent_scoped rows as their Support owner', function () {
    $company = Company::factory()->create();
    test()->actingAs(calendarMemberOf($company));

    $calendar = Calendar::factory()->create(['company_id' => $company->id]);
    $attendance = CalendarAttendance::factory()->create(['calendar_id' => $calendar->id]);
    $leave = CalendarLeave::factory()->create(['calendar_id' => $calendar->id, 'company_id' => null]);

    expect(EmployeeCalendar::find($calendar->id))->not->toBeNull();
    expect(EmployeeCalendarAttendance::find($attendance->id))->not->toBeNull();
    expect(EmployeeCalendarLeave::find($leave->id))->not->toBeNull();
    expect(TimeOffCalendarLeave::find($leave->id))->not->toBeNull();
});

it('applies the same cross-company rejection to the Employee Calendar alias as to the Support owner', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $calendarB = calendarFor($companyB->id);

    test()->actingAs(calendarMemberOf($companyA));

    expect(EmployeeCalendar::find($calendarB->id))->toBeNull();
});

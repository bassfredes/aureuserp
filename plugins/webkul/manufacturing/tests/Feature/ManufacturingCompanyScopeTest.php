<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Webkul\Manufacturing\Models\BillOfMaterial;
use Webkul\Manufacturing\Models\WorkCenter;
use Webkul\Product\Models\Product;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Calendar;
use Webkul\Support\Models\CalendarLeave;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('manufacturing');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

// ── WorkCenter (strict_company) ─────────────────────────────────────────────

it('hides WorkCenters from a company the user is not allowed to see', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $userA = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));

    // No acting user yet — WorkCenter's write-authorization check needs a
    // system context for this fixture (#138 review round 2, 2026-07-18).
    [$workCenterA, $workCenterB] = TestBootstrapHelper::withSystemContextIfNoUser(fn () => [
        WorkCenter::factory()->create(['company_id' => $companyA->id]),
        WorkCenter::factory()->create(['company_id' => $companyB->id]),
    ]);

    test()->actingAs($userA);

    expect(WorkCenter::find($workCenterA->id))->not->toBeNull();
    expect(WorkCenter::find($workCenterB->id))->toBeNull();
});

// ── BillOfMaterial (strict_company — D2, no shared/global rows) ────────────

it('hides BillOfMaterials from a company the user is not allowed to see', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $userA = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));

    // No acting user yet — BillOfMaterial's write-authorization check
    // (via resolveEffectiveCompanyIdOrFail()) needs a system context for
    // this fixture (#138 review round 2, 2026-07-18).
    [$bomA, $bomB] = TestBootstrapHelper::withSystemContextIfNoUser(function () use ($companyA, $companyB) {
        $productA = Product::factory()->create(['company_id' => $companyA->id]);
        $productB = Product::factory()->create(['company_id' => $companyB->id]);

        return [
            BillOfMaterial::factory()->create(['company_id' => $companyA->id, 'product_id' => $productA->id]),
            BillOfMaterial::factory()->create(['company_id' => $companyB->id, 'product_id' => $productB->id]),
        ];
    });

    test()->actingAs($userA);

    $visibleIds = BillOfMaterial::query()->pluck('id');

    expect($visibleIds)->toContain($bomA->id);
    expect($visibleIds)->not->toContain($bomB->id);
});

it('forbids creating a BillOfMaterial for a Product that has no company of its own (D2: strict_company, company_id NULL is never persisted)', function () {
    $company = Company::factory()->create();

    $productWithNoCompany = CompanyContext::runForAllCompanies(
        reason: 'test fixture setup — Product with no company',
        caller: __FILE__,
        callback: fn () => Product::factory()->create(['company_id' => null]),
    );

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id]));
    test()->actingAs($user);

    expect(fn () => BillOfMaterial::factory()->create(['company_id' => null, 'product_id' => $productWithNoCompany->id]))
        ->toThrow(AuthorizationException::class);
});

it('derives BillOfMaterial.company_id from its Product, not the acting user\'s default', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyB->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $productA = Product::factory()->create(['company_id' => $companyA->id]);

    $bom = BillOfMaterial::factory()->create(['company_id' => null, 'product_id' => $productA->id]);

    expect($bom->company_id)->toBe($companyA->id);
});

it('forbids an explicit BillOfMaterial company_id that mismatches its Product\'s company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $productA = Product::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => BillOfMaterial::factory()->create(['company_id' => $companyB->id, 'product_id' => $productA->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids creating a BillOfMaterial when the Product cannot be resolved', function () {
    $company = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id]));
    test()->actingAs($user);

    expect(fn () => BillOfMaterial::factory()->create(['company_id' => null, 'product_id' => 999999999]))
        ->toThrow(AuthorizationException::class);
});

it('lets a super_admin bypass company isolation for BillOfMaterials via forAllCompanies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $superAdmin = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));

    // No acting user yet — same system-context requirement as above
    // (#138 review round 2, 2026-07-18).
    [$bomA, $bomB] = TestBootstrapHelper::withSystemContextIfNoUser(function () use ($companyA, $companyB) {
        $productA = Product::factory()->create(['company_id' => $companyA->id]);
        $productB = Product::factory()->create(['company_id' => $companyB->id]);

        return [
            BillOfMaterial::factory()->create(['company_id' => $companyA->id, 'product_id' => $productA->id]),
            BillOfMaterial::factory()->create(['company_id' => $companyB->id, 'product_id' => $productB->id]),
        ];
    });

    test()->actingAs($superAdmin);

    expect(BillOfMaterial::find($bomB->id))->toBeNull();

    $bypassedIds = BillOfMaterial::forAllCompanies()->pluck('id')->all();

    expect($bypassedIds)->toContain($bomA->id, $bomB->id);
});

// ── WorkCenter.calendar_id: company_or_shared Calendar (#138 A4I) ─────────

it('forbids a WorkCenter.calendar_id pointing at a Calendar in a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $calendarB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Calendar::factory()->create(['company_id' => $companyB->id]));

    test()->actingAs(User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id])));

    expect(fn () => WorkCenter::factory()->create(['company_id' => $companyA->id, 'calendar_id' => $calendarB->id]))
        ->toThrow(AuthorizationException::class);
});

it('allows a WorkCenter.calendar_id pointing at a shared Calendar', function () {
    $companyA = Company::factory()->create();

    $shared = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Calendar::factory()->create(['company_id' => null]));

    test()->actingAs(User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id])));

    $workCenter = WorkCenter::factory()->create(['company_id' => $companyA->id, 'calendar_id' => $shared->id]);

    expect($workCenter->calendar_id)->toBe($shared->id);
});

it('forbids newly assigning a soft-deleted Calendar to a WorkCenter', function () {
    $companyA = Company::factory()->create();

    test()->actingAs(User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id])));

    $calendar = Calendar::factory()->create(['company_id' => $companyA->id]);
    $calendar->delete();

    expect(fn () => WorkCenter::factory()->create(['company_id' => $companyA->id, 'calendar_id' => $calendar->id]))
        ->toThrow(AuthorizationException::class);
});

// ── CalendarLeave against a WorkCenter resource (#138 A4I) ─────────────────

it('derives CalendarLeave.company_id and calendar_id from its WorkCenter resource', function () {
    $companyA = Company::factory()->create();

    test()->actingAs(User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id])));

    $calendar = Calendar::factory()->create(['company_id' => $companyA->id]);
    $workCenter = WorkCenter::factory()->create(['company_id' => $companyA->id, 'calendar_id' => $calendar->id]);

    $leave = CalendarLeave::factory()->create([
        'company_id'    => null,
        'calendar_id'   => null,
        'resource_type' => $workCenter->getMorphClass(),
        'resource_id'   => $workCenter->id,
    ]);

    expect($leave->company_id)->toBe($companyA->id)
        ->and($leave->calendar_id)->toBe($calendar->id);
});

it('forbids a CalendarLeave whose explicit calendar_id mismatches its WorkCenter resource\'s own calendar', function () {
    $companyA = Company::factory()->create();

    test()->actingAs(User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id])));

    $calendar = Calendar::factory()->create(['company_id' => $companyA->id]);
    $otherCalendar = Calendar::factory()->create(['company_id' => $companyA->id]);
    $workCenter = WorkCenter::factory()->create(['company_id' => $companyA->id, 'calendar_id' => $calendar->id]);

    expect(fn () => CalendarLeave::factory()->create([
        'company_id'    => null,
        'calendar_id'   => $otherCalendar->id,
        'resource_type' => $workCenter->getMorphClass(),
        'resource_id'   => $workCenter->id,
    ]))->toThrow(AuthorizationException::class);
});

it('forbids a CalendarLeave resource pointing at a nonexistent or soft-deleted WorkCenter', function () {
    $companyA = Company::factory()->create();

    test()->actingAs(User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id])));

    expect(fn () => CalendarLeave::factory()->create([
        'company_id'    => null,
        'calendar_id'   => null,
        'resource_type' => WorkCenter::class,
        'resource_id'   => 999999999,
    ]))->toThrow(AuthorizationException::class);

    $calendar = Calendar::factory()->create(['company_id' => $companyA->id]);
    $workCenter = WorkCenter::factory()->create(['company_id' => $companyA->id, 'calendar_id' => $calendar->id]);
    $workCenter->delete();

    expect(fn () => CalendarLeave::factory()->create([
        'company_id'    => null,
        'calendar_id'   => null,
        'resource_type' => $workCenter->getMorphClass(),
        'resource_id'   => $workCenter->id,
    ]))->toThrow(AuthorizationException::class);
});

// ── Regression: getLeaveIntervalsBatch no longer leaks across calendars/companies ──

it('no longer lets a CalendarLeave of another calendar/company leak into getLeaveIntervalsBatch\'s anonymous bucket (#138 A4I)', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $calendarA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Calendar::factory()->create(['company_id' => $companyA->id, 'timezone' => 'UTC']));
    $calendarB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Calendar::factory()->create(['company_id' => $companyB->id, 'timezone' => 'UTC']));

    $from = now()->startOfDay();
    $to = $from->clone()->addDays(2);

    // Before #138 A4I, a CalendarLeave with calendar_id NULL matched every
    // calendar's anonymous ($resource === null) bucket regardless of
    // company. Simulate the pre-fix corrupted state with a raw insert —
    // the application can no longer produce it (calendar_id is mandatory
    // and enforced in the `saving` listener), so this is the only way to
    // prove the read side no longer honors it either.
    DB::table('calendar_leaves')->insert([
        'name'        => 'cross-company leak attempt',
        'time_type'   => 'leave',
        'date_from'   => $from,
        'date_to'     => $to,
        'company_id'  => $companyB->id,
        'calendar_id' => null,
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);

    $intervals = $calendarA->getLeaveIntervalsBatch($from, $to);

    expect($intervals[null] ?? collect())->toHaveCount(0);
});

<?php

use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Webkul\Purchase\Models\Order;
use Webkul\Purchase\Models\OrderLine;
use Webkul\Purchase\Models\Requisition;
use Webkul\Purchase\Models\RequisitionLine;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('purchases');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

// ── Order ────────────────────────────────────────────────────────────────────

it('hides purchase orders from companies the user is not allowed to see', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $userA = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $companyA->id,
    ]));

    $orderA = Order::factory()->create(['company_id' => $companyA->id]);
    $orderB = Order::factory()->create(['company_id' => $companyB->id]);

    test()->actingAs($userA);

    expect(Order::find($orderA->id))->not->toBeNull();
    expect(Order::find($orderB->id))->toBeNull();
    expect(Order::pluck('company_id')->all())->not->toContain($companyB->id);
});

it('shows purchase orders from every company explicitly allowed to the user', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $companyA->id,
    ]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);

    $orderA = Order::factory()->create(['company_id' => $companyA->id]);
    $orderB = Order::factory()->create(['company_id' => $companyB->id]);

    test()->actingAs($user);

    expect(Order::query()->pluck('id')->all())
        ->toEqualCanonicalizing([$orderA->id, $orderB->id]);
});

it('hides all purchase orders from an authenticated user without company access', function () {
    $company = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => null,
    ]));

    Order::factory()->create(['company_id' => $company->id]);

    test()->actingAs($user);

    expect(Order::query()->count())->toBe(0);
});

it('fails closed on purchase orders when there is no authenticated user and no system context', function () {
    $company = Company::factory()->create();
    Order::factory()->create(['company_id' => $company->id]);

    Auth::logout();

    expect(Order::query()->count())->toBe(0);
});

it('lets an explicit company system context see exactly that company\'s purchase orders with no authenticated user', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $orderA = Order::factory()->create(['company_id' => $companyA->id]);
    Order::factory()->create(['company_id' => $companyB->id]);

    Auth::logout();

    $visibleIds = CompanyContext::runForCompany(
        $companyA->id,
        reason: 'test: company system context visibility',
        caller: __FILE__,
        callback: fn () => Order::query()->pluck('id'),
    );

    expect($visibleIds)->toContain($orderA->id)
        ->and($visibleIds)->toHaveCount(1);
});

it('lets an explicit all_companies system context see every purchase order with no authenticated user', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    Order::factory()->create(['company_id' => $companyA->id]);
    Order::factory()->create(['company_id' => $companyB->id]);

    Auth::logout();

    $count = CompanyContext::runForAllCompanies(
        reason: 'test: all_companies system context visibility',
        caller: __FILE__,
        callback: fn () => Order::query()->count(),
    );

    expect($count)->toBeGreaterThanOrEqual(2);
});

it('lets a super_admin bypass purchase order company isolation via forAllCompanies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $superAdmin = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $companyA->id,
    ]));
    $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));

    $orderA = Order::factory()->create(['company_id' => $companyA->id]);
    $orderB = Order::factory()->create(['company_id' => $companyB->id]);

    test()->actingAs($superAdmin);

    expect(Order::find($orderB->id))->toBeNull();

    $bypassedIds = Order::forAllCompanies()->pluck('id')->all();

    expect($bypassedIds)->toContain($orderA->id)
        ->and($bypassedIds)->toContain($orderB->id);
});

it('forbids a non-super_admin from bypassing purchase order company isolation', function () {
    $companyA = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $companyA->id,
    ]));

    test()->actingAs($user);

    expect(fn () => Order::forAllCompanies())
        ->toThrow(HttpException::class);
});

// ── OrderLine ────────────────────────────────────────────────────────────────

it('hides purchase order lines from companies the user is not allowed to see', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $userA = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $companyA->id,
    ]));

    // OrderLine::created() reads $this->order (a strict_company relation)
    // to decide whether to trigger inventory move creation — this fixture
    // spans two companies before actingAs() picks one, so it needs the
    // explicit all_companies system context rather than relying on an
    // implicit no-user bypass (ADR 0007).
    [$lineA, $lineB] = CompanyContext::runForAllCompanies(
        reason: 'test fixture setup — cross-company order lines',
        caller: __FILE__,
        callback: function () use ($companyA, $companyB) {
            $orderA = Order::factory()->create(['company_id' => $companyA->id]);
            $orderB = Order::factory()->create(['company_id' => $companyB->id]);

            return [
                OrderLine::factory()->create(['order_id' => $orderA->id, 'company_id' => $companyA->id]),
                OrderLine::factory()->create(['order_id' => $orderB->id, 'company_id' => $companyB->id]),
            ];
        },
    );

    test()->actingAs($userA);

    expect(OrderLine::find($lineA->id))->not->toBeNull();
    expect(OrderLine::find($lineB->id))->toBeNull();
});

it('hides all purchase order lines from an authenticated user without company access', function () {
    $company = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => null,
    ]));

    CompanyContext::runForCompany($company->id, reason: 'test fixture setup', caller: __FILE__, callback: function () use ($company) {
        $order = Order::factory()->create(['company_id' => $company->id]);

        OrderLine::factory()->create(['order_id' => $order->id, 'company_id' => $company->id]);
    });

    test()->actingAs($user);

    expect(OrderLine::query()->count())->toBe(0);
});

// ── Requisition (purchase agreement) ────────────────────────────────────────

it('hides purchase agreements from companies the user is not allowed to see', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $userA = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $companyA->id,
    ]));

    $agreementA = Requisition::factory()->create(['company_id' => $companyA->id]);
    $agreementB = Requisition::factory()->create(['company_id' => $companyB->id]);

    test()->actingAs($userA);

    expect(Requisition::find($agreementA->id))->not->toBeNull();
    expect(Requisition::find($agreementB->id))->toBeNull();
});

it('hides all purchase agreements from an authenticated user without company access', function () {
    $company = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => null,
    ]));

    Requisition::factory()->create(['company_id' => $company->id]);

    test()->actingAs($user);

    expect(Requisition::query()->count())->toBe(0);
});

// ── RequisitionLine ──────────────────────────────────────────────────────────

it('hides purchase agreement lines from companies the user is not allowed to see', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $userA = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $companyA->id,
    ]));

    $agreementA = Requisition::factory()->create(['company_id' => $companyA->id]);
    $agreementB = Requisition::factory()->create(['company_id' => $companyB->id]);

    $lineA = RequisitionLine::factory()->create(['requisition_id' => $agreementA->id, 'company_id' => $companyA->id]);
    $lineB = RequisitionLine::factory()->create(['requisition_id' => $agreementB->id, 'company_id' => $companyB->id]);

    test()->actingAs($userA);

    expect(RequisitionLine::find($lineA->id))->not->toBeNull();
    expect(RequisitionLine::find($lineB->id))->toBeNull();
});

it('hides all purchase agreement lines from an authenticated user without company access', function () {
    $company = Company::factory()->create();
    $agreement = Requisition::factory()->create(['company_id' => $company->id]);

    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => null,
    ]));

    RequisitionLine::factory()->create(['requisition_id' => $agreement->id, 'company_id' => $company->id]);

    test()->actingAs($user);

    expect(RequisitionLine::query()->count())->toBe(0);
});

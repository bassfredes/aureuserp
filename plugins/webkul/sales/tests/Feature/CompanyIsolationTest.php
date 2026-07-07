<?php

use Illuminate\Support\Facades\Auth;
use Webkul\Sale\Models\Order;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('sales');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

it('hides sales orders from companies the user is not allowed to see', function () {
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

it('shows orders from every company explicitly allowed to the user', function () {
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

it('does not filter orders when there is no authenticated user', function () {
    $companyA = Company::factory()->create();
    Order::factory()->create(['company_id' => $companyA->id]);

    Auth::logout();

    expect(Order::query()->count())->toBeGreaterThanOrEqual(1);
});

it('lets a super_admin bypass company isolation via forAllCompanies', function () {
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

    expect($bypassedIds)->toContain($orderA->id);
    expect($bypassedIds)->toContain($orderB->id);
});

it('forbids a non-super_admin from bypassing company isolation', function () {
    $companyA = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $companyA->id,
    ]));

    test()->actingAs($user);

    expect(fn () => Order::forAllCompanies())
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

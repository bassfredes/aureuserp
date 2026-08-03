<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Sale\Models\Order;
use Webkul\Sale\Models\OrderOption;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('sales');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

function orderOptionOrderIn(int $companyId): Order
{
    return CompanyContext::runForAllCompanies(
        reason: 'fixture',
        caller: __FILE__,
        callback: fn () => Order::factory()->create(['company_id' => $companyId]),
    );
}

// ── read: parent_scoped via whereHas('order') ──────────────────────────────

it('shows an OrderOption of the user\'s own company, not company B\'s', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $orderA = orderOptionOrderIn($companyA->id);
    $orderB = orderOptionOrderIn($companyB->id);

    $optionA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => OrderOption::factory()->create(['order_id' => $orderA->id]));
    $optionB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => OrderOption::factory()->create(['order_id' => $orderB->id]));

    test()->actingAs(User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id])));

    $ids = OrderOption::query()->pluck('id');

    expect($ids)->toContain($optionA->id)
        ->not->toContain($optionB->id);
});

it('shows nothing to a companyless user', function () {
    $order = orderOptionOrderIn(Company::factory()->create()->id);
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => OrderOption::factory()->create(['order_id' => $order->id]));

    test()->actingAs(User::withoutEvents(fn () => User::factory()->create(['default_company_id' => null])));

    expect(OrderOption::query()->count())->toBe(0);
});

it('fails closed on OrderOption reads with no authenticated user and no active CompanyContext', function () {
    $order = orderOptionOrderIn(Company::factory()->create()->id);
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => OrderOption::factory()->create(['order_id' => $order->id]));

    expect(OrderOption::query()->count())->toBe(0);
});

// ── write: resolve persisted Order, authorize its company, reject spoofed/dirty ──

it('allows creating an OrderOption under an Order the acting user is authorized for', function () {
    $company = Company::factory()->create();
    $order = orderOptionOrderIn($company->id);

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id]));
    test()->actingAs($user);

    $option = OrderOption::factory()->create(['order_id' => $order->id]);

    expect($option->exists)->toBeTrue();
});

it('forbids creating an OrderOption under an Order the acting user is not authorized for', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $orderB = orderOptionOrderIn($companyB->id);

    test()->actingAs(User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id])));

    expect(fn () => OrderOption::factory()->create(['order_id' => $orderB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('sales_order_options', ['order_id' => $orderB->id]);
});

it('forbids creating an OrderOption with a nonexistent order_id', function () {
    $company = Company::factory()->create();
    test()->actingAs(User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id])));

    expect(fn () => OrderOption::factory()->create(['order_id' => 999999999]))
        ->toThrow(AuthorizationException::class);
});

it('forbids retargeting an OrderOption to an Order in a different company, leaving the original row intact', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $orderA = orderOptionOrderIn($companyA->id);
    $orderB = orderOptionOrderIn($companyB->id);

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->syncWithoutDetaching([$companyB->id]);
    test()->actingAs($user);

    $option = OrderOption::factory()->create(['order_id' => $orderA->id]);

    expect(fn () => $option->update(['order_id' => $orderB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('sales_order_options', ['id' => $option->id, 'order_id' => $orderA->id]);
});

it('forbids a user in company A from updating an OrderOption of company B obtained via withoutGlobalScope', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $orderB = orderOptionOrderIn($companyB->id);
    $optionB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => OrderOption::factory()->create(['order_id' => $orderB->id]));

    test()->actingAs(User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id])));

    $unscoped = OrderOption::withoutGlobalScope('companyViaOrder')->findOrFail($optionB->id);

    expect(fn () => $unscoped->update(['quantity' => 5]))
        ->toThrow(AuthorizationException::class);
});

it('fails closed when creating an OrderOption with no authenticated user and no active CompanyContext', function () {
    $order = orderOptionOrderIn(Company::factory()->create()->id);

    expect(fn () => OrderOption::factory()->create(['order_id' => $order->id]))
        ->toThrow(AuthorizationException::class);
});

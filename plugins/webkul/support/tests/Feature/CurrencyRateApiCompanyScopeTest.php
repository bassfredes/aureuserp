<?php

use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;
use Webkul\Support\Models\CurrencyRate;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../Helpers/SecurityHelper.php';
require_once __DIR__.'/../Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensureERPInstalled();
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

// Deliberately does NOT reuse SecurityHelper::authenticateWithPermissions():
// that helper's createUser() calls grantExistingCompanies(), which grants
// every company that already exists in the database to the new user --
// convenient for the common case, but it makes a genuinely cross-company
// fixture (company B created before authenticating the actor) impossible to
// construct for these tests specifically (#138 PR4 A4D-0, same reasoning as
// BankAccountReadIntegrationTest's local helper in ola4C). Replicates only
// the Sanctum/permission wiring authenticateWithPermissions() itself
// performs, none of the company grant.
function actingAsScopedCurrencyRateApiUser(User $user, array $permissionNames): void
{
    Permission::query()->upsert(
        collect($permissionNames)->map(fn ($name) => ['name' => $name, 'guard_name' => 'web'])->all(),
        uniqueBy: ['name', 'guard_name'],
        update: []
    );

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user->givePermissionTo(Permission::query()->whereIn('name', $permissionNames)->where('guard_name', 'web')->get());
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Auth::guard('web')->login($user);
    Auth::guard('web')->setUser($user);
    Auth::guard('sanctum')->setUser($user);
    Auth::shouldUse('sanctum');
    Sanctum::actingAs($user, ['*']);
}

function currencyRateScopeApiRoute(string $action, mixed $currency, mixed $rate = null): string
{
    $name = "admin.api.v1.support.currencies.rates.{$action}";

    $parameters = ['currency' => $currency];

    if ($rate !== null) {
        $parameters['rate'] = $rate;
    }

    return route($name, $parameters);
}

it('index only lists rates visible to the acting user\'s own company plus shared rates', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $currency = Currency::factory()->create();

    $rateA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => $companyA->id]));
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => $companyB->id]));
    $shared = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => null]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    actingAsScopedCurrencyRateApiUser($user, ['view_support_currency']);

    $response = $this->getJson(currencyRateScopeApiRoute('index', $currency));

    $ids = collect($response->json('data'))->pluck('id')->all();

    $response->assertOk();
    expect($ids)->toContain($rateA->id, $shared->id);
});

it('filter[company_id]=B from actor A returns an empty collection, never company B\'s rows', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $currency = Currency::factory()->create();

    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    actingAsScopedCurrencyRateApiUser($user, ['view_support_currency']);

    $response = $this->getJson(currencyRateScopeApiRoute('index', $currency).'?filter[company_id]='.$companyB->id);

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
});

it('show returns 404 for a rate belonging to a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $currency = Currency::factory()->create();

    $rateB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    actingAsScopedCurrencyRateApiUser($user, ['view_support_currency']);

    $this->getJson(currencyRateScopeApiRoute('show', $currency, $rateB))
        ->assertNotFound();
});

it('update returns 404 for a rate belonging to a different company (never leaks that it exists)', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $currency = Currency::factory()->create();

    $rateB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    actingAsScopedCurrencyRateApiUser($user, ['update_support_currency']);

    $this->patchJson(currencyRateScopeApiRoute('update', $currency, $rateB), ['rate' => 9.999999])
        ->assertNotFound();

    $this->assertDatabaseHas('currency_rates', ['id' => $rateB->id, 'rate' => $rateB->rate]);
});

it('destroy returns 404 for a rate belonging to a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $currency = Currency::factory()->create();

    $rateB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    actingAsScopedCurrencyRateApiUser($user, ['update_support_currency', 'delete_support_currency']);

    $this->deleteJson(currencyRateScopeApiRoute('destroy', $currency, $rateB))
        ->assertNotFound();

    $this->assertDatabaseHas('currency_rates', ['id' => $rateB->id]);
});

it('create rejects an actor in company A submitting an explicit company_id for company B', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $currency = Currency::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    actingAsScopedCurrencyRateApiUser($user, ['update_support_currency']);

    $this->postJson(currencyRateScopeApiRoute('store', $currency), [
        'name'       => '2026-01-01',
        'rate'       => 1.5,
        'company_id' => $companyB->id,
    ])->assertForbidden();

    $this->assertDatabaseMissing('currency_rates', ['currency_id' => $currency->id, 'company_id' => $companyB->id]);
});

it('create rejects a regular authenticated user submitting company_id null (shared rate)', function () {
    $companyA = Company::factory()->create();
    $currency = Currency::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    actingAsScopedCurrencyRateApiUser($user, ['update_support_currency']);

    $this->postJson(currencyRateScopeApiRoute('store', $currency), [
        'name'       => '2026-01-01',
        'rate'       => 1.5,
        'company_id' => null,
    ])->assertForbidden();

    $this->assertDatabaseMissing('currency_rates', ['currency_id' => $currency->id, 'company_id' => null]);
});

it('create allows a super_admin to submit company_id null (shared rate)', function () {
    $companyA = Company::factory()->create();
    $currency = Currency::factory()->create();

    $superAdmin = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));
    actingAsScopedCurrencyRateApiUser($superAdmin, ['update_support_currency']);

    $this->postJson(currencyRateScopeApiRoute('store', $currency), [
        'name'       => '2026-01-01',
        'rate'       => 1.5,
        'company_id' => null,
    ])->assertCreated();

    $this->assertDatabaseHas('currency_rates', ['currency_id' => $currency->id, 'company_id' => null]);
});

it('update allows a super_admin to modify a shared rate', function () {
    $companyA = Company::factory()->create();
    $currency = Currency::factory()->create();
    $shared = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => null]));

    $superAdmin = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));
    actingAsScopedCurrencyRateApiUser($superAdmin, ['update_support_currency']);

    $this->patchJson(currencyRateScopeApiRoute('update', $currency, $shared), ['rate' => 2.5])
        ->assertOk();

    $this->assertDatabaseHas('currency_rates', ['id' => $shared->id, 'rate' => 2.5]);
});

it('destroy allows a super_admin to delete a shared rate', function () {
    $companyA = Company::factory()->create();
    $currency = Currency::factory()->create();
    $shared = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => null]));

    $superAdmin = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));
    actingAsScopedCurrencyRateApiUser($superAdmin, ['update_support_currency', 'delete_support_currency']);

    $this->deleteJson(currencyRateScopeApiRoute('destroy', $currency, $shared))
        ->assertOk();

    $this->assertDatabaseMissing('currency_rates', ['id' => $shared->id]);
});

it('index returns an empty collection for a user with no allowed companies, including shared rates', function () {
    $currency = Currency::factory()->create();
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => null]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => null]));
    actingAsScopedCurrencyRateApiUser($user, ['view_support_currency']);

    $response = $this->getJson(currencyRateScopeApiRoute('index', $currency));

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
});

it('show returns 404 for a known rate id when the user has no allowed companies', function () {
    $currency = Currency::factory()->create();
    $shared = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => null]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => null]));
    actingAsScopedCurrencyRateApiUser($user, ['view_support_currency']);

    $this->getJson(currencyRateScopeApiRoute('show', $currency, $shared))
        ->assertNotFound();
});

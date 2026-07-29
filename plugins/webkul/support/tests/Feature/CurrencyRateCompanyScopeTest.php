<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;
use Webkul\Support\Models\CurrencyRate;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../Helpers/SecurityHelper.php';
require_once __DIR__.'/../Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensureERPInstalled();
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

// ── read: company_or_shared (IncludesSharedCompanyRows) (#138 PR4 A4D-0) ──

it('shows actor A their own company rates plus shared rates, not company B', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $currency = Currency::factory()->create();

    $rateA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => $companyA->id]));
    $rateB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => $companyB->id]));
    $shared = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => null]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $ids = CurrencyRate::query()->pluck('id');

    expect($ids)->toContain($rateA->id, $shared->id)
        ->not->toContain($rateB->id);
});

it('shows a multi-company actor rates from both allowed companies plus shared rates', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $companyC = Company::factory()->create();
    $currency = Currency::factory()->create();

    $rateA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => $companyA->id]));
    $rateB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => $companyB->id]));
    $rateC = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => $companyC->id]));
    $shared = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => null]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->syncWithoutDetaching([$companyB->id]);
    test()->actingAs($user);

    $ids = CurrencyRate::query()->pluck('id');

    expect($ids)->toContain($rateA->id, $rateB->id, $shared->id)
        ->not->toContain($rateC->id);
});

it('shows nothing to a user with no allowed companies, including shared rates', function () {
    $currency = Currency::factory()->create();
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => null]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => null]));
    test()->actingAs($user);

    expect(CurrencyRate::query()->count())->toBe(0);
});

it('shows nothing with no authenticated user and no active CompanyContext', function () {
    $currency = Currency::factory()->create();
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => null]));

    expect(CurrencyRate::query()->count())->toBe(0);
});

it('shows only the exact company plus shared rates under CompanyContext::runForCompany, with no user', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $currency = Currency::factory()->create();

    $rateA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => $companyA->id]));
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => $companyB->id]));
    $shared = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => null]));

    CompanyContext::runForCompany($companyA->id, reason: 'test', caller: __FILE__, callback: function () use ($rateA, $shared) {
        $ids = CurrencyRate::query()->pluck('id');

        expect($ids)->toContain($rateA->id, $shared->id)
            ->and($ids)->toHaveCount(2);
    });
});

it('shows every rate under CompanyContext::runForAllCompanies regardless of company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $currency = Currency::factory()->create();

    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => $companyA->id]));
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => $companyB->id]));
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => null]));

    CompanyContext::runForAllCompanies(reason: 'test', caller: __FILE__, callback: function () {
        expect(CurrencyRate::query()->count())->toBe(3);
    });
});

it('shows every rate under CompanyContext::runForBootstrap regardless of company', function () {
    $company = Company::factory()->create();
    $currency = Currency::factory()->create();

    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => $company->id]));
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => null]));

    CompanyContext::runForBootstrap(reason: 'test', caller: __FILE__, callback: function () {
        expect(CurrencyRate::query()->count())->toBe(2);
    });
});

it('throws when an authenticated user is active while a CompanyContext is still open', function () {
    $company = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id]));

    CompanyContext::runForAllCompanies(reason: 'test: simulate an unexpected concurrent actor', caller: __FILE__, callback: function () use ($user) {
        test()->actingAs($user);

        expect(fn () => CurrencyRate::query()->count())->toThrow(LogicException::class);
    });
});

// ── write: create/update/delete reauthorize the effective company ────────

it('creates a CurrencyRate for the actor\'s own explicit company', function () {
    $companyA = Company::factory()->create();
    $currency = Currency::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $rate = CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => $companyA->id]);

    expect($rate->company_id)->toBe($companyA->id);
});

it('forbids a user in company A from creating a CurrencyRate directly under company B by knowing its id', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $currency = Currency::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('currency_rates', ['company_id' => $companyB->id]);
});

it('forbids a user in company A from updating an unrelated field on a CurrencyRate obtained from company B via withoutGlobalScope', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $currency = Currency::factory()->create();

    $rateB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $rateBUnscoped = CurrencyRate::withoutGlobalScope(CompanyScope::class)->findOrFail($rateB->id);

    expect(fn () => $rateBUnscoped->update(['rate' => 9.999999]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('currency_rates', ['id' => $rateB->id, 'rate' => $rateB->rate]);
});

it('forbids a user in company A from deleting a CurrencyRate obtained from company B via withoutGlobalScope', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $currency = Currency::factory()->create();

    $rateB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $rateBUnscoped = CurrencyRate::withoutGlobalScope(CompanyScope::class)->findOrFail($rateB->id);

    expect(fn () => $rateBUnscoped->delete())
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('currency_rates', ['id' => $rateB->id]);
});

// ── company_id is immutable after creation: all 3 transitions forbidden ──

it('forbids changing a CurrencyRate\'s company_id from A to B, even for a user authorized in both', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $currency = Currency::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->syncWithoutDetaching([$companyB->id]);
    test()->actingAs($user);

    $rate = CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => $companyA->id]);

    expect(fn () => $rate->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('currency_rates', ['id' => $rate->id, 'company_id' => $companyA->id]);
});

it('forbids changing a CurrencyRate\'s company_id from A to null (cannot turn an owned rate into a shared one)', function () {
    $companyA = Company::factory()->create();
    $currency = Currency::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $rate = CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => $companyA->id]);

    expect(fn () => $rate->update(['company_id' => null]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('currency_rates', ['id' => $rate->id, 'company_id' => $companyA->id]);
});

it('forbids changing a CurrencyRate\'s company_id from null to A, even for a super_admin (must recreate instead)', function () {
    $companyA = Company::factory()->create();
    $currency = Currency::factory()->create();

    $shared = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => null]));

    $superAdmin = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));
    test()->actingAs($superAdmin);

    expect(fn () => $shared->update(['company_id' => $companyA->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('currency_rates', ['id' => $shared->id, 'company_id' => null]);
});

// ── shared-row (company_id null) mutation guard ───────────────────────────

it('forbids a regular authenticated user from creating, modifying, or deleting a shared CurrencyRate', function () {
    $currency = Currency::factory()->create();
    $shared = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => null]));

    $company = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id]));
    test()->actingAs($user);

    $originalRate = $shared->rate;

    expect(fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => null]))
        ->toThrow(AuthorizationException::class);

    expect(fn () => $shared->update(['rate' => 9.999999]))
        ->toThrow(AuthorizationException::class);

    expect(fn () => $shared->delete())
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('currency_rates', ['id' => $shared->id, 'company_id' => null, 'rate' => $originalRate]);
});

it('lets a super_admin create, modify, and delete a shared CurrencyRate that a regular user cannot touch', function () {
    $currency = Currency::factory()->create();
    $shared = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => null]));

    $company = Company::factory()->create();
    $superAdmin = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id]));
    $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));
    test()->actingAs($superAdmin);

    $created = CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => null]);
    expect($created->company_id)->toBeNull();

    $shared->update(['rate' => 1.234567]);
    expect($shared->fresh()->rate)->toEqual('1.234567');

    $shared->delete();
    $this->assertDatabaseMissing('currency_rates', ['id' => $shared->id]);
});

it('forbids a no-user CompanyContext::COMPANY process from creating a shared CurrencyRate', function () {
    $company = Company::factory()->create();
    $currency = Currency::factory()->create();

    CompanyContext::runForCompany($company->id, reason: 'test', caller: __FILE__, callback: function () use ($currency) {
        expect(fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => null]))
            ->toThrow(AuthorizationException::class);
    });
});

it('lets a no-user CompanyContext::ALL_COMPANIES process create a shared CurrencyRate', function () {
    $currency = Currency::factory()->create();

    $shared = CompanyContext::runForAllCompanies(reason: 'test', caller: __FILE__, callback: fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => null]));

    expect($shared->company_id)->toBeNull();
});

it('lets a no-user CompanyContext::BOOTSTRAP process create a shared CurrencyRate', function () {
    $currency = Currency::factory()->create();

    $shared = CompanyContext::runForBootstrap(reason: 'test', caller: __FILE__, callback: fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => null]));

    expect($shared->company_id)->toBeNull();
});

it('forbids creating a shared CurrencyRate with no authenticated user and no active CompanyContext', function () {
    $currency = Currency::factory()->create();

    expect(fn () => CurrencyRate::factory()->create(['currency_id' => $currency->id, 'company_id' => null]))
        ->toThrow(AuthorizationException::class);
});

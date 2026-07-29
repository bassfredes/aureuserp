<?php

use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Partner\Filament\Resources\BankAccountResource;
use Webkul\Partner\Filament\Resources\BankAccountResource\Pages\ManageBankAccounts;
use Webkul\Partner\Models\BankAccount;
use Webkul\Partner\Models\Partner;
use Webkul\Security\Models\Permission;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('accounts');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

// Deliberately does NOT reuse SecurityHelper::authenticateWithPermissions():
// that helper's createUser() calls grantExistingCompanies(), which grants
// every company that already exists in the database to the new user —
// convenient for the common case, but it makes a genuinely cross-company
// fixture (created before authentication, in a different company than the
// acting user) impossible to construct for these two tests specifically
// (#138 PR4 ola4C). Replicates only the Sanctum/permission wiring
// authenticateWithPermissions() itself performs, none of the company grant.
function actingAsScopedBankAccountApiUser(User $user, array $permissionNames): void
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

it('does not enumerate a BankAccount enabled only for a different company via the Filament Resource query', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $partner = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Partner::factory()->create(['company_id' => null]));
    $visible = CompanyContext::runForCompany($companyA->id, reason: 'fixture', caller: __FILE__, callback: fn () => BankAccount::factory()->create(['partner_id' => $partner->id]));
    $hidden = CompanyContext::runForCompany($companyB->id, reason: 'fixture', caller: __FILE__, callback: fn () => BankAccount::factory()->create(['partner_id' => $partner->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $ids = BankAccountResource::getEloquentQuery()->pluck('id');

    expect($ids)->toContain($visible->id)
        ->not->toContain($hidden->id);
});

it('API index does not list a BankAccount enabled only for a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $partner = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Partner::factory()->create(['company_id' => null]));
    $visible = CompanyContext::runForCompany($companyA->id, reason: 'fixture', caller: __FILE__, callback: fn () => BankAccount::factory()->create(['partner_id' => $partner->id]));
    CompanyContext::runForCompany($companyB->id, reason: 'fixture', caller: __FILE__, callback: fn () => BankAccount::factory()->create(['partner_id' => $partner->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    actingAsScopedBankAccountApiUser($user, ['view_partner_partner']);

    $response = test()->getJson(route('admin.api.v1.partners.partners.bank-accounts.index', $partner));

    $response->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $visible->id);
});

it('API show returns 404 for a BankAccount enabled only for a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $partner = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Partner::factory()->create(['company_id' => null]));
    $hidden = CompanyContext::runForCompany($companyB->id, reason: 'fixture', caller: __FILE__, callback: fn () => BankAccount::factory()->create(['partner_id' => $partner->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    actingAsScopedBankAccountApiUser($user, ['view_partner_partner']);

    test()->getJson(route('admin.api.v1.partners.partners.bank-accounts.show', [$partner, $hidden]))
        ->assertNotFound();
});

it('ManageBankAccounts::getTabs() badge counts reflect the acting user\'s membership scope, not every company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $partner = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Partner::factory()->create(['company_id' => null]));
    CompanyContext::runForCompany($companyA->id, reason: 'fixture', caller: __FILE__, callback: fn () => BankAccount::factory()->create(['partner_id' => $partner->id]));
    CompanyContext::runForCompany($companyB->id, reason: 'fixture', caller: __FILE__, callback: fn () => BankAccount::factory()->create(['partner_id' => $partner->id]));

    $archivedForA = CompanyContext::runForCompany($companyA->id, reason: 'fixture', caller: __FILE__, callback: fn () => BankAccount::factory()->create(['partner_id' => $partner->id]));
    $archivedForA->delete();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    // Direct instantiation, no Livewire::test()/panel bootstrap: getTabs()
    // only calls BankAccount::count()/::onlyTrashed()->count(), neither of
    // which reads any Livewire/Filament page state, so this exercises the
    // exact same scoped query the real admin panel would build without
    // needing panel/tenancy/routing test infrastructure this repository
    // has no existing precedent for (#138 PR4 ola4C pre-audit correction).
    $tabs = (new ManageBankAccounts)->getTabs();

    expect($tabs['all']->getBadge())->toBe('1');
    expect($tabs['archived']->getBadge())->toBe('1');
});

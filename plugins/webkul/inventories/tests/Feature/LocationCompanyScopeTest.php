<?php

use Illuminate\Support\Facades\Auth;
use Webkul\Inventory\Enums\LocationType;
use Webkul\Inventory\Models\Location;
use Webkul\Inventory\Models\Warehouse;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('inventories');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

it('shows a user their own company locations plus the shared/global ones', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $userA = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $companyA->id,
    ]));

    $locationA = Location::factory()->create(['company_id' => $companyA->id]);
    $locationB = Location::factory()->create(['company_id' => $companyB->id]);

    test()->actingAs($userA);

    $visibleIds = Location::query()->pluck('id');

    expect($visibleIds)->toContain($locationA->id);
    expect($visibleIds)->not->toContain($locationB->id);
    // Seeded shared references (Vendors, Customers, ...) stay visible.
    expect(Location::find(1))->not->toBeNull();
});

it('hides locations from a company the user is not allowed to see', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $userA = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $companyA->id,
    ]));

    $locationB = Location::factory()->create(['company_id' => $companyB->id]);

    test()->actingAs($userA);

    expect(Location::find($locationB->id))->toBeNull();
});

it('shows locations from every company explicitly allowed to the user, plus shared ones', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $companyA->id,
    ]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);

    $locationA = Location::factory()->create(['company_id' => $companyA->id]);
    $locationB = Location::factory()->create(['company_id' => $companyB->id]);

    test()->actingAs($user);

    $visibleIds = Location::query()->pluck('id');

    expect($visibleIds)->toContain($locationA->id, $locationB->id, 1);
});

it('hides all locations, including shared ones, from an authenticated user without company access', function () {
    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => null,
    ]));

    test()->actingAs($user);

    expect(Location::query()->count())->toBe(0);
});

it('resolves the global Vendors location for a scoped user', function () {
    $company = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $company->id,
    ]));

    test()->actingAs($user);

    $vendors = Location::where('type', LocationType::SUPPLIER)->first();

    expect($vendors)->not->toBeNull()
        ->and($vendors->company_id)->toBeNull();
});

it('resolves the global Customers location for a scoped user', function () {
    $company = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $company->id,
    ]));

    test()->actingAs($user);

    $customers = Location::where('type', LocationType::CUSTOMER)->first();

    expect($customers)->not->toBeNull()
        ->and($customers->company_id)->toBeNull();
});

it('creates all warehouse locations and operation types for a scoped user', function () {
    $company = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $company->id,
    ]));

    test()->actingAs($user);

    $warehouse = Warehouse::create([
        'name'       => 'Scoped Warehouse',
        'code'       => 'SCW',
        'company_id' => $company->id,
    ]);

    expect($warehouse->view_location_id)->not->toBeNull()
        ->and($warehouse->lot_stock_location_id)->not->toBeNull()
        ->and($warehouse->in_type_id)->not->toBeNull()
        ->and($warehouse->out_type_id)->not->toBeNull()
        ->and(Location::find($warehouse->view_location_id)?->company_id)->toBe($company->id);
});

it('points incoming/outgoing operation types at the correct global locations', function () {
    $company = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $company->id,
    ]));

    test()->actingAs($user);

    $warehouse = Warehouse::create([
        'name'       => 'Flow Warehouse',
        'code'       => 'FLW',
        'company_id' => $company->id,
    ]);

    $supplierLocation = Location::where('type', LocationType::SUPPLIER)->first();
    $customerLocation = Location::where('type', LocationType::CUSTOMER)->first();

    expect($warehouse->inType->source_location_id)->toBe($supplierLocation->id)
        ->and($warehouse->outType->destination_location_id)->toBe($customerLocation->id);
});

it('forbids a regular authenticated user from modifying or deleting a shared location', function () {
    $company = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $company->id,
    ]));

    test()->actingAs($user);

    $vendors = Location::where('type', LocationType::SUPPLIER)->first();

    expect(fn () => $vendors->update(['name' => 'Hacked']))
        ->toThrow(Exception::class);

    expect(fn () => $vendors->delete())
        ->toThrow(Exception::class);

    expect(fn () => Location::create([
        'type'         => LocationType::VIEW,
        'name'         => 'Sneaky Global',
        'company_id'   => null,
        'parent_id'    => 1,
        'is_scrap'     => false,
        'is_replenish' => false,
    ]))->toThrow(Exception::class);
});

it('lets a super_admin bypass company isolation for locations via forAllCompanies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $superAdmin = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $companyA->id,
    ]));
    $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));

    $locationA = Location::factory()->create(['company_id' => $companyA->id]);
    $locationB = Location::factory()->create(['company_id' => $companyB->id]);

    test()->actingAs($superAdmin);

    expect(Location::find($locationB->id))->toBeNull();

    $bypassedIds = Location::forAllCompanies()->pluck('id')->all();

    expect($bypassedIds)->toContain($locationA->id, $locationB->id);
});

it('resolves parent/children relations across a tree mixing a global parent and a company child', function () {
    $company = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $company->id,
    ]));

    test()->actingAs($user);

    $virtualLocationsRoot = Location::where('type', LocationType::VIEW)
        ->whereNull('company_id')
        ->where('name', 'Virtual Locations')
        ->first();

    $child = Location::factory()->create([
        'company_id' => $company->id,
        'parent_id'  => $virtualLocationsRoot->id,
    ]);

    expect($child->parent->id)->toBe($virtualLocationsRoot->id)
        ->and($virtualLocationsRoot->children->pluck('id'))->toContain($child->id);
});

it('does not affect shared locations when soft-deleting and restoring a company location', function () {
    $company = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $company->id,
    ]));

    test()->actingAs($user);

    $vendors = Location::where('type', LocationType::SUPPLIER)->first();
    $companyLocation = Location::factory()->create(['company_id' => $company->id]);

    $companyLocation->delete();
    expect(Location::find($companyLocation->id))->toBeNull();
    expect(Location::find($vendors->id))->not->toBeNull();

    $companyLocation->restore();
    expect(Location::find($companyLocation->id))->not->toBeNull();
    expect(Location::withTrashed()->find($vendors->id)->trashed())->toBeFalse();
});

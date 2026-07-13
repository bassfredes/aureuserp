<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Inventory\Enums\LocationType;
use Webkul\Inventory\Models\Delivery;
use Webkul\Inventory\Models\Dropship;
use Webkul\Inventory\Models\InternalTransfer;
use Webkul\Inventory\Models\Location;
use Webkul\Inventory\Models\Lot;
use Webkul\Inventory\Models\Move;
use Webkul\Inventory\Models\MoveLine;
use Webkul\Inventory\Models\Operation;
use Webkul\Inventory\Models\OperationType;
use Webkul\Inventory\Models\OrderPoint;
use Webkul\Inventory\Models\Package;
use Webkul\Inventory\Models\PackageLevel;
use Webkul\Inventory\Models\PackageType;
use Webkul\Inventory\Models\ProductQuantity;
use Webkul\Inventory\Models\PutawayRule;
use Webkul\Inventory\Models\Receipt;
use Webkul\Inventory\Models\Route;
use Webkul\Inventory\Models\Rule;
use Webkul\Inventory\Models\Scrap;
use Webkul\Inventory\Models\StorageCategory;
use Webkul\Inventory\Models\Warehouse;
use Webkul\Product\Models\Product;
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

/**
 * The 20 inventories models that received HasCompanyScope in this rollout
 * (Product/Packaging excluded — base classes live in the products plugin,
 * out of scope; see ADR 0007). Route/Package/Lot are exercised separately
 * below (mixed-visibility / write-guard nuances); this dataset validates
 * the baseline strict_company policy: own-company visible, other-company
 * hidden, fail-closed for a companyless user.
 */
dataset('strict_company_models', [
    'Warehouse'        => [Warehouse::class],
    'OperationType'    => [OperationType::class],
    'OrderPoint'       => [OrderPoint::class],
    'PackageLevel'     => [PackageLevel::class],
    'PackageType'      => [PackageType::class],
    'ProductQuantity'  => [ProductQuantity::class],
    'PutawayRule'      => [PutawayRule::class],
    'Rule'             => [Rule::class],
    'Scrap'            => [Scrap::class],
    'StorageCategory'  => [StorageCategory::class],
    'Move'             => [Move::class],
    'Operation'        => [Operation::class],
    'Delivery'         => [Delivery::class],
    'Dropship'         => [Dropship::class],
    'InternalTransfer' => [InternalTransfer::class],
    'Receipt'          => [Receipt::class],
]);

it('hides another company\'s rows and shows the user\'s own', function (string $modelClass) {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $userA = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $companyA->id,
    ]));

    $rowA = $modelClass::factory()->create(['company_id' => $companyA->id]);
    $rowB = $modelClass::factory()->create(['company_id' => $companyB->id]);

    test()->actingAs($userA);

    $visibleIds = $modelClass::query()->pluck('id');

    expect($visibleIds)->toContain($rowA->id);
    expect($visibleIds)->not->toContain($rowB->id);
    expect($modelClass::find($rowB->id))->toBeNull();
})->with('strict_company_models');

it('shows rows from every company explicitly allowed to the user, not a third', function (string $modelClass) {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $companyC = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $companyA->id,
    ]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);

    $rowA = $modelClass::factory()->create(['company_id' => $companyA->id]);
    $rowB = $modelClass::factory()->create(['company_id' => $companyB->id]);
    $rowC = $modelClass::factory()->create(['company_id' => $companyC->id]);

    test()->actingAs($user);

    $visibleIds = $modelClass::query()->pluck('id');

    expect($visibleIds)->toContain($rowA->id);
    expect($visibleIds)->toContain($rowB->id);
    expect($visibleIds)->not->toContain($rowC->id);
})->with('strict_company_models');

it('hides everything from an authenticated user with no company', function (string $modelClass) {
    $company = Company::factory()->create();

    $row = $modelClass::factory()->create(['company_id' => $company->id]);

    $companyless = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => null,
    ]));

    test()->actingAs($companyless);

    expect($modelClass::query()->count())->toBe(0);
    expect($modelClass::find($row->id))->toBeNull();
})->with('strict_company_models');

// ── MoveLine: dedicated (factory pairs an independently-random product_id
// and uom_id, which can land in different UOM categories and throw; the
// established pattern — see MoveTest.php's createMoveLineRecord() — wires
// uom_id from the product's own default to avoid it) ───────────────────────────

function createScopedMoveLine(int $companyId): MoveLine
{
    $product = Product::factory()->create();

    $move = Move::factory()->create([
        'product_id' => $product->id,
        'uom_id'     => $product->uom_id,
        'company_id' => $companyId,
    ]);

    return MoveLine::factory()->create([
        'move_id'                 => $move->id,
        'operation_id'            => $move->operation_id,
        'product_id'              => $move->product_id,
        'uom_id'                  => $move->uom_id,
        'source_location_id'      => $move->source_location_id,
        'destination_location_id' => $move->destination_location_id,
        'company_id'              => $companyId,
    ]);
}

it('hides another company\'s move lines and shows the user\'s own', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $userA = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $companyA->id,
    ]));

    $lineA = createScopedMoveLine($companyA->id);
    $lineB = createScopedMoveLine($companyB->id);

    test()->actingAs($userA);

    $visibleIds = MoveLine::query()->pluck('id');

    expect($visibleIds)->toContain($lineA->id);
    expect($visibleIds)->not->toContain($lineB->id);
    expect(MoveLine::find($lineB->id))->toBeNull();
});

it('hides move lines from an authenticated user with no company', function () {
    $company = Company::factory()->create();

    $line = createScopedMoveLine($company->id);

    $companyless = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => null,
    ]));

    test()->actingAs($companyless);

    expect(MoveLine::query()->count())->toBe(0);
    expect(MoveLine::find($line->id))->toBeNull();
});

// ── Route: mixed visibility (shared "Buy"/"Dropship" routes) ───────────────────

it('keeps the seeded shared routes visible to any scoped user', function () {
    $company = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $company->id,
    ]));

    test()->actingAs($user);

    // Seeded as soft-deleted by RouteSeeder (a template/reference row, not
    // meant to appear in normal listings) — withTrashed() removes only the
    // SoftDeletingScope; CompanyScope's own visibility rule still applies.
    $buy = Route::withTrashed()->where('name', 'Buy')->first();

    expect($buy)->not->toBeNull()
        ->and($buy->company_id)->toBeNull();
});

it('forbids a regular user from mutating a shared route, allows super_admin', function () {
    $company = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $company->id,
    ]));

    test()->actingAs($user);

    $buy = Route::withTrashed()->where('name', 'Buy')->first();

    expect(fn () => $buy->update(['name' => 'Hacked']))
        ->toThrow(AuthorizationException::class);

    expect(fn () => $buy->delete())
        ->toThrow(AuthorizationException::class);

    $superAdminRole = Role::firstOrCreate(['name' => 'super_admin'], ['guard_name' => 'web']);
    $superAdmin = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $company->id,
    ]));
    $superAdmin->assignRole($superAdminRole);

    test()->actingAs($superAdmin);

    $buy->refresh();
    $buy->update(['name' => 'Buy']);

    expect(Route::withTrashed()->find($buy->id)->name)->toBe('Buy');
});

it('does not let a regular user create a route with a null company_id', function () {
    $company = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $company->id,
    ]));

    test()->actingAs($user);

    $route = Route::factory()->create(['company_id' => null]);

    expect($route->company_id)->toBe($company->id);
});

// ── Package: shared visibility, no write guard (routine recompute) ─────────────

it('keeps a null-company package visible to any scoped user', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $package = Package::factory()->create(['company_id' => null]);

    $userB = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $companyB->id,
    ]));

    test()->actingAs($userB);

    expect(Package::find($package->id))->not->toBeNull();
});

it('lets a regular user update a null-company package (routine recompute, not a protected shared row)', function () {
    $company = Company::factory()->create();

    $package = Package::factory()->create(['company_id' => null]);

    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $company->id,
    ]));

    test()->actingAs($user);

    $package->update(['company_id' => $company->id]);

    expect($package->fresh()->company_id)->toBe($company->id);
});

// ── Lot: shared visibility, no write guard, company_id now defaults ────────────

it('keeps a null-company lot visible to any scoped user', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $lot = Lot::factory()->create(['company_id' => null]);

    $userB = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $companyB->id,
    ]));

    test()->actingAs($userB);

    expect(Lot::find($lot->id))->not->toBeNull();
});

it('defaults a lot\'s company_id from the acting user when omitted', function () {
    $company = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $company->id,
    ]));

    test()->actingAs($user);

    $lot = Lot::factory()->create(['company_id' => null]);

    expect($lot->company_id)->toBe($company->id);
});

// ── Warehouse: creation cascade stays scoped end-to-end ─────────────────────────

it('scopes every row created by the warehouse creation cascade to the same company', function () {
    $company = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $company->id,
    ]));

    test()->actingAs($user);

    $warehouse = Warehouse::factory()->create(['company_id' => $company->id]);

    expect($warehouse->company_id)->toBe($company->id);

    $locations = Location::where('warehouse_id', $warehouse->id)->get();
    expect($locations)->not->toBeEmpty();
    $locations->each(fn ($location) => expect($location->company_id)->toBe($company->id));

    $operationTypes = OperationType::where('warehouse_id', $warehouse->id)->get();
    expect($operationTypes)->not->toBeEmpty();
    $operationTypes->each(fn ($operationType) => expect($operationType->company_id)->toBe($company->id));

    $routes = Route::whereIn('id', function ($query) use ($warehouse) {
        $query->select('route_id')
            ->from('inventories_route_warehouses')
            ->where('warehouse_id', $warehouse->id);
    })->get();
    expect($routes)->not->toBeEmpty();
    $routes->each(fn ($route) => expect($route->company_id)->toBe($company->id));

    $rules = Rule::where('warehouse_id', $warehouse->id)->get();
    expect($rules)->not->toBeEmpty();
    $rules->each(fn ($rule) => expect($rule->company_id)->toBe($company->id));
});

it('lets a scoped user see the shared Vendors/Customers locations resolved during warehouse creation', function () {
    $company = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $company->id,
    ]));

    test()->actingAs($user);

    Warehouse::factory()->create(['company_id' => $company->id]);

    $vendors = Location::where('type', LocationType::SUPPLIER)->first();
    $customers = Location::where('type', LocationType::CUSTOMER)->first();

    expect($vendors)->not->toBeNull()->and($vendors->company_id)->toBeNull();
    expect($customers)->not->toBeNull()->and($customers->company_id)->toBeNull();
});

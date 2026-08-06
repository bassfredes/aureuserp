<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Webkul\Inventory\Enums\LocationType;
use Webkul\Inventory\Models\Location;
use Webkul\Inventory\Models\Rule;
use Webkul\Inventory\Models\Warehouse;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('manufacturing');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

/**
 * aureuserp #138 PR4 gap backfill: manufacturing:production-location:backfill.
 * Simulates the pre-fix corrupted state (a Warehouse's "Pre-Production ->
 * Production" Rule pointing at a Location owned by a different company, or
 * a company with no Production location at all) via a raw DB update, since
 * the fixed create path can no longer produce either state on its own.
 */
it('repoints a rule pointing at another company\'s Production location back to its own company\'s row (dry-run reports, does not write)', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $warehouseA = Warehouse::create([
        'name'       => 'Warehouse A',
        'code'       => 'WHA',
        'company_id' => $companyA->id,
    ])->fresh();

    $productionA = Location::where('type', LocationType::PRODUCTION)->where('company_id', $companyA->id)->first();
    $productionB = Location::factory()->production()->create(['company_id' => $companyB->id]);

    $rule = Rule::withTrashed()->where('name', $warehouseA->code.': Pre-Production → Production')->first();

    // Simulate the pre-fix corrupted state — the only way this row could
    // still exist today, now that the create path always resolves the
    // warehouse's own company's Production location.
    DB::table('inventories_rules')->where('id', $rule->id)->update(['destination_location_id' => $productionB->id]);

    test()->artisan('manufacturing:production-location:backfill', ['--dry-run' => true])->assertExitCode(0);

    expect(DB::table('inventories_rules')->where('id', $rule->id)->value('destination_location_id'))
        ->toBe($productionB->id);

    test()->artisan('manufacturing:production-location:backfill')->assertExitCode(0);

    expect(DB::table('inventories_rules')->where('id', $rule->id)->value('destination_location_id'))
        ->toBe($productionA->id);
});

it('provisions a missing Production location for a company and repoints its rule, without falling back to another company\'s row', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $warehouseA = Warehouse::create([
        'name'       => 'Warehouse A',
        'code'       => 'WHZ',
        'company_id' => $companyA->id,
    ])->fresh();

    $productionA = Location::where('type', LocationType::PRODUCTION)->where('company_id', $companyA->id)->first();
    $productionB = Location::factory()->production()->create(['company_id' => $companyB->id]);

    $rule = Rule::withTrashed()->where('name', $warehouseA->code.': Pre-Production → Production')->first();

    DB::table('inventories_rules')->where('id', $rule->id)->update(['destination_location_id' => $productionB->id]);

    // Now company A has no Production location at all (its original one is
    // unreferenced after the update above, so it can be hard-deleted
    // without violating the restrictOnDelete FK on destination_location_id).
    $productionA->forceDelete();

    test()->artisan('manufacturing:production-location:backfill')->assertExitCode(0);

    $newProductionA = Location::where('type', LocationType::PRODUCTION)->where('company_id', $companyA->id)->first();

    expect($newProductionA)->not->toBeNull()
        ->and($newProductionA->id)->not->toBe($productionA->id)
        ->and($newProductionA->id)->not->toBe($productionB->id);

    expect(DB::table('inventories_rules')->where('id', $rule->id)->value('destination_location_id'))
        ->toBe($newProductionA->id);
});

it('is idempotent: running the backfill twice does not change already-correct rows', function () {
    $company = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id]));
    test()->actingAs($user);

    $warehouse = Warehouse::create([
        'name'       => 'Warehouse',
        'code'       => 'WHY',
        'company_id' => $company->id,
    ])->fresh();

    test()->artisan('manufacturing:production-location:backfill')->assertExitCode(0);

    $rule = Rule::withTrashed()->where('name', $warehouse->code.': Pre-Production → Production')->first();
    $afterFirstRun = $rule->destination_location_id;

    test()->artisan('manufacturing:production-location:backfill')->assertExitCode(0);

    $afterSecondRun = Rule::withTrashed()->find($rule->id)->destination_location_id;

    expect($afterFirstRun)->toBe($afterSecondRun);
});

it('reports success with nothing to do when there are no manufacturing-enabled warehouses', function () {
    test()->artisan('manufacturing:production-location:backfill')->assertExitCode(0);
});

/**
 * Real deploy execution has no authenticated actor at all — CompanyScope
 * fails closed (`1 = 0`) with no user and no active CompanyContext (ADR
 * 0007), so every Eloquent read inside resolveOrCreateProductionLocation()
 * and Location's own single-Production-per-company guard would silently
 * see zero rows unless the command opens its own per-warehouse
 * CompanyContext. Fixtures are built authenticated (Warehouse's own cascade
 * creates a Route, and Route::boot() — a pre-existing, unrelated gap — has
 * no actor-less branch at all, unlike Location) and then Auth::logout()'d
 * before the artisan call, the same "log out right before the actor-less
 * assertion" shape RelationIntegrityTest.php already uses for this kind of
 * case. The artisan call itself, and everything read afterward, run with
 * no authenticated user and no open CompanyContext — the same as
 * `php artisan manufacturing:production-location:backfill` run from a real
 * deploy.
 */
it('provisions exactly one Production location for a company with two warehouses when run with no authenticated actor at all', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $warehouseA = Warehouse::create([
        'name'       => 'No-Actor Warehouse A',
        'code'       => 'WHNA',
        'company_id' => $companyA->id,
    ])->fresh();

    $warehouseB = Warehouse::create([
        'name'       => 'No-Actor Warehouse B',
        'code'       => 'WHNB',
        'company_id' => $companyA->id,
    ])->fresh();

    $productionB = Location::factory()->production()->create(['company_id' => $companyB->id]);

    $originalProductionAId = DB::table('inventories_locations')
        ->where('type', 'production')
        ->where('company_id', $companyA->id)
        ->value('id');

    $ruleA = Rule::withTrashed()->where('name', $warehouseA->code.': Pre-Production → Production')->first();
    $ruleB = Rule::withTrashed()->where('name', $warehouseB->code.': Pre-Production → Production')->first();

    // Simulate the pre-fix corrupted state for BOTH warehouses of the same
    // company: no Production location of its own, both rules pointing at a
    // different company's row. Raw DB writes only — no Eloquent involved in
    // constructing this fixture, so none of it depends on the fix under
    // test.
    DB::table('inventories_rules')->where('id', $ruleA->id)->update(['destination_location_id' => $productionB->id]);
    DB::table('inventories_rules')->where('id', $ruleB->id)->update(['destination_location_id' => $productionB->id]);
    DB::table('inventories_locations')->where('id', $originalProductionAId)->delete();

    Auth::logout();

    expect(Auth::check())->toBeFalse()
        ->and(CompanyContext::current())->toBeNull();

    test()->artisan('manufacturing:production-location:backfill')->assertExitCode(0);

    // Reads happen via the DB facade, not Eloquent — the test itself has no
    // authenticated actor and no open CompanyContext at this point either,
    // so an Eloquent read here would hit the very same fail-closed branch
    // this test exists to prove the command no longer falls into.
    $newProductionRowsForA = DB::table('inventories_locations')
        ->where('type', 'production')
        ->where('company_id', $companyA->id)
        ->whereNull('deleted_at')
        ->get();

    expect($newProductionRowsForA)->toHaveCount(1);

    $newProductionId = $newProductionRowsForA->first()->id;

    expect($newProductionId)->not->toBe($productionB->id)
        ->and(DB::table('inventories_rules')->where('id', $ruleA->id)->value('destination_location_id'))->toBe($newProductionId)
        ->and(DB::table('inventories_rules')->where('id', $ruleB->id)->value('destination_location_id'))->toBe($newProductionId);
});

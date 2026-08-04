<?php

use Illuminate\Support\Facades\DB;
use Webkul\Inventory\Enums\LocationType;
use Webkul\Inventory\Models\Location;
use Webkul\Inventory\Models\Rule;
use Webkul\Inventory\Models\Warehouse;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

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

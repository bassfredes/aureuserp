<?php

use Webkul\Inventory\Enums\LocationType;
use Webkul\Inventory\Enums\ManufactureStep;
use Webkul\Inventory\Models\Location;
use Webkul\Inventory\Models\Rule;
use Webkul\Inventory\Models\Warehouse;
use Webkul\Manufacturing\Models\Warehouse as ManufacturingWarehouse;
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
 * aureuserp #138 PR4 gap: Warehouse::createManufacturingRules() and
 * Warehouse::syncManufacturingWarehouseConfiguration() used to resolve "the"
 * Production Location via an unscoped, company-blind global lookup, so
 * every company's manufacturing rules silently pointed at whichever
 * company's row the query returned first — breaking operational isolation
 * of stock movements between companies. This suite proves each company now
 * resolves and keeps its own Production Location, and that a Warehouse
 * unable to resolve one fails closed instead of falling back to another
 * company's row.
 */
// ── createManufacturingRules(): each company gets its own Production Location ──

it('resolves a distinct, company-scoped Production location when creating manufacturing rules for two different companies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    // WarehouseObserver implements ShouldHandleEventsAfterCommit — the
    // manufacturing side effects (Locations, OperationTypes, Rules) happen
    // after this create() call already returned, so each in-memory
    // instance must be refreshed before reading those columns.
    $warehouseA = Warehouse::create([
        'name'       => 'Warehouse A',
        'code'       => 'WHA',
        'company_id' => $companyA->id,
    ])->fresh();

    $warehouseB = Warehouse::create([
        'name'       => 'Warehouse B',
        'code'       => 'WHB',
        'company_id' => $companyB->id,
    ])->fresh();

    $productionA = Location::where('type', LocationType::PRODUCTION)->where('company_id', $companyA->id)->first();
    $productionB = Location::where('type', LocationType::PRODUCTION)->where('company_id', $companyB->id)->first();

    expect($productionA)->not->toBeNull()
        ->and($productionB)->not->toBeNull()
        ->and($productionA->id)->not->toBe($productionB->id);

    // Default manufacture_steps is ONE_STEP, which archives (soft-deletes)
    // the "Pre-Production -> Production" rule immediately — withTrashed()
    // is required to read it.
    $ruleA = Rule::withTrashed()->where('name', $warehouseA->code.': Pre-Production → Production')->first();
    $ruleB = Rule::withTrashed()->where('name', $warehouseB->code.': Pre-Production → Production')->first();

    expect($ruleA)->not->toBeNull()
        ->and($ruleB)->not->toBeNull()
        ->and($ruleA->destination_location_id)->toBe($productionA->id)
        ->and($ruleB->destination_location_id)->toBe($productionB->id)
        ->and($ruleA->destination_location_id)->not->toBe($ruleB->destination_location_id);
});

it('reuses an already-existing company Production location instead of provisioning a duplicate', function () {
    $company = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id]));
    test()->actingAs($user);

    $existingProduction = Location::factory()->production()->create(['company_id' => $company->id]);

    $warehouse = Warehouse::create([
        'name'       => 'Existing Production Warehouse',
        'code'       => 'EPW',
        'company_id' => $company->id,
    ])->fresh();

    $productionLocations = Location::where('type', LocationType::PRODUCTION)->where('company_id', $company->id)->get();

    expect($productionLocations)->toHaveCount(1)
        ->and($productionLocations->first()->id)->toBe($existingProduction->id);

    $rule = Rule::withTrashed()->where('name', $warehouse->code.': Pre-Production → Production')->first();

    expect($rule->destination_location_id)->toBe($existingProduction->id);
});

// ── syncManufacturingWarehouseConfiguration(): stays company-scoped on update ──

it('keeps the Pre-Production -> Production rule pointed at its own company\'s Production location after syncManufacturingWarehouseConfiguration runs', function () {
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

    // A second company's Warehouse/Production location exists at the same
    // time — if the resolution were still company-blind, the sync below
    // could silently repoint warehouseA's rule at companyB's location.
    Warehouse::create([
        'name'       => 'Warehouse B',
        'code'       => 'WHB',
        'company_id' => $companyB->id,
    ]);

    $productionA = Location::where('type', LocationType::PRODUCTION)->where('company_id', $companyA->id)->first();
    $productionB = Location::where('type', LocationType::PRODUCTION)->where('company_id', $companyB->id)->first();

    // Switching to THREE_STEPS restores the previously-archived
    // "Pre-Production -> Production" rule via
    // syncManufacturingWarehouseConfiguration()'s updateRules() call.
    $warehouseA->update(['manufacture_steps' => ManufactureStep::THREE_STEPS]);

    $ruleA = Rule::where('name', $warehouseA->code.': Pre-Production → Production')->first();

    expect($ruleA)->not->toBeNull()
        ->and($ruleA->trashed())->toBeFalse()
        ->and($ruleA->destination_location_id)->toBe($productionA->id)
        ->and($ruleA->destination_location_id)->not->toBe($productionB->id);
});

// ── Explicit failure path: never falls back to another company's row ────────

it('fails closed instead of falling back to another company\'s Production location when a warehouse has no company_id', function () {
    Location::factory()->production()->create(['company_id' => Company::factory()->create()->id]);

    $warehouse = new ManufacturingWarehouse(['code' => 'NOCOMPANY']);

    expect(fn () => $warehouse->resolveOrCreateProductionLocation())
        ->toThrow(RuntimeException::class);
});

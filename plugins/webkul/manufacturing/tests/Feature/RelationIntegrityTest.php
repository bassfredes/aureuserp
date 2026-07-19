<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Webkul\Inventory\Enums\LocationType;
use Webkul\Inventory\Models\Location;
use Webkul\Inventory\Models\Warehouse;
use Webkul\Manufacturing\Models\BillOfMaterial;
use Webkul\Manufacturing\Models\BillOfMaterialByproduct;
use Webkul\Manufacturing\Models\BillOfMaterialLine;
use Webkul\Manufacturing\Models\Operation;
use Webkul\Manufacturing\Models\Order;
use Webkul\Manufacturing\Models\UnbuildOrder;
use Webkul\Manufacturing\Models\WorkCenter;
use Webkul\Manufacturing\Models\WorkCenterCapacity;
use Webkul\Manufacturing\Models\WorkCenterProductivityLog;
use Webkul\Manufacturing\Models\WorkCenterProductivityLoss;
use Webkul\Manufacturing\Models\WorkOrder;
use Webkul\Product\Models\Product;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Models\UOM;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('manufacturing');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

// ── BillOfMaterial → Product ─────────────────────────────────────────────────

it('forbids a BillOfMaterial for company A referencing a Product from company B, even for a user allowed in both', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $productB = Product::factory()->create(['company_id' => $companyB->id]);

    expect(fn () => BillOfMaterial::factory()->create([
        'company_id' => $companyA->id,
        'product_id' => $productB->id,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('manufacturing_bills_of_materials', ['company_id' => $companyA->id, 'product_id' => $productB->id]);
});

it('forbids changing a BillOfMaterial\'s product_id to a Product from a different company on update', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->create(['company_id' => $companyB->id]);
    $bom = BillOfMaterial::factory()->create(['company_id' => $companyA->id, 'product_id' => $productA->id]);

    expect(fn () => $bom->update(['product_id' => $productB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('manufacturing_bills_of_materials', ['id' => $bom->id, 'product_id' => $productA->id]);
});

// ── BillOfMaterialLine / Byproduct: derived from parent BOM ─────────────────

it('forbids a BillOfMaterialLine referencing a Product from a different company than its BillOfMaterial', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->create(['company_id' => $companyB->id]);
    $bom = BillOfMaterial::factory()->create(['company_id' => $companyA->id, 'product_id' => $productA->id]);
    $uomId = UOM::query()->value('id') ?? UOM::factory()->create()->id;

    expect(fn () => BillOfMaterialLine::create([
        'bill_of_material_id' => $bom->id,
        'product_id'          => $productB->id,
        'uom_id'              => $uomId,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('manufacturing_bill_of_material_lines', ['bill_of_material_id' => $bom->id, 'product_id' => $productB->id]);
});

it('derives a BillOfMaterialLine.company_id from its parent BillOfMaterial, not the acting user\'s default', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyB->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $bom = BillOfMaterial::factory()->create(['company_id' => $companyA->id, 'product_id' => $productA->id]);
    $uomId = UOM::query()->value('id') ?? UOM::factory()->create()->id;

    $line = BillOfMaterialLine::create([
        'bill_of_material_id' => $bom->id,
        'product_id'          => $productA->id,
        'uom_id'              => $uomId,
    ]);

    expect($line->company_id)->toBe($companyA->id);
});

it('forbids creating a BillOfMaterialLine when the parent BillOfMaterial cannot be resolved', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $uomId = UOM::query()->value('id') ?? UOM::factory()->create()->id;

    expect(fn () => BillOfMaterialLine::create([
        'bill_of_material_id' => 999999999,
        'product_id'          => $productA->id,
        'uom_id'              => $uomId,
    ]))->toThrow(AuthorizationException::class);
});

it('forbids reassigning a BillOfMaterialLine to a BillOfMaterial from a different company on update', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->create(['company_id' => $companyB->id]);
    $bomA = BillOfMaterial::factory()->create(['company_id' => $companyA->id, 'product_id' => $productA->id]);
    $bomB = BillOfMaterial::factory()->create(['company_id' => $companyB->id, 'product_id' => $productB->id]);
    $uomId = UOM::query()->value('id') ?? UOM::factory()->create()->id;

    $line = BillOfMaterialLine::create([
        'bill_of_material_id' => $bomA->id,
        'product_id'          => $productA->id,
        'uom_id'              => $uomId,
    ]);

    expect(fn () => $line->update(['bill_of_material_id' => $bomB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('manufacturing_bill_of_material_lines', ['id' => $line->id, 'bill_of_material_id' => $bomA->id]);
});

it('forbids a BillOfMaterialByproduct referencing a Product from a different company than its BillOfMaterial', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->create(['company_id' => $companyB->id]);
    $bom = BillOfMaterial::factory()->create(['company_id' => $companyA->id, 'product_id' => $productA->id]);
    $uomId = UOM::query()->value('id') ?? UOM::factory()->create()->id;

    expect(fn () => BillOfMaterialByproduct::create([
        'bill_of_material_id' => $bom->id,
        'product_id'          => $productB->id,
        'uom_id'              => $uomId,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('manufacturing_bill_of_material_byproducts', ['bill_of_material_id' => $bom->id, 'product_id' => $productB->id]);
});

// ── Order (manufacturing) → Product / BillOfMaterial ────────────────────────

it('forbids a manufacturing Order for company A referencing a Product from company B, even for a user allowed in both', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $productB = Product::factory()->create(['company_id' => $companyB->id]);

    expect(fn () => Order::factory()->create([
        'company_id'          => $companyA->id,
        'product_id'          => $productB->id,
        'bill_of_material_id' => null,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('manufacturing_orders', ['company_id' => $companyA->id, 'product_id' => $productB->id]);
});

it('forbids a manufacturing Order for company A referencing a BillOfMaterial from company B, even for a user allowed in both', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->create(['company_id' => $companyB->id]);
    $bomB = BillOfMaterial::factory()->create(['company_id' => $companyB->id, 'product_id' => $productB->id]);

    expect(fn () => Order::factory()->create([
        'company_id'          => $companyA->id,
        'product_id'          => $productA->id,
        'bill_of_material_id' => $bomB->id,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('manufacturing_orders', ['company_id' => $companyA->id, 'bill_of_material_id' => $bomB->id]);
});

// ── UnbuildOrder → Product / BillOfMaterial / manufacturing Order ───────────

it('forbids an UnbuildOrder for company A referencing a Product from company B, even for a user allowed in both', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $productB = Product::factory()->create(['company_id' => $companyB->id]);

    expect(fn () => UnbuildOrder::factory()->create([
        'company_id'          => $companyA->id,
        'product_id'          => $productB->id,
        'bill_of_material_id' => null,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('manufacturing_unbuild_orders', ['company_id' => $companyA->id, 'product_id' => $productB->id]);
});

it('forbids an UnbuildOrder for company A referencing a manufacturing Order from company B, even for a user allowed in both', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    // orderB must be a genuinely, validly created Order — never
    // saveQuietly() to fabricate a parent that skips Order's own
    // creating()/saving() guards, which would prove nothing about
    // UnbuildOrder's guard in a security test (#138 review, 2026-07-18).
    // A real Warehouse::create() wires the operation type + "production"
    // Location Order's own side effects need; $user already has both
    // companies allowed, so the manufacturing WarehouseObserver's own
    // Location::where(...) lookup for the just-created "production"
    // Location stays visible under normal CompanyScope. That lookup is
    // itself company-blind (a pre-existing manufacturing gap, out of this
    // PR's scope) and returns nothing at all on a fresh DB with zero
    // production locations, so one is seeded first.
    Location::factory()->create(['type' => LocationType::PRODUCTION, 'company_id' => $companyB->id]);

    // WarehouseObserver implements ShouldHandleEventsAfterCommit — its
    // manu_type_id/lot_stock_location_id assignment happens after this
    // create() call already returned, so this in-memory instance must be
    // refreshed before reading those columns.
    $warehouseB = Warehouse::create([
        'name'       => 'Warehouse B',
        'code'       => 'WHB',
        'company_id' => $companyB->id,
    ])->fresh();

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->create(['company_id' => $companyB->id]);
    $orderB = Order::factory()->create([
        'company_id'          => $companyB->id,
        'product_id'          => $productB->id,
        'uom_id'              => $productB->uom_id,
        'operation_type_id'   => $warehouseB->manu_type_id,
        'source_location_id'  => $warehouseB->lot_stock_location_id,
        'bill_of_material_id' => null,
    ]);

    expect(fn () => UnbuildOrder::factory()->create([
        'company_id'             => $companyA->id,
        'product_id'             => $productA->id,
        'manufacturing_order_id' => $orderB->id,
        'bill_of_material_id'    => null,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('manufacturing_unbuild_orders', ['company_id' => $companyA->id, 'manufacturing_order_id' => $orderB->id]);
});

it('forbids changing a manufacturing Order\'s product_id to a Product from a different company on update', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    // A real Warehouse-backed operation_type_id/source_location_id for
    // companyA is required here — OrderFactory's bare
    // `OperationType::query()->value('id')` default picks up whatever
    // OperationType happens to exist first in the DB, which belongs to no
    // particular company and derails Order's own finished-Move creation
    // (Move's pre-existing #137 D5b company derivation) with an unrelated
    // mismatch once the Order itself is valid enough to reach that step.
    Location::factory()->create(['type' => LocationType::PRODUCTION, 'company_id' => $companyA->id]);
    $warehouseA = Warehouse::create([
        'name'       => 'Warehouse A',
        'code'       => 'WHA',
        'company_id' => $companyA->id,
    ])->fresh();

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->create(['company_id' => $companyB->id]);
    $order = Order::factory()->create([
        'company_id'          => $companyA->id,
        'product_id'          => $productA->id,
        'uom_id'              => $productA->uom_id,
        'operation_type_id'   => $warehouseA->manu_type_id,
        'source_location_id'  => $warehouseA->lot_stock_location_id,
        'bill_of_material_id' => null,
    ]);

    expect(fn () => $order->update(['product_id' => $productB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('manufacturing_orders', ['id' => $order->id, 'product_id' => $productA->id]);
});

it('forbids changing a manufacturing Order\'s bill_of_material_id to a BillOfMaterial from a different company on update', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    Location::factory()->create(['type' => LocationType::PRODUCTION, 'company_id' => $companyA->id]);
    $warehouseA = Warehouse::create([
        'name'       => 'Warehouse A',
        'code'       => 'WHA',
        'company_id' => $companyA->id,
    ])->fresh();

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->create(['company_id' => $companyB->id]);
    $bomA = BillOfMaterial::factory()->create(['company_id' => $companyA->id, 'product_id' => $productA->id, 'uom_id' => $productA->uom_id]);
    $bomB = BillOfMaterial::factory()->create(['company_id' => $companyB->id, 'product_id' => $productB->id, 'uom_id' => $productB->uom_id]);
    $order = Order::factory()->create([
        'company_id'          => $companyA->id,
        'product_id'          => $productA->id,
        'uom_id'              => $productA->uom_id,
        'operation_type_id'   => $warehouseA->manu_type_id,
        'source_location_id'  => $warehouseA->lot_stock_location_id,
        'bill_of_material_id' => $bomA->id,
    ]);

    expect(fn () => $order->update(['bill_of_material_id' => $bomB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('manufacturing_orders', ['id' => $order->id, 'bill_of_material_id' => $bomA->id]);
});

it('forbids changing an UnbuildOrder\'s product_id to a Product from a different company on update', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->create(['company_id' => $companyB->id]);
    $unbuildOrder = UnbuildOrder::factory()->create([
        'company_id'          => $companyA->id,
        'product_id'          => $productA->id,
        'bill_of_material_id' => null,
    ]);

    expect(fn () => $unbuildOrder->update(['product_id' => $productB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('manufacturing_unbuild_orders', ['id' => $unbuildOrder->id, 'product_id' => $productA->id]);
});

it('forbids reassigning an UnbuildOrder\'s manufacturing_order_id to a manufacturing Order from a different company on update', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    // orderA/orderB both built via their own real, fully-guarded creation
    // path (never saveQuietly()) so the update-time guard being exercised
    // here is the only thing standing between the two companies
    // (#138 review, 2026-07-18).
    Location::factory()->create(['type' => LocationType::PRODUCTION, 'company_id' => $companyA->id]);
    Location::factory()->create(['type' => LocationType::PRODUCTION, 'company_id' => $companyB->id]);

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

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->create(['company_id' => $companyB->id]);

    $orderA = Order::factory()->create([
        'company_id'          => $companyA->id,
        'product_id'          => $productA->id,
        'uom_id'              => $productA->uom_id,
        'operation_type_id'   => $warehouseA->manu_type_id,
        'source_location_id'  => $warehouseA->lot_stock_location_id,
        'bill_of_material_id' => null,
    ]);

    $orderB = Order::factory()->create([
        'company_id'          => $companyB->id,
        'product_id'          => $productB->id,
        'uom_id'              => $productB->uom_id,
        'operation_type_id'   => $warehouseB->manu_type_id,
        'source_location_id'  => $warehouseB->lot_stock_location_id,
        'bill_of_material_id' => null,
    ]);

    $unbuildOrder = UnbuildOrder::factory()->create([
        'company_id'             => $companyA->id,
        'product_id'             => $productA->id,
        'manufacturing_order_id' => $orderA->id,
        'bill_of_material_id'    => null,
    ]);

    expect(fn () => $unbuildOrder->update(['manufacturing_order_id' => $orderB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('manufacturing_unbuild_orders', ['id' => $unbuildOrder->id, 'manufacturing_order_id' => $orderA->id]);
});

// ── WorkCenterProductivityLog: strict-derived company from WorkCenter ──────

it('forbids a WorkCenterProductivityLog referencing a WorkCenter that cannot be resolved', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $loss = WorkCenterProductivityLoss::create(['loss_type' => 'other', 'name' => 'Other', 'manual' => true]);

    expect(fn () => WorkCenterProductivityLog::create([
        'work_center_id' => 999999999,
        'loss_id'        => $loss->id,
        'started_at'     => now(),
    ]))->toThrow(AuthorizationException::class);
});

it('derives a WorkCenterProductivityLog.company_id from its WorkCenter, not the acting user\'s default', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyB->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $workCenterA = WorkCenter::factory()->create(['company_id' => $companyA->id]);
    $loss = WorkCenterProductivityLoss::create(['loss_type' => 'other', 'name' => 'Other', 'manual' => true]);

    $log = WorkCenterProductivityLog::create([
        'work_center_id' => $workCenterA->id,
        'loss_id'        => $loss->id,
        'started_at'     => now(),
    ]);

    expect($log->company_id)->toBe($companyA->id);
});

it('forbids reassigning a WorkCenterProductivityLog to a WorkCenter from a different company on update (WorkCenter A -> B)', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $workCenterA = WorkCenter::factory()->create(['company_id' => $companyA->id]);
    $workCenterB = WorkCenter::factory()->create(['company_id' => $companyB->id]);
    $loss = WorkCenterProductivityLoss::create(['loss_type' => 'other', 'name' => 'Other', 'manual' => true]);

    $log = WorkCenterProductivityLog::create([
        'work_center_id' => $workCenterA->id,
        'loss_id'        => $loss->id,
        'started_at'     => now(),
    ]);

    expect(fn () => $log->update(['work_center_id' => $workCenterB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('manufacturing_work_center_productivity_logs', ['id' => $log->id, 'work_center_id' => $workCenterA->id]);
});

// ── CompanyScope::assertCanWriteCompany(): custom "owner" boot pattern (#138 review round 2, 2026-07-18) ──

it('forbids a user in company A from creating a manufacturing Order directly under company B by knowing its id', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => Order::factory()->create([
        'company_id'          => $companyB->id,
        'bill_of_material_id' => null,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('manufacturing_orders', ['company_id' => $companyB->id]);
});

// ── BillOfMaterial::bomFindFilters(): no shared/NULL-company fallback (#138 review round 2, 2026-07-18) ──

it('does not return a legacy NULL-company BillOfMaterial row from bomFindFilters()', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $legacyBom = BillOfMaterial::factory()->create(['company_id' => $companyA->id, 'product_id' => $productA->id]);

    // A NULL company_id can only exist on a row inserted directly,
    // bypassing the model layer (resolveEffectiveCompanyIdOrFail() never
    // persists NULL going forward).
    DB::table('manufacturing_bills_of_materials')->where('id', $legacyBom->id)->update(['company_id' => null]);

    Auth::logout();

    // Under an authenticated user, CompanyScope's own whereIn(company_id,
    // allowed) already excludes NULL rows regardless of bomFindFilters()
    // — the leak this regression targets only surfaces under
    // all_companies/bootstrap, where CompanyScope itself applies no
    // filter and bomFindFilters()'s own filter is the sole guard (#138
    // review round 2, 2026-07-18).
    $results = CompanyContext::runForAllCompanies(
        reason: 'test: legacy NULL-company BOM row must not leak via bomFindFilters()',
        caller: __FILE__,
        callback: fn () => BillOfMaterial::bomFindFilters(collect([$productA]), companyId: $companyA->id)->get(),
    );

    expect($results->pluck('id'))->not->toContain($legacyBom->id);
});

// ── Operation: no company_id column — WorkCenter must match its BillOfMaterial's company (#138 review round 2, 2026-07-18) ──

it('forbids an Operation linking a WorkCenter from a different company than its BillOfMaterial', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $bomA = BillOfMaterial::factory()->create(['company_id' => $companyA->id, 'product_id' => $productA->id]);
    $workCenterB = WorkCenter::factory()->create(['company_id' => $companyB->id]);

    expect(fn () => Operation::create([
        'name'                => 'Cut',
        'bill_of_material_id' => $bomA->id,
        'work_center_id'      => $workCenterB->id,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('manufacturing_operations', ['bill_of_material_id' => $bomA->id, 'work_center_id' => $workCenterB->id]);
});

it('forbids reassigning an Operation\'s work_center_id to a WorkCenter from a different company on update', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $bomA = BillOfMaterial::factory()->create(['company_id' => $companyA->id, 'product_id' => $productA->id]);
    $workCenterA = WorkCenter::factory()->create(['company_id' => $companyA->id]);
    $workCenterB = WorkCenter::factory()->create(['company_id' => $companyB->id]);

    $operation = Operation::create([
        'name'                => 'Cut',
        'bill_of_material_id' => $bomA->id,
        'work_center_id'      => $workCenterA->id,
    ]);

    expect(fn () => $operation->update(['work_center_id' => $workCenterB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('manufacturing_operations', ['id' => $operation->id, 'work_center_id' => $workCenterA->id]);
});

// ── WorkCenterCapacity: no company_id column — Product must match its WorkCenter's company (#138 review round 2, 2026-07-18) ──

it('forbids a WorkCenterCapacity linking a Product from a different company than its WorkCenter', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $workCenterA = WorkCenter::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->create(['company_id' => $companyB->id]);

    expect(fn () => WorkCenterCapacity::create([
        'work_center_id' => $workCenterA->id,
        'product_id'     => $productB->id,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('manufacturing_work_center_capacities', ['work_center_id' => $workCenterA->id, 'product_id' => $productB->id]);
});

it('forbids reassigning a WorkCenterCapacity\'s product_id to a Product from a different company on update', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $workCenterA = WorkCenter::factory()->create(['company_id' => $companyA->id]);
    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->create(['company_id' => $companyB->id]);

    $capacity = WorkCenterCapacity::create([
        'work_center_id' => $workCenterA->id,
        'product_id'     => $productA->id,
    ]);

    expect(fn () => $capacity->update(['product_id' => $productB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('manufacturing_work_center_capacities', ['id' => $capacity->id, 'product_id' => $productA->id]);
});

// ── WorkOrder: no company_id column — WorkCenter/Product/Operation must match its manufacturing Order's company (#138 review round 2, 2026-07-18) ──

it('forbids a WorkOrder linking a WorkCenter from a different company than its manufacturing Order', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    Location::factory()->create(['type' => LocationType::PRODUCTION, 'company_id' => $companyA->id]);
    $warehouseA = Warehouse::create([
        'name'       => 'Warehouse A',
        'code'       => 'WHA',
        'company_id' => $companyA->id,
    ])->fresh();

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $orderA = Order::factory()->create([
        'company_id'          => $companyA->id,
        'product_id'          => $productA->id,
        'uom_id'              => $productA->uom_id,
        'operation_type_id'   => $warehouseA->manu_type_id,
        'source_location_id'  => $warehouseA->lot_stock_location_id,
        'bill_of_material_id' => null,
    ]);

    $workCenterB = WorkCenter::factory()->create(['company_id' => $companyB->id]);

    expect(fn () => WorkOrder::create([
        'name'                    => 'Assemble',
        'manufacturing_order_id'  => $orderA->id,
        'work_center_id'          => $workCenterB->id,
        'product_id'              => $productA->id,
        'uom_id'                  => $productA->uom_id,
        'operation_id'            => null,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('manufacturing_work_orders', ['manufacturing_order_id' => $orderA->id, 'work_center_id' => $workCenterB->id]);
});

it('forbids a WorkOrder linking an Operation from a different company than its manufacturing Order', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    Location::factory()->create(['type' => LocationType::PRODUCTION, 'company_id' => $companyA->id]);
    $warehouseA = Warehouse::create([
        'name'       => 'Warehouse A',
        'code'       => 'WHA',
        'company_id' => $companyA->id,
    ])->fresh();

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $orderA = Order::factory()->create([
        'company_id'          => $companyA->id,
        'product_id'          => $productA->id,
        'uom_id'              => $productA->uom_id,
        'operation_type_id'   => $warehouseA->manu_type_id,
        'source_location_id'  => $warehouseA->lot_stock_location_id,
        'bill_of_material_id' => null,
    ]);

    $workCenterA = WorkCenter::factory()->create(['company_id' => $companyA->id]);

    $productB = Product::factory()->create(['company_id' => $companyB->id]);
    $bomB = BillOfMaterial::factory()->create(['company_id' => $companyB->id, 'product_id' => $productB->id]);
    $operationB = Operation::create([
        'name'                => 'Weld',
        'bill_of_material_id' => $bomB->id,
        'work_center_id'      => WorkCenter::factory()->create(['company_id' => $companyB->id])->id,
    ]);

    expect(fn () => WorkOrder::create([
        'name'                   => 'Assemble',
        'manufacturing_order_id' => $orderA->id,
        'work_center_id'         => $workCenterA->id,
        'product_id'             => $productA->id,
        'uom_id'                 => $productA->uom_id,
        'operation_id'           => $operationB->id,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('manufacturing_work_orders', ['manufacturing_order_id' => $orderA->id, 'operation_id' => $operationB->id]);
});

it('forbids reassigning a WorkOrder\'s work_center_id to a WorkCenter from a different company on update', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    Location::factory()->create(['type' => LocationType::PRODUCTION, 'company_id' => $companyA->id]);
    $warehouseA = Warehouse::create([
        'name'       => 'Warehouse A',
        'code'       => 'WHA',
        'company_id' => $companyA->id,
    ])->fresh();

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $orderA = Order::factory()->create([
        'company_id'          => $companyA->id,
        'product_id'          => $productA->id,
        'uom_id'              => $productA->uom_id,
        'operation_type_id'   => $warehouseA->manu_type_id,
        'source_location_id'  => $warehouseA->lot_stock_location_id,
        'bill_of_material_id' => null,
    ]);

    $workCenterA = WorkCenter::factory()->create(['company_id' => $companyA->id]);
    $workCenterB = WorkCenter::factory()->create(['company_id' => $companyB->id]);

    $workOrder = WorkOrder::create([
        'name'                   => 'Assemble',
        'manufacturing_order_id' => $orderA->id,
        'work_center_id'         => $workCenterA->id,
        'product_id'             => $productA->id,
        'uom_id'                 => $productA->uom_id,
        'operation_id'           => null,
    ]);

    expect(fn () => $workOrder->update(['work_center_id' => $workCenterB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('manufacturing_work_orders', ['id' => $workOrder->id, 'work_center_id' => $workCenterA->id]);
});

// ── BillOfMaterialLine / Byproduct: operation_id must belong to the SAME BillOfMaterial (#138 review round 2, 2026-07-18) ──

it('forbids a BillOfMaterialLine referencing an Operation from a different BillOfMaterial, even in the same company', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $bomA = BillOfMaterial::factory()->create(['company_id' => $companyA->id, 'product_id' => $productA->id]);
    $otherBomA = BillOfMaterial::factory()->create(['company_id' => $companyA->id, 'product_id' => $productA->id]);
    $workCenterA = WorkCenter::factory()->create(['company_id' => $companyA->id]);

    $operationOfOtherBom = Operation::create([
        'name'                => 'Cut',
        'bill_of_material_id' => $otherBomA->id,
        'work_center_id'      => $workCenterA->id,
    ]);

    $uomId = UOM::query()->value('id') ?? UOM::factory()->create()->id;

    expect(fn () => BillOfMaterialLine::create([
        'bill_of_material_id' => $bomA->id,
        'product_id'          => $productA->id,
        'uom_id'              => $uomId,
        'operation_id'        => $operationOfOtherBom->id,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('manufacturing_bill_of_material_lines', ['bill_of_material_id' => $bomA->id, 'operation_id' => $operationOfOtherBom->id]);
});

it('forbids reassigning a BillOfMaterialLine\'s operation_id to an Operation from a different BillOfMaterial on update', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $bomA = BillOfMaterial::factory()->create(['company_id' => $companyA->id, 'product_id' => $productA->id]);
    $otherBomA = BillOfMaterial::factory()->create(['company_id' => $companyA->id, 'product_id' => $productA->id]);
    $workCenterA = WorkCenter::factory()->create(['company_id' => $companyA->id]);

    $operationOfOwnBom = Operation::create([
        'name'                => 'Cut',
        'bill_of_material_id' => $bomA->id,
        'work_center_id'      => $workCenterA->id,
    ]);

    $operationOfOtherBom = Operation::create([
        'name'                => 'Weld',
        'bill_of_material_id' => $otherBomA->id,
        'work_center_id'      => $workCenterA->id,
    ]);

    $uomId = UOM::query()->value('id') ?? UOM::factory()->create()->id;

    $line = BillOfMaterialLine::create([
        'bill_of_material_id' => $bomA->id,
        'product_id'          => $productA->id,
        'uom_id'              => $uomId,
        'operation_id'        => $operationOfOwnBom->id,
    ]);

    expect(fn () => $line->update(['operation_id' => $operationOfOtherBom->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('manufacturing_bill_of_material_lines', ['id' => $line->id, 'operation_id' => $operationOfOwnBom->id]);
});

it('forbids a BillOfMaterialByproduct referencing an Operation from a different BillOfMaterial, even in the same company', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $bomA = BillOfMaterial::factory()->create(['company_id' => $companyA->id, 'product_id' => $productA->id]);
    $otherBomA = BillOfMaterial::factory()->create(['company_id' => $companyA->id, 'product_id' => $productA->id]);
    $workCenterA = WorkCenter::factory()->create(['company_id' => $companyA->id]);

    $operationOfOtherBom = Operation::create([
        'name'                => 'Cut',
        'bill_of_material_id' => $otherBomA->id,
        'work_center_id'      => $workCenterA->id,
    ]);

    $uomId = UOM::query()->value('id') ?? UOM::factory()->create()->id;

    expect(fn () => BillOfMaterialByproduct::create([
        'bill_of_material_id' => $bomA->id,
        'product_id'          => $productA->id,
        'uom_id'              => $uomId,
        'operation_id'        => $operationOfOtherBom->id,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('manufacturing_bill_of_material_byproducts', ['bill_of_material_id' => $bomA->id, 'operation_id' => $operationOfOtherBom->id]);
});

// ── Order / UnbuildOrder: standalone strict owners, immutable company_id (#138 review round 2, 2026-07-18) ──

it('forbids changing a manufacturing Order\'s company_id directly, even for a user authorized in both companies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    Location::factory()->create(['type' => LocationType::PRODUCTION, 'company_id' => $companyA->id]);
    $warehouseA = Warehouse::create([
        'name'       => 'Warehouse A',
        'code'       => 'WHA',
        'company_id' => $companyA->id,
    ])->fresh();

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $order = Order::factory()->create([
        'company_id'          => $companyA->id,
        'product_id'          => $productA->id,
        'uom_id'              => $productA->uom_id,
        'operation_type_id'   => $warehouseA->manu_type_id,
        'source_location_id'  => $warehouseA->lot_stock_location_id,
        'bill_of_material_id' => null,
    ]);

    expect(fn () => $order->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('manufacturing_orders', ['id' => $order->id, 'company_id' => $companyA->id]);
});

it('forbids changing an UnbuildOrder\'s company_id directly, even for a user authorized in both companies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $unbuildOrder = UnbuildOrder::factory()->create([
        'company_id'          => $companyA->id,
        'product_id'          => $productA->id,
        'bill_of_material_id' => null,
    ]);

    expect(fn () => $unbuildOrder->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('manufacturing_unbuild_orders', ['id' => $unbuildOrder->id, 'company_id' => $companyA->id]);
});

// ── Write authorization applies to EVERY save, not only creation or an FK change (#138 review round 3, 2026-07-18) ──

it('forbids a user in company A from updating an unrelated field on a manufacturing Order obtained from company B via an unscoped query', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    // Warehouse::create() cascades into inventories' Route model, whose
    // own `creating()` hook dereferences Auth::user()->id unconditionally
    // (a pre-existing, unrelated null-safety gap in that plugin, out of
    // this PR's scope) — build this fixture under a real companyB-only
    // actor rather than a no-user CompanyContext to avoid tripping it.
    $userB = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyB->id]));
    test()->actingAs($userB);

    Location::factory()->create(['type' => LocationType::PRODUCTION, 'company_id' => $companyB->id]);
    $warehouseB = Warehouse::create([
        'name'       => 'Warehouse B',
        'code'       => 'WHB',
        'company_id' => $companyB->id,
    ])->fresh();
    $productB = Product::factory()->create(['company_id' => $companyB->id]);

    $orderB = Order::factory()->create([
        'company_id'          => $companyB->id,
        'product_id'          => $productB->id,
        'uom_id'              => $productB->uom_id,
        'operation_type_id'   => $warehouseB->manu_type_id,
        'source_location_id'  => $warehouseB->lot_stock_location_id,
        'bill_of_material_id' => null,
    ]);

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $orderBUnscoped = Order::withoutGlobalScope(CompanyScope::class)->findOrFail($orderB->id);

    expect(fn () => $orderBUnscoped->update(['origin' => 'Renamed by A']))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('manufacturing_orders', ['id' => $orderB->id, 'origin' => 'Renamed by A']);
});

it('forbids a user in company A from updating an unrelated field on an UnbuildOrder obtained from company B via an unscoped query', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $unbuildOrderB = CompanyContext::runForAllCompanies(
        reason: 'test fixture setup — UnbuildOrder in company B',
        caller: __FILE__,
        callback: function () use ($companyB) {
            $productB = Product::factory()->create(['company_id' => $companyB->id]);

            return UnbuildOrder::factory()->create([
                'company_id'          => $companyB->id,
                'product_id'          => $productB->id,
                'bill_of_material_id' => null,
            ]);
        },
    );

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $unbuildOrderBUnscoped = UnbuildOrder::withoutGlobalScope(CompanyScope::class)->findOrFail($unbuildOrderB->id);

    expect(fn () => $unbuildOrderBUnscoped->update(['quantity' => 5]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('manufacturing_unbuild_orders', ['id' => $unbuildOrderB->id, 'quantity' => 5]);
});

it('forbids a user in company A from updating an unrelated field on a WorkCenter obtained from company B via an unscoped query', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $workCenterB = CompanyContext::runForAllCompanies(
        reason: 'test fixture setup — WorkCenter in company B',
        caller: __FILE__,
        callback: fn () => WorkCenter::factory()->create(['company_id' => $companyB->id]),
    );

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $workCenterBUnscoped = WorkCenter::withoutGlobalScope(CompanyScope::class)->findOrFail($workCenterB->id);

    expect(fn () => $workCenterBUnscoped->update(['note' => 'Renamed by A']))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('manufacturing_work_centers', ['id' => $workCenterB->id, 'note' => 'Renamed by A']);
});

it('forbids a user in company A from updating an unrelated field on an Operation whose BillOfMaterial belongs to company B', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $operation = CompanyContext::runForAllCompanies(
        reason: 'test fixture setup — Operation in company B',
        caller: __FILE__,
        callback: function () use ($companyB) {
            $productB = Product::factory()->create(['company_id' => $companyB->id]);
            $bomB = BillOfMaterial::factory()->create(['company_id' => $companyB->id, 'product_id' => $productB->id]);
            $workCenterB = WorkCenter::factory()->create(['company_id' => $companyB->id]);

            return Operation::create([
                'name'                => 'Cut',
                'bill_of_material_id' => $bomB->id,
                'work_center_id'      => $workCenterB->id,
            ]);
        },
    );

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    // Operation has no CompanyScope of its own — fetchable directly.
    expect(fn () => $operation->update(['name' => 'Renamed by A']))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('manufacturing_operations', ['id' => $operation->id, 'name' => 'Renamed by A']);
});

it('forbids a user in company A from updating an unrelated field on a WorkCenterCapacity whose WorkCenter belongs to company B', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $capacity = CompanyContext::runForAllCompanies(
        reason: 'test fixture setup — WorkCenterCapacity in company B',
        caller: __FILE__,
        callback: function () use ($companyB) {
            $workCenterB = WorkCenter::factory()->create(['company_id' => $companyB->id]);
            $productB = Product::factory()->create(['company_id' => $companyB->id]);

            return WorkCenterCapacity::create([
                'work_center_id' => $workCenterB->id,
                'product_id'     => $productB->id,
            ]);
        },
    );

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => $capacity->update(['capacity' => 99]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('manufacturing_work_center_capacities', ['id' => $capacity->id, 'capacity' => 99]);
});

it('forbids a user in company A from updating an unrelated field on a WorkOrder whose manufacturing Order belongs to company B', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    // See the manufacturing Order test above — Warehouse::create() needs a
    // real authenticated actor, not a no-user CompanyContext, to avoid
    // tripping an unrelated null-safety gap in inventories' Route model.
    $userB = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyB->id]));
    test()->actingAs($userB);

    Location::factory()->create(['type' => LocationType::PRODUCTION, 'company_id' => $companyB->id]);
    $warehouseB = Warehouse::create([
        'name'       => 'Warehouse B',
        'code'       => 'WHB2',
        'company_id' => $companyB->id,
    ])->fresh();
    $productB = Product::factory()->create(['company_id' => $companyB->id]);

    $orderB = Order::factory()->create([
        'company_id'          => $companyB->id,
        'product_id'          => $productB->id,
        'uom_id'              => $productB->uom_id,
        'operation_type_id'   => $warehouseB->manu_type_id,
        'source_location_id'  => $warehouseB->lot_stock_location_id,
        'bill_of_material_id' => null,
    ]);

    $workCenterB = WorkCenter::factory()->create(['company_id' => $companyB->id]);

    $workOrder = WorkOrder::create([
        'name'                   => 'Assemble',
        'manufacturing_order_id' => $orderB->id,
        'work_center_id'         => $workCenterB->id,
        'product_id'             => $productB->id,
        'uom_id'                 => $productB->uom_id,
        'operation_id'           => null,
    ]);

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => $workOrder->update(['barcode' => 'RENAMED-BY-A']))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('manufacturing_work_orders', ['id' => $workOrder->id, 'barcode' => 'RENAMED-BY-A']);
});

it('allows creating a WorkCenter under CompanyContext::runForAllCompanies with an explicit company_id', function () {
    $company = Company::factory()->create();

    $workCenter = CompanyContext::runForAllCompanies(
        reason: 'test: write positive under all_companies',
        caller: __FILE__,
        callback: fn () => WorkCenter::factory()->create(['company_id' => $company->id]),
    );

    expect($workCenter->company_id)->toBe($company->id);
});

it('allows creating a WorkCenter under CompanyContext::runForBootstrap with an explicit company_id', function () {
    $company = Company::factory()->create();

    $workCenter = CompanyContext::runForBootstrap(
        reason: 'test: write positive under bootstrap',
        caller: __FILE__,
        callback: fn () => WorkCenter::factory()->create(['company_id' => $company->id]),
    );

    expect($workCenter->company_id)->toBe($company->id);
});

<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Inventory\Enums\LocationType;
use Webkul\Inventory\Models\Location;
use Webkul\Inventory\Models\Warehouse;
use Webkul\Manufacturing\Models\BillOfMaterial;
use Webkul\Manufacturing\Models\BillOfMaterialByproduct;
use Webkul\Manufacturing\Models\BillOfMaterialLine;
use Webkul\Manufacturing\Models\Order;
use Webkul\Manufacturing\Models\UnbuildOrder;
use Webkul\Manufacturing\Models\WorkCenter;
use Webkul\Manufacturing\Models\WorkCenterProductivityLog;
use Webkul\Manufacturing\Models\WorkCenterProductivityLoss;
use Webkul\Product\Models\Product;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\UOM;

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

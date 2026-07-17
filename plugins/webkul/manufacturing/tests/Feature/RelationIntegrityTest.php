<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Manufacturing\Models\BillOfMaterial;
use Webkul\Manufacturing\Models\BillOfMaterialByproduct;
use Webkul\Manufacturing\Models\BillOfMaterialLine;
use Webkul\Manufacturing\Models\Order;
use Webkul\Manufacturing\Models\UnbuildOrder;
use Webkul\Product\Models\Product;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
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

it('allows a BillOfMaterialLine on a global (company_id null) BillOfMaterial to reference any company\'s Product', function () {
    $companyA = Company::factory()->create();

    $globalBom = CompanyContext::runForAllCompanies(
        reason: 'test fixture setup — global BOM template',
        caller: __FILE__,
        callback: fn () => BillOfMaterial::factory()->create(['company_id' => null]),
    );
    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $uomId = UOM::query()->value('id') ?? UOM::factory()->create()->id;

    $line = CompanyContext::runForAllCompanies(
        reason: 'test fixture setup — line on global BOM',
        caller: __FILE__,
        callback: fn () => BillOfMaterialLine::create([
            'bill_of_material_id' => $globalBom->id,
            'product_id'          => $productA->id,
            'uom_id'              => $uomId,
        ]),
    );

    expect($line->exists)->toBeTrue()
        ->and($line->company_id)->toBeNull();
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

// Note: the positive-path mirror of this ("Order may reference a global
// BillOfMaterial") is intentionally not a full Order::factory()->create()
// integration test — Order's created()/updated() side effects need a fully
// seeded warehouse/route/location graph (inventories' own Warehouse setup,
// several layers removed from company-scope) to avoid unrelated fixture
// failures. The guard itself — `if ($order->billOfMaterial?->company_id
// !== null) { assertRelatedBelongsToCompany(...) }` in Order's saving()
// hook — is the same skip-when-null pattern already proven by "allows a
// BillOfMaterialLine on a global (company_id null) BillOfMaterial..."
// above, on the sibling model it was modeled after.

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

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->create(['company_id' => $companyB->id]);
    // orderB is only a fixture here — this test is about UnbuildOrder's own
    // guard, not Order's. saveQuietly() skips Order's creating()/saving()
    // side effects entirely (procurement group, finished moves, the
    // manufacturing Warehouse observer chain), avoiding unrelated
    // location/operation-type setup this test doesn't otherwise need.
    $orderB = Order::factory()->make([
        'company_id'          => $companyB->id,
        'product_id'          => $productB->id,
        'uom_id'              => $productB->uom_id,
        'bill_of_material_id' => null,
    ]);
    $orderB->saveQuietly();

    expect(fn () => UnbuildOrder::factory()->create([
        'company_id'             => $companyA->id,
        'product_id'             => $productA->id,
        'manufacturing_order_id' => $orderB->id,
        'bill_of_material_id'    => null,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('manufacturing_unbuild_orders', ['company_id' => $companyA->id, 'manufacturing_order_id' => $orderB->id]);
});

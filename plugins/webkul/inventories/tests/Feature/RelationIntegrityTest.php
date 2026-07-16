<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Inventory\Models\Lot;
use Webkul\Inventory\Models\Move;
use Webkul\Inventory\Models\MoveLine;
use Webkul\Inventory\Models\OrderPoint;
use Webkul\Inventory\Models\ProductQuantity;
use Webkul\Inventory\Models\PutawayRule;
use Webkul\Inventory\Models\Scrap;
use Webkul\Product\Models\Packaging;
use Webkul\Product\Models\Product;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('inventories');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

// ── Move (highest blast radius: shared by Receipt/Delivery/Internal/Dropship) ─

it('allows a Move for company A referencing a Product from company A', function () {
    $companyA = Company::factory()->create();

    $product = Product::factory()->create(['company_id' => $companyA->id]);

    $move = Move::factory()->create([
        'company_id' => $companyA->id,
        'product_id' => $product->id,
    ]);

    expect($move->exists)->toBeTrue();
});

it('forbids a Move for company A referencing a Product from company B, even for a user allowed in both', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $productB = Product::factory()->create(['company_id' => $companyB->id]);

    expect(fn () => Move::factory()->create([
        'company_id' => $companyA->id,
        'product_id' => $productB->id,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('inventories_moves', ['company_id' => $companyA->id, 'product_id' => $productB->id]);
});

it('allows a Move for company A referencing a Packaging from company A', function () {
    $companyA = Company::factory()->create();

    $product = Product::factory()->create(['company_id' => $companyA->id]);
    $packaging = Packaging::factory()->create(['product_id' => $product->id, 'company_id' => $companyA->id]);

    $move = Move::factory()->create([
        'company_id'           => $companyA->id,
        'product_id'           => $product->id,
        'product_packaging_id' => $packaging->id,
    ]);

    expect($move->exists)->toBeTrue();
});

it('forbids a Move for company A referencing a Packaging from company B, even for a user allowed in both', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->create(['company_id' => $companyB->id]);
    $packagingB = Packaging::factory()->create(['product_id' => $productB->id, 'company_id' => $companyB->id]);

    expect(fn () => Move::factory()->create([
        'company_id'           => $companyA->id,
        'product_id'           => $productA->id,
        'product_packaging_id' => $packagingB->id,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('inventories_moves', ['company_id' => $companyA->id, 'product_packaging_id' => $packagingB->id]);
});

it('forbids changing a Move\'s product_id to a Product from a different company on update', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->create(['company_id' => $companyB->id]);
    $move = Move::factory()->create(['company_id' => $companyA->id, 'product_id' => $productA->id]);

    expect(fn () => $move->update(['product_id' => $productB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('inventories_moves', ['id' => $move->id, 'product_id' => $productA->id]);
});

it('forbids changing only a Move\'s company_id to a company that mismatches its unchanged product', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $move = Move::factory()->create(['company_id' => $companyA->id, 'product_id' => $productA->id]);

    expect(fn () => $move->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('inventories_moves', ['id' => $move->id, 'company_id' => $companyA->id]);
});

// ── MoveLine ─────────────────────────────────────────────────────────────────

it('forbids a MoveLine for company A referencing a Product from company B, even for a user allowed in both', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $productB = Product::factory()->create(['company_id' => $companyB->id]);

    expect(fn () => MoveLine::factory()->create([
        'company_id' => $companyA->id,
        'product_id' => $productB->id,
        // Matches productB's own uom, not the factory's independent
        // default — otherwise an unrelated UOM-category mismatch
        // exception fires first and masks the assertion this test is
        // actually after.
        'uom_id'     => $productB->uom_id,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('inventories_move_lines', ['company_id' => $companyA->id, 'product_id' => $productB->id]);
});

it('forbids changing a MoveLine\'s product_id to a Product from a different company on update', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    // Same uom_id on both products: computeUOMQty() (MoveLine's own
    // saving() hook, which runs before both creating() and updating())
    // would otherwise throw its own unrelated UOM-category mismatch
    // exception first and mask the company guard this test is after.
    $uom = \Webkul\Support\Models\UOM::query()->value('id') ?? \Webkul\Support\Models\UOM::factory()->create()->id;
    $productA = Product::factory()->create(['company_id' => $companyA->id, 'uom_id' => $uom]);
    $productB = Product::factory()->create(['company_id' => $companyB->id, 'uom_id' => $uom]);
    // The parent Move's own uom_id must also match: MoveLine::created()
    // recomputes Move::computeQuantity(), which compares each line's uom
    // against the Move's own — same rationale as the line's uom_id above.
    $parentMove = Move::factory()->create(['company_id' => $companyA->id, 'product_id' => $productA->id, 'uom_id' => $uom]);
    $moveLine = MoveLine::factory()->create(['move_id' => $parentMove->id, 'company_id' => $companyA->id, 'product_id' => $productA->id, 'uom_id' => $uom]);

    expect(fn () => $moveLine->update(['product_id' => $productB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('inventories_move_lines', ['id' => $moveLine->id, 'product_id' => $productA->id]);
});

// ── ProductQuantity ──────────────────────────────────────────────────────────

it('forbids a ProductQuantity for company A referencing a Product from company B, even for a user allowed in both', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $productB = Product::factory()->create(['company_id' => $companyB->id]);

    expect(fn () => ProductQuantity::factory()->create([
        'company_id' => $companyA->id,
        'product_id' => $productB->id,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('inventories_product_quantities', ['company_id' => $companyA->id, 'product_id' => $productB->id]);
});

it('forbids changing a ProductQuantity\'s product_id to a Product from a different company on update', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->create(['company_id' => $companyB->id]);
    $quantity = ProductQuantity::factory()->create(['company_id' => $companyA->id, 'product_id' => $productA->id]);

    expect(fn () => $quantity->update(['product_id' => $productB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('inventories_product_quantities', ['id' => $quantity->id, 'product_id' => $productA->id]);
});

// ── Scrap ────────────────────────────────────────────────────────────────────

it('forbids a Scrap for company A referencing a Product from company B, even for a user allowed in both', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $productB = Product::factory()->create(['company_id' => $companyB->id]);

    expect(fn () => Scrap::factory()->create([
        'company_id' => $companyA->id,
        'product_id' => $productB->id,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('inventories_scraps', ['company_id' => $companyA->id, 'product_id' => $productB->id]);
});

it('forbids changing a Scrap\'s product_id to a Product from a different company on update', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->create(['company_id' => $companyB->id]);
    $scrap = Scrap::factory()->create(['company_id' => $companyA->id, 'product_id' => $productA->id]);

    expect(fn () => $scrap->update(['product_id' => $productB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('inventories_scraps', ['id' => $scrap->id, 'product_id' => $productA->id]);
});

// ── Lot ──────────────────────────────────────────────────────────────────────

it('forbids a Lot for company A referencing a Product from company B, even for a user allowed in both', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $productB = Product::factory()->create(['company_id' => $companyB->id]);

    expect(fn () => Lot::factory()->create([
        'company_id' => $companyA->id,
        'product_id' => $productB->id,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('inventories_lots', ['company_id' => $companyA->id, 'product_id' => $productB->id]);
});

// ── PutawayRule ──────────────────────────────────────────────────────────────

it('forbids a PutawayRule for company A referencing a Product from company B, even for a user allowed in both', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $productB = Product::factory()->create(['company_id' => $companyB->id]);

    expect(fn () => PutawayRule::factory()->create([
        'company_id' => $companyA->id,
        'product_id' => $productB->id,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('inventories_putaway_rules', ['company_id' => $companyA->id, 'product_id' => $productB->id]);
});

// ── OrderPoint ───────────────────────────────────────────────────────────────

it('forbids an OrderPoint for company A referencing a Product from company B, even for a user allowed in both', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $productB = Product::factory()->create(['company_id' => $companyB->id]);

    expect(fn () => OrderPoint::factory()->create([
        'company_id' => $companyA->id,
        'product_id' => $productB->id,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('inventories_order_points', ['company_id' => $companyA->id, 'product_id' => $productB->id]);
});

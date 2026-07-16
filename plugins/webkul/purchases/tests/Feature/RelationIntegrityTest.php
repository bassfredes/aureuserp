<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Product\Models\Packaging;
use Webkul\Product\Models\Product;
use Webkul\Purchase\Models\Order;
use Webkul\Purchase\Models\OrderLine;
use Webkul\Purchase\Models\Requisition;
use Webkul\Purchase\Models\RequisitionLine;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('purchases');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

// ── OrderLine ────────────────────────────────────────────────────────────────

it('allows a purchases OrderLine for company A referencing a Product from company A', function () {
    $companyA = Company::factory()->create();

    $order = Order::factory()->create(['company_id' => $companyA->id]);
    $product = Product::factory()->create(['company_id' => $companyA->id]);

    $line = OrderLine::factory()->create([
        'order_id'   => $order->id,
        'company_id' => $companyA->id,
        'product_id' => $product->id,
    ]);

    expect($line->exists)->toBeTrue();
});

it('forbids a purchases OrderLine for company A referencing a Product from company B, even for a user allowed in both', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $order = Order::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->create(['company_id' => $companyB->id]);

    expect(fn () => OrderLine::factory()->create([
        'order_id'   => $order->id,
        'company_id' => $companyA->id,
        'product_id' => $productB->id,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('purchases_order_lines', ['order_id' => $order->id, 'product_id' => $productB->id]);
});

it('allows a purchases OrderLine for company A referencing a Packaging from company A', function () {
    $companyA = Company::factory()->create();

    $order = Order::factory()->create(['company_id' => $companyA->id]);
    $product = Product::factory()->create(['company_id' => $companyA->id]);
    $packaging = Packaging::factory()->create(['product_id' => $product->id, 'company_id' => $companyA->id]);

    $line = OrderLine::factory()->create([
        'order_id'             => $order->id,
        'company_id'           => $companyA->id,
        'product_id'           => $product->id,
        'product_packaging_id' => $packaging->id,
    ]);

    expect($line->exists)->toBeTrue();
});

it('forbids a purchases OrderLine for company A referencing a Packaging from company B, even for a user allowed in both', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $order = Order::factory()->create(['company_id' => $companyA->id]);
    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->create(['company_id' => $companyB->id]);
    $packagingB = Packaging::factory()->create(['product_id' => $productB->id, 'company_id' => $companyB->id]);

    expect(fn () => OrderLine::factory()->create([
        'order_id'             => $order->id,
        'company_id'           => $companyA->id,
        'product_id'           => $productA->id,
        'product_packaging_id' => $packagingB->id,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('purchases_order_lines', ['order_id' => $order->id, 'product_packaging_id' => $packagingB->id]);
});

it('forbids changing a purchases OrderLine\'s product_id to a Product from a different company on update', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $order = Order::factory()->create(['company_id' => $companyA->id]);
    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->create(['company_id' => $companyB->id]);
    $line = OrderLine::factory()->create([
        'order_id'   => $order->id,
        'company_id' => $companyA->id,
        'product_id' => $productA->id,
    ]);

    expect(fn () => $line->update(['product_id' => $productB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('purchases_order_lines', ['id' => $line->id, 'product_id' => $productA->id]);
});

it('forbids changing only a purchases OrderLine\'s company_id to a company that mismatches its unchanged product', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $order = Order::factory()->create(['company_id' => $companyA->id]);
    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $line = OrderLine::factory()->create([
        'order_id'   => $order->id,
        'company_id' => $companyA->id,
        'product_id' => $productA->id,
    ]);

    expect(fn () => $line->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('purchases_order_lines', ['id' => $line->id, 'company_id' => $companyA->id]);
});

// ── RequisitionLine ──────────────────────────────────────────────────────────

it('allows a RequisitionLine for company A referencing a Product from company A', function () {
    $companyA = Company::factory()->create();

    $requisition = Requisition::factory()->create(['company_id' => $companyA->id]);
    $product = Product::factory()->create(['company_id' => $companyA->id]);

    $line = RequisitionLine::factory()->create([
        'requisition_id' => $requisition->id,
        'company_id'     => $companyA->id,
        'product_id'     => $product->id,
    ]);

    expect($line->exists)->toBeTrue();
});

it('forbids a RequisitionLine for company A referencing a Product from company B, even for a user allowed in both', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $requisition = Requisition::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->create(['company_id' => $companyB->id]);

    expect(fn () => RequisitionLine::factory()->create([
        'requisition_id' => $requisition->id,
        'company_id'     => $companyA->id,
        'product_id'     => $productB->id,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('purchases_requisition_lines', ['requisition_id' => $requisition->id, 'product_id' => $productB->id]);
});

it('forbids changing a RequisitionLine\'s product_id to a Product from a different company on update', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $requisition = Requisition::factory()->create(['company_id' => $companyA->id]);
    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->create(['company_id' => $companyB->id]);
    $line = RequisitionLine::factory()->create([
        'requisition_id' => $requisition->id,
        'company_id'     => $companyA->id,
        'product_id'     => $productA->id,
    ]);

    expect(fn () => $line->update(['product_id' => $productB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('purchases_requisition_lines', ['id' => $line->id, 'product_id' => $productA->id]);
});

it('forbids changing only a RequisitionLine\'s company_id to a company that mismatches its unchanged product', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $requisition = Requisition::factory()->create(['company_id' => $companyA->id]);
    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $line = RequisitionLine::factory()->create([
        'requisition_id' => $requisition->id,
        'company_id'     => $companyA->id,
        'product_id'     => $productA->id,
    ]);

    expect(fn () => $line->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('purchases_requisition_lines', ['id' => $line->id, 'company_id' => $companyA->id]);
});

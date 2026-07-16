<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Product\Models\Packaging;
use Webkul\Product\Models\Product;
use Webkul\Sale\Filament\Clusters\Orders\Resources\QuotationResource;
use Webkul\Sale\Models\Order;
use Webkul\Sale\Models\OrderLine;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('sales');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

/**
 * D5b (aureuserp#137): read isolation via CompanyScope is not the same
 * guarantee as relation integrity — a user authorized in both A and B must
 * not be able to create a company-A OrderLine referencing a company-B
 * Product/Packaging, even though they can individually see both records.
 */
it('allows an OrderLine for company A referencing a Product from company A', function () {
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

it('forbids an OrderLine for company A referencing a Product from company B, even for a user allowed in both', function () {
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

    $this->assertDatabaseMissing('sales_order_lines', ['order_id' => $order->id, 'product_id' => $productB->id]);
});

it('allows an OrderLine for company A referencing a Packaging from company A', function () {
    $companyA = Company::factory()->create();

    $order = Order::factory()->create(['company_id' => $companyA->id]);
    $product = Product::factory()->create(['company_id' => $companyA->id]);
    $packaging = Packaging::factory()->create(['product_id' => $product->id, 'company_id' => $companyA->id]);

    $line = OrderLine::factory()->create([
        'order_id'              => $order->id,
        'company_id'            => $companyA->id,
        'product_id'            => $product->id,
        'product_packaging_id'  => $packaging->id,
    ]);

    expect($line->exists)->toBeTrue();
});

it('forbids an OrderLine for company A referencing a Packaging from company B, even for a user allowed in both', function () {
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

    $this->assertDatabaseMissing('sales_order_lines', ['order_id' => $order->id, 'product_packaging_id' => $packagingB->id]);
});

it('forbids changing an OrderLine\'s product_id to a Product from a different company on update', function () {
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

    $this->assertDatabaseHas('sales_order_lines', ['id' => $line->id, 'product_id' => $productA->id]);
});

it('forbids changing only an OrderLine\'s company_id to a company that mismatches its unchanged product', function () {
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

    $this->assertDatabaseHas('sales_order_lines', ['id' => $line->id, 'company_id' => $companyA->id]);
});

// ── Non-API write path: Filament's repeater mutator ─────────────────────────

it('derives OrderLine.company_id from the Order record in the Filament repeater mutator, not the acting user default', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    // Acting user's default company (B) deliberately differs from the
    // Order being edited (A) — this is exactly the same-aggregate
    // mismatch QuotationResource::mutateProductRelationship() used to
    // produce before this fix (D5b, aureuserp#137).
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyB->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $order = Order::factory()->create(['company_id' => $companyA->id]);
    $product = Product::factory()->create(['company_id' => $companyA->id]);

    $mutated = QuotationResource::mutateProductRelationship([
        'product_id' => $product->id,
    ], $order);

    expect($mutated['company_id'])->toBe($companyA->id);
});

it('forbids creating an OrderLine through the Filament repeater mutator for a Product from a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $order = Order::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->create(['company_id' => $companyB->id]);

    $mutated = QuotationResource::mutateProductRelationship([
        'product_id' => $productB->id,
    ], $order);

    expect($mutated['company_id'])->toBe($companyA->id);

    expect(fn () => $order->lines()->create($mutated))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('sales_order_lines', ['order_id' => $order->id, 'product_id' => $productB->id]);
});

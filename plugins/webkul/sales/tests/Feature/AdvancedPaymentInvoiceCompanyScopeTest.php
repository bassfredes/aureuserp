<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Webkul\Sale\Models\AdvancedPaymentInvoice;
use Webkul\Sale\Models\AdvancedPaymentInvoiceOrderSale;
use Webkul\Sale\Models\Order;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

/**
 * #138 A4F: AdvancedPaymentInvoice (own company_id, HasStrictCompanyId) and
 * AdvancedPaymentInvoiceOrderSale (no company_id of its own, ownership
 * derived from the invoice and cross-checked against the referenced
 * Order's company_id — closed the two real gaps of the residual inventory,
 * review 4824921645 / comment 5138552733).
 */
beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('sales');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

// ── AdvancedPaymentInvoice: read isolation (test 1) ─────────────────────────

it('hides an AdvancedPaymentInvoice from a company the user is not allowed to see', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $invoiceA = CompanyContext::runForCompany($companyA->id, reason: 'test fixture setup', caller: __FILE__, callback: fn () => AdvancedPaymentInvoice::factory()->create(['company_id' => $companyA->id]));
    $invoiceB = CompanyContext::runForCompany($companyB->id, reason: 'test fixture setup', caller: __FILE__, callback: fn () => AdvancedPaymentInvoice::factory()->create(['company_id' => $companyB->id]));

    $userA = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($userA);

    expect(AdvancedPaymentInvoice::find($invoiceA->id))->not->toBeNull();
    expect(AdvancedPaymentInvoice::find($invoiceB->id))->toBeNull();
});

// ── AdvancedPaymentInvoice: create (tests 2, 3) ─────────────────────────────

it('allows creating an AdvancedPaymentInvoice for the acting user\'s own company', function () {
    $companyA = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $invoice = AdvancedPaymentInvoice::factory()->create(['company_id' => $companyA->id]);

    expect($invoice->exists)->toBeTrue();
    expect($invoice->company_id)->toBe($companyA->id);
});

it('forbids creating an AdvancedPaymentInvoice when no company_id can be resolved', function () {
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => null]));
    test()->actingAs($user);

    expect(fn () => AdvancedPaymentInvoice::factory()->create(['company_id' => null]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('sales_advance_payment_invoices', 0);
});

it('forbids creating an AdvancedPaymentInvoice for a company the acting user is not allowed to write to', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => AdvancedPaymentInvoice::factory()->create(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('sales_advance_payment_invoices', ['company_id' => $companyB->id]);
});

// ── AdvancedPaymentInvoice: update / retarget (test 4) ──────────────────────

it('forbids changing an AdvancedPaymentInvoice\'s company_id on update', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $invoice = AdvancedPaymentInvoice::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => $invoice->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('sales_advance_payment_invoices', ['id' => $invoice->id, 'company_id' => $companyA->id]);
});

// ── AdvancedPaymentInvoice: creator_id write path (#138 A4F review 4827999112) ─

it('forbids creating an AdvancedPaymentInvoice with an explicit creator_id that has no membership in the target company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $outsider = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => AdvancedPaymentInvoice::factory()->create(['company_id' => $companyA->id, 'creator_id' => $outsider->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('sales_advance_payment_invoices', ['creator_id' => $outsider->id]);
});

it('forbids creating an AdvancedPaymentInvoice with a nonexistent creator_id', function () {
    $companyA = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => AdvancedPaymentInvoice::factory()->create(['company_id' => $companyA->id, 'creator_id' => 999999999]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('sales_advance_payment_invoices', 0);
});

it('forbids changing an AdvancedPaymentInvoice\'s creator_id on update, leaving the original row intact', function () {
    $companyA = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $otherMember = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $invoice = AdvancedPaymentInvoice::factory()->create(['company_id' => $companyA->id]);
    $originalCreatorId = $invoice->creator_id;

    // Even a same-company, otherwise-legitimate member is rejected — the
    // guard is immutability, not merely re-checking membership.
    expect(fn () => $invoice->update(['creator_id' => $otherMember->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('sales_advance_payment_invoices', ['id' => $invoice->id, 'creator_id' => $originalCreatorId]);
});

it('forbids updating an AdvancedPaymentInvoice to a nonexistent creator_id, leaving the original row intact', function () {
    $companyA = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $invoice = AdvancedPaymentInvoice::factory()->create(['company_id' => $companyA->id]);
    $originalCreatorId = $invoice->creator_id;

    expect(fn () => $invoice->update(['creator_id' => 999999999]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('sales_advance_payment_invoices', ['id' => $invoice->id, 'creator_id' => $originalCreatorId]);
});

// ── AdvancedPaymentInvoice: delete (test 5) ─────────────────────────────────

it('forbids deleting an AdvancedPaymentInvoice from a different company than the acting user', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $invoiceB = CompanyContext::runForCompany($companyB->id, reason: 'test fixture setup', caller: __FILE__, callback: fn () => AdvancedPaymentInvoice::factory()->create(['company_id' => $companyB->id]));

    $userA = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($userA);

    // CompanyScope already hides company B's row from a normal query — the
    // deleting() guard is defense in depth for an instance obtained some
    // other way (forAllCompanies(), a system context, ...), so fetch it
    // explicitly bypassing the read scope to exercise that guard directly.
    $bypassed = AdvancedPaymentInvoice::withoutGlobalScope(CompanyScope::class)->find($invoiceB->id);

    expect(fn () => $bypassed->delete())->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('sales_advance_payment_invoices', ['id' => $invoiceB->id]);
});

// ── AdvancedPaymentInvoiceOrderSale: attach / detach (tests 6, 7, 12) ───────

it('allows attaching an Order to an AdvancedPaymentInvoice from the same company', function () {
    $companyA = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $invoice = AdvancedPaymentInvoice::factory()->create(['company_id' => $companyA->id]);
    $order = Order::factory()->create(['company_id' => $companyA->id]);

    $invoice->orders()->attach($order->id);

    $this->assertDatabaseHas('sales_advance_payment_invoice_order_sales', [
        'advance_payment_invoice_id' => $invoice->id,
        'order_id'                   => $order->id,
    ]);
});

it('forbids attaching an Order from a different company than the AdvancedPaymentInvoice, without modifying any row', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $invoice = AdvancedPaymentInvoice::factory()->create(['company_id' => $companyA->id]);
    $orderB = Order::factory()->create(['company_id' => $companyB->id]);

    expect(fn () => $invoice->orders()->attach($orderB->id))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('sales_advance_payment_invoice_order_sales', [
        'advance_payment_invoice_id' => $invoice->id,
        'order_id'                   => $orderB->id,
    ]);
});

it('forbids detaching an AdvancedPaymentInvoiceOrderSale row for an actor not authorized for its company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    [$invoice, $order] = CompanyContext::runForCompany($companyA->id, reason: 'test fixture setup', caller: __FILE__, callback: function () use ($companyA) {
        $invoice = AdvancedPaymentInvoice::factory()->create(['company_id' => $companyA->id]);
        $order = Order::factory()->create(['company_id' => $companyA->id]);
        $invoice->orders()->attach($order->id);

        return [$invoice, $order];
    });

    $userB = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyB->id]));
    test()->actingAs($userB);

    $bypassedInvoice = AdvancedPaymentInvoice::withoutGlobalScope(CompanyScope::class)->find($invoice->id);

    expect(fn () => $bypassedInvoice->orders()->detach($order->id))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('sales_advance_payment_invoice_order_sales', [
        'advance_payment_invoice_id' => $invoice->id,
        'order_id'                   => $order->id,
    ]);
});

// ── AdvancedPaymentInvoiceOrderSale: direct create (test 8) ─────────────────

it('forbids creating an AdvancedPaymentInvoiceOrderSale directly for a cross-company pair', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $invoiceA = AdvancedPaymentInvoice::factory()->create(['company_id' => $companyA->id]);
    $orderB = Order::factory()->create(['company_id' => $companyB->id]);

    expect(fn () => AdvancedPaymentInvoiceOrderSale::create([
        'advance_payment_invoice_id' => $invoiceA->id,
        'order_id'                   => $orderB->id,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('sales_advance_payment_invoice_order_sales', [
        'advance_payment_invoice_id' => $invoiceA->id,
        'order_id'                   => $orderB->id,
    ]);
});

// ── AdvancedPaymentInvoiceOrderSale: missing/hidden parent (test 9) ─────────

it('forbids creating an AdvancedPaymentInvoiceOrderSale for a nonexistent invoice', function () {
    $companyA = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $order = Order::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => AdvancedPaymentInvoiceOrderSale::create([
        'advance_payment_invoice_id' => 999999999,
        'order_id'                   => $order->id,
    ]))->toThrow(AuthorizationException::class);
});

it('forbids creating an AdvancedPaymentInvoiceOrderSale for an invoice hidden from the acting user\'s company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $invoiceB = CompanyContext::runForCompany($companyB->id, reason: 'test fixture setup', caller: __FILE__, callback: fn () => AdvancedPaymentInvoice::factory()->create(['company_id' => $companyB->id]));

    $userA = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($userA);

    $orderA = Order::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => AdvancedPaymentInvoiceOrderSale::create([
        'advance_payment_invoice_id' => $invoiceB->id,
        'order_id'                   => $orderA->id,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('sales_advance_payment_invoice_order_sales', ['order_id' => $orderA->id]);
});

// ── AdvancedPaymentInvoiceOrderSale: retargeting (test 10) ──────────────────

it('forbids retargeting an AdvancedPaymentInvoiceOrderSale\'s advance_payment_invoice_id, leaving the original row intact', function () {
    $companyA = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $invoiceA1 = AdvancedPaymentInvoice::factory()->create(['company_id' => $companyA->id]);
    $invoiceA2 = AdvancedPaymentInvoice::factory()->create(['company_id' => $companyA->id]);
    $order = Order::factory()->create(['company_id' => $companyA->id]);

    $invoiceA1->orders()->attach($order->id);

    $pivot = AdvancedPaymentInvoiceOrderSale::query()
        ->where('advance_payment_invoice_id', $invoiceA1->id)
        ->where('order_id', $order->id)
        ->firstOrFail();

    expect(fn () => $pivot->update(['advance_payment_invoice_id' => $invoiceA2->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('sales_advance_payment_invoice_order_sales', [
        'advance_payment_invoice_id' => $invoiceA1->id,
        'order_id'                   => $order->id,
    ]);
});

it('forbids retargeting an AdvancedPaymentInvoiceOrderSale\'s order_id, leaving the original row intact', function () {
    $companyA = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $invoice = AdvancedPaymentInvoice::factory()->create(['company_id' => $companyA->id]);
    $orderA1 = Order::factory()->create(['company_id' => $companyA->id]);
    $orderA2 = Order::factory()->create(['company_id' => $companyA->id]);

    $invoice->orders()->attach($orderA1->id);

    $pivot = AdvancedPaymentInvoiceOrderSale::query()
        ->where('advance_payment_invoice_id', $invoice->id)
        ->where('order_id', $orderA1->id)
        ->firstOrFail();

    expect(fn () => $pivot->update(['order_id' => $orderA2->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('sales_advance_payment_invoice_order_sales', [
        'advance_payment_invoice_id' => $invoice->id,
        'order_id'                   => $orderA1->id,
    ]);
});

// ── AdvancedPaymentInvoiceOrderSale: sync() / updateExistingPivot() (test 11) ─

it('forbids sync() from retargeting an AdvancedPaymentInvoiceOrderSale to a cross-company Order', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $invoice = AdvancedPaymentInvoice::factory()->create(['company_id' => $companyA->id]);
    $orderA = Order::factory()->create(['company_id' => $companyA->id]);
    $orderB = Order::factory()->create(['company_id' => $companyB->id]);

    $invoice->orders()->attach($orderA->id);

    // sync() diffs against the new set and detaches-then-attaches — not
    // wrapped in a transaction by Eloquent itself — so the pre-existing
    // orderA row is legitimately detached (going through the deleting()
    // guard, which allows it: same company) before the attach of orderB
    // ever runs and throws. The guarantee this test actually verifies is
    // that no cross-company row is ever created — not that sync() as a
    // whole is atomic, which it never was for this or any other pivot in
    // this codebase.
    expect(fn () => $invoice->orders()->sync([$orderB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('sales_advance_payment_invoice_order_sales', [
        'advance_payment_invoice_id' => $invoice->id,
        'order_id'                   => $orderB->id,
    ]);
});

it('forbids updateExistingPivot() from smuggling any change through an already-persisted AdvancedPaymentInvoiceOrderSale row', function () {
    $companyA = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $invoiceA1 = AdvancedPaymentInvoice::factory()->create(['company_id' => $companyA->id]);
    $invoiceA2 = AdvancedPaymentInvoice::factory()->create(['company_id' => $companyA->id]);
    $order = Order::factory()->create(['company_id' => $companyA->id]);

    $invoiceA1->orders()->attach($order->id);

    expect(fn () => $invoiceA1->orders()->updateExistingPivot($order->id, ['advance_payment_invoice_id' => $invoiceA2->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('sales_advance_payment_invoice_order_sales', [
        'advance_payment_invoice_id' => $invoiceA1->id,
        'order_id'                   => $order->id,
    ]);
});

// ── Real production write path: SaleManager::createInvoice()'s own pattern (test 13) ─

it('leaves no cross-company row when the AdvancedPaymentInvoice create+attach pattern used by SaleManager::createInvoice is exercised for one company', function () {
    $companyA = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $order = Order::factory()->create(['company_id' => $companyA->id]);

    // Mirrors SaleManager::createInvoice() exactly: company_id/currency_id
    // taken from the Order, creator_id from the acting user, then the same
    // Order attached via orders()->attach() — the only production write
    // path to these two models (SaleManager.php:117-134).
    $invoice = AdvancedPaymentInvoice::create([
        'advance_payment_method' => 'percentage',
        'amount'                 => 100,
        'currency_id'            => $order->currency_id,
        'company_id'             => $order->company_id,
        'creator_id'             => Auth::id(),
        'deduct_down_payments'   => true,
        'consolidated_billing'   => true,
    ]);

    $invoice->orders()->attach($order->id);

    $this->assertDatabaseHas('sales_advance_payment_invoice_order_sales', [
        'advance_payment_invoice_id' => $invoice->id,
        'order_id'                   => $order->id,
    ]);
    expect(AdvancedPaymentInvoiceOrderSale::query()->count())->toBe(1);
});

// ── Fail-closed / system context (test 14) ──────────────────────────────────

it('fails closed on AdvancedPaymentInvoice reads and writes when there is no authenticated user and no system context', function () {
    $companyA = Company::factory()->create();

    CompanyContext::runForCompany($companyA->id, reason: 'test fixture setup', caller: __FILE__, callback: fn () => AdvancedPaymentInvoice::factory()->create(['company_id' => $companyA->id]));

    Auth::logout();

    expect(AdvancedPaymentInvoice::query()->count())->toBe(0);

    expect(fn () => AdvancedPaymentInvoice::factory()->create(['company_id' => $companyA->id]))
        ->toThrow(AuthorizationException::class);
});

it('allows an explicit company system context to create and read AdvancedPaymentInvoice rows with no authenticated user', function () {
    $companyA = Company::factory()->create();

    $invoice = CompanyContext::runForCompany(
        $companyA->id,
        reason: 'test: company system context write',
        caller: __FILE__,
        callback: fn () => AdvancedPaymentInvoice::factory()->create(['company_id' => $companyA->id]),
    );

    $visible = CompanyContext::runForCompany(
        $companyA->id,
        reason: 'test: company system context read',
        caller: __FILE__,
        callback: fn () => AdvancedPaymentInvoice::find($invoice->id),
    );

    expect($visible)->not->toBeNull();
});

// ── Factory/fixture coherence (test 15) ──────────────────────────────────────

it('produces a same-company AdvancedPaymentInvoiceOrderSale pair from the bare factory default', function () {
    CompanyContext::runForAllCompanies(reason: 'test: bare factory coherence', caller: __FILE__, callback: function () {
        $pivot = AdvancedPaymentInvoiceOrderSale::factory()->create();

        $invoice = AdvancedPaymentInvoice::withoutGlobalScope(CompanyScope::class)->find($pivot->advance_payment_invoice_id);
        $order = Order::withoutGlobalScope(CompanyScope::class)->find($pivot->order_id);

        expect($invoice->company_id)->not->toBeNull();
        expect($invoice->company_id)->toBe($order->company_id);
    });
});

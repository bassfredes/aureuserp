<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Webkul\Account\Models\Journal;
use Webkul\Product\Models\Product;
use Webkul\Sale\Enums\OrderState;
use Webkul\Sale\Models\Order;
use Webkul\Sale\Models\OrderLine;
use Webkul\Sale\Models\OrderTemplate;
use Webkul\Sale\Models\OrderTemplateProduct;
use Webkul\Sale\Models\Scopes\ParentDerivedCompanyScope;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

/**
 * #138 PR4 A4H: OrderTemplate (own company_id, HasStrictCompanyId, no
 * SoftDeletes) and OrderTemplateProduct (company_id column present but
 * authority in the parent template, the OrderLine shape), plus the
 * Order.sale_order_template_id relation contract — the other half of the
 * same lesson that made A4G include Order.team_id.
 */
beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('sales');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

function templateMemberOf(Company $company): User
{
    return User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id]));
}

function templateFor(Company $company): OrderTemplate
{
    return CompanyContext::runForCompany(
        $company->id,
        reason: 'test fixture setup',
        caller: __FILE__,
        callback: fn () => OrderTemplate::factory()->create(['company_id' => $company->id]),
    );
}

/**
 * No CompanyContext here on purpose: Product carries HasCompanyScope but
 * no write-side guard, so an explicit company_id is honoured whoever the
 * actor is, and opening a system context while authenticated is itself a
 * LogicException by design (ADR 0007).
 */
function productFor(Company $company): Product
{
    return Product::factory()->create(['company_id' => $company->id]);
}

// ── OrderTemplate: read isolation ───────────────────────────────────────────

it('hides an OrderTemplate from a company the user is not allowed to see', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $templateA = templateFor($companyA);
    $templateB = templateFor($companyB);

    test()->actingAs(templateMemberOf($companyA));

    expect(OrderTemplate::find($templateA->id))->not->toBeNull();
    expect(OrderTemplate::find($templateB->id))->toBeNull();
});

// ── OrderTemplate: create ───────────────────────────────────────────────────

it('allows creating an OrderTemplate for the acting user\'s own company', function () {
    $companyA = Company::factory()->create();

    test()->actingAs(templateMemberOf($companyA));

    $template = OrderTemplate::factory()->create(['company_id' => $companyA->id]);

    expect($template->exists)->toBeTrue();
    expect($template->company_id)->toBe($companyA->id);
});

it('forbids creating an OrderTemplate for a company the acting user is not allowed to write to', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    test()->actingAs(templateMemberOf($companyA));

    expect(fn () => OrderTemplate::factory()->create(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('sales_order_templates', ['company_id' => $companyB->id]);
});

it('forbids creating an OrderTemplate when no company_id can be resolved', function () {
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => null]));
    test()->actingAs($user);

    $before = DB::table('sales_order_templates')->count();

    expect(fn () => OrderTemplate::factory()->create(['company_id' => null]))
        ->toThrow(AuthorizationException::class);

    expect(DB::table('sales_order_templates')->count())->toBe($before);
});

// ── OrderTemplate: immutability and creator ─────────────────────────────────

it('forbids changing an OrderTemplate\'s company_id on update', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = templateMemberOf($companyA);
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $template = OrderTemplate::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => $template->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('sales_order_templates', ['id' => $template->id, 'company_id' => $companyA->id]);
});

it('forbids an explicit creator_id without membership and a nonexistent one when creating an OrderTemplate', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $outsider = templateMemberOf($companyB);

    test()->actingAs(templateMemberOf($companyA));

    expect(fn () => OrderTemplate::factory()->create(['company_id' => $companyA->id, 'creator_id' => $outsider->id]))
        ->toThrow(AuthorizationException::class);

    expect(fn () => OrderTemplate::factory()->create(['company_id' => $companyA->id, 'creator_id' => 999999999]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('sales_order_templates', ['creator_id' => $outsider->id]);
    $this->assertDatabaseMissing('sales_order_templates', ['creator_id' => 999999999]);
});

it('forbids changing an OrderTemplate\'s creator_id on update, including from a historic NULL', function () {
    $companyA = Company::factory()->create();

    $user = templateMemberOf($companyA);
    $mate = templateMemberOf($companyA);
    test()->actingAs($user);

    $template = OrderTemplate::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => $template->update(['creator_id' => $mate->id]))
        ->toThrow(AuthorizationException::class);

    DB::table('sales_order_templates')->where('id', $template->id)->update(['creator_id' => null]);

    $historic = OrderTemplate::findOrFail($template->id);

    expect(fn () => $historic->update(['creator_id' => $mate->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('sales_order_templates', ['id' => $template->id, 'creator_id' => null]);
});

// ── OrderTemplate: journal ──────────────────────────────────────────────────

it('forbids an OrderTemplate whose journal belongs to another company, on create and on update', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $journalB = CompanyContext::runForCompany($companyB->id, reason: 'test fixture setup', caller: __FILE__, callback: fn () => Journal::factory()->create(['company_id' => $companyB->id]));

    test()->actingAs(templateMemberOf($companyA));

    expect(fn () => OrderTemplate::factory()->create(['company_id' => $companyA->id, 'journal_id' => $journalB->id]))
        ->toThrow(AuthorizationException::class);

    $template = OrderTemplate::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => $template->update(['journal_id' => $journalB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('sales_order_templates', ['id' => $template->id, 'journal_id' => null]);
});

it('forbids an OrderTemplate pointing at a nonexistent journal, since the column has no foreign key behind it', function () {
    $companyA = Company::factory()->create();

    test()->actingAs(templateMemberOf($companyA));

    expect(fn () => OrderTemplate::factory()->create(['company_id' => $companyA->id, 'journal_id' => 999999999]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('sales_order_templates', ['journal_id' => 999999999]);
});

// ── OrderTemplate: delete ───────────────────────────────────────────────────

it('forbids deleting an OrderTemplate from a different company than the acting user', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $templateB = templateFor($companyB);

    test()->actingAs(templateMemberOf($companyA));

    $loaded = OrderTemplate::withoutGlobalScope(CompanyScope::class)->findOrFail($templateB->id);

    expect(fn () => $loaded->delete())->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('sales_order_templates', ['id' => $templateB->id]);
});

// ── OrderTemplateProduct: ownership derived from the template ───────────────

it('derives an OrderTemplateProduct\'s company from its template instead of an arbitrary tenant', function () {
    $companyA = Company::factory()->create();

    // A second company exists and was created FIRST for one of the two,
    // so a surviving Company::first() fallback would be observable.
    $user = templateMemberOf($companyA);
    test()->actingAs($user);

    $template = OrderTemplate::factory()->create(['company_id' => $companyA->id]);
    $product = productFor($companyA);

    $line = OrderTemplateProduct::create([
        'order_template_id' => $template->id,
        'product_id'        => $product->id,
        'name'              => 'line',
        'quantity'          => 1,
    ]);

    expect($line->company_id)->toBe($companyA->id);
    expect($line->product_uom_id)->toBe($product->uom_id);
    expect($line->creator_id)->toBe($user->id);
});

it('forbids creating an OrderTemplateProduct under a template of another company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $templateB = templateFor($companyB);
    $productB = productFor($companyB);

    test()->actingAs(templateMemberOf($companyA));

    expect(fn () => OrderTemplateProduct::create([
        'order_template_id' => $templateB->id,
        'product_id'        => $productB->id,
        'name'              => 'line',
        'quantity'          => 1,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('sales_order_template_products', 0);
});

it('forbids creating an OrderTemplateProduct with no template and with a nonexistent one', function () {
    $companyA = Company::factory()->create();

    test()->actingAs(templateMemberOf($companyA));

    $product = productFor($companyA);

    expect(fn () => OrderTemplateProduct::create(['order_template_id' => null, 'product_id' => $product->id, 'name' => 'x', 'quantity' => 1]))
        ->toThrow(AuthorizationException::class);

    expect(fn () => OrderTemplateProduct::create(['order_template_id' => 999999999, 'product_id' => $product->id, 'name' => 'x', 'quantity' => 1]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('sales_order_template_products', 0);
});

it('forbids an explicit company_id that contradicts the template\'s own', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = templateMemberOf($companyA);
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $template = OrderTemplate::factory()->create(['company_id' => $companyA->id]);
    $product = productFor($companyA);

    expect(fn () => OrderTemplateProduct::create([
        'order_template_id' => $template->id,
        'company_id'        => $companyB->id,
        'product_id'        => $product->id,
        'name'              => 'line',
        'quantity'          => 1,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('sales_order_template_products', 0);
});

it('forbids an OrderTemplateProduct that references a product of another company, trashed included', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = templateMemberOf($companyA);
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $template = OrderTemplate::factory()->create(['company_id' => $companyA->id]);
    $productB = productFor($companyB);

    expect(fn () => OrderTemplateProduct::create([
        'order_template_id' => $template->id,
        'product_id'        => $productB->id,
        'name'              => 'line',
        'quantity'          => 1,
    ]))->toThrow(AuthorizationException::class);

    $productB->delete();

    expect(fn () => OrderTemplateProduct::create([
        'order_template_id' => $template->id,
        'product_id'        => $productB->id,
        'name'              => 'line',
        'quantity'          => 1,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('sales_order_template_products', 0);
});

it('requires a product on a real template line but never invents one for a section or a note', function () {
    $companyA = Company::factory()->create();

    test()->actingAs(templateMemberOf($companyA));

    $template = OrderTemplate::factory()->create(['company_id' => $companyA->id]);

    // A pre-existing product makes the removed Product::first() fallback
    // observable: with it in place this would have silently succeeded.
    productFor($companyA);

    expect(fn () => OrderTemplateProduct::create([
        'order_template_id' => $template->id,
        'name'              => 'line with no product',
        'quantity'          => 1,
    ]))->toThrow(AuthorizationException::class);

    $section = OrderTemplateProduct::factory()->section()->create(['order_template_id' => $template->id]);
    $note = OrderTemplateProduct::factory()->note()->create(['order_template_id' => $template->id]);

    expect($section->product_id)->toBeNull();
    expect($section->product_uom_id)->toBeNull();
    expect($note->product_id)->toBeNull();
    expect($note->product_uom_id)->toBeNull();
    expect($section->company_id)->toBe($companyA->id);
});

it('re-authorizes the persisted template before letting a row be retargeted into an authorized company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $templateB = templateFor($companyB);
    $productB = productFor($companyB);

    $lineB = CompanyContext::runForCompany($companyB->id, reason: 'test fixture setup', caller: __FILE__, callback: fn () => OrderTemplateProduct::create([
        'order_template_id' => $templateB->id,
        'product_id'        => $productB->id,
        'name'              => 'line',
        'quantity'          => 1,
    ]));

    test()->actingAs(templateMemberOf($companyA));

    $templateA = OrderTemplate::factory()->create(['company_id' => $companyA->id]);

    // Loaded through an explicit bypass, exactly the situation the
    // re-authorization of the CURRENT parent exists for.
    $loaded = OrderTemplateProduct::withoutGlobalScope(ParentDerivedCompanyScope::class)->findOrFail($lineB->id);

    expect(fn () => $loaded->update(['order_template_id' => $templateA->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('sales_order_template_products', [
        'id'                => $lineB->id,
        'order_template_id' => $templateB->id,
        'company_id'        => $companyB->id,
    ]);
});

it('hides an OrderTemplateProduct whose template belongs to another company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $templateB = templateFor($companyB);
    $productB = productFor($companyB);

    $lineB = CompanyContext::runForCompany($companyB->id, reason: 'test fixture setup', caller: __FILE__, callback: fn () => OrderTemplateProduct::create([
        'order_template_id' => $templateB->id,
        'product_id'        => $productB->id,
        'name'              => 'line',
        'quantity'          => 1,
    ]));

    $companyAUser = templateMemberOf($companyA);
    test()->actingAs($companyAUser);

    expect(OrderTemplateProduct::find($lineB->id))->toBeNull();
});

it('forbids deleting an OrderTemplateProduct whose template belongs to another company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $templateB = templateFor($companyB);
    $productB = productFor($companyB);

    $lineB = CompanyContext::runForCompany($companyB->id, reason: 'test fixture setup', caller: __FILE__, callback: fn () => OrderTemplateProduct::create([
        'order_template_id' => $templateB->id,
        'product_id'        => $productB->id,
        'name'              => 'line',
        'quantity'          => 1,
    ]));

    test()->actingAs(templateMemberOf($companyA));

    $loaded = OrderTemplateProduct::withoutGlobalScope(ParentDerivedCompanyScope::class)->findOrFail($lineB->id);

    expect(fn () => $loaded->delete())->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('sales_order_template_products', ['id' => $lineB->id]);
});

// ── Order -> OrderTemplate relation contract ────────────────────────────────

it('allows an Order to reference an OrderTemplate of its own company', function () {
    $companyA = Company::factory()->create();

    test()->actingAs(templateMemberOf($companyA));

    $template = OrderTemplate::factory()->create(['company_id' => $companyA->id]);
    $order = Order::factory()->create(['company_id' => $companyA->id, 'sale_order_template_id' => $template->id]);

    expect($order->sale_order_template_id)->toBe($template->id);
});

it('forbids creating an Order that references an OrderTemplate of another company or a nonexistent one', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $templateB = templateFor($companyB);

    test()->actingAs(templateMemberOf($companyA));

    expect(fn () => Order::factory()->create(['company_id' => $companyA->id, 'sale_order_template_id' => $templateB->id]))
        ->toThrow(AuthorizationException::class);

    expect(fn () => Order::factory()->create(['company_id' => $companyA->id, 'sale_order_template_id' => 999999999]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('sales_orders', 0);
});

it('leaves the Order and every one of its lines untouched when a template retarget is rejected', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $templateB = templateFor($companyB);

    test()->actingAs(templateMemberOf($companyA));

    $templateA = OrderTemplate::factory()->create(['company_id' => $companyA->id]);
    $order = Order::factory()->create([
        'company_id'             => $companyA->id,
        'sale_order_template_id' => $templateA->id,
        'state'                  => OrderState::DRAFT,
    ]);

    $lines = OrderLine::factory()->count(2)->create([
        'order_id' => $order->id,
        'state'    => OrderState::DRAFT,
    ]);

    expect(fn () => $order->update(['sale_order_template_id' => $templateB->id, 'state' => OrderState::SALE]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('sales_orders', [
        'id'                     => $order->id,
        'sale_order_template_id' => $templateA->id,
        'company_id'             => $companyA->id,
        'state'                  => OrderState::DRAFT->value,
    ]);

    foreach ($lines as $line) {
        $this->assertDatabaseHas('sales_order_lines', ['id' => $line->id, 'state' => OrderState::DRAFT->value]);
    }
});

// ── Fail closed and system context ──────────────────────────────────────────

it('fails closed on OrderTemplate reads and writes when there is no authenticated user and no system context', function () {
    $companyA = Company::factory()->create();

    templateFor($companyA);

    expect(OrderTemplate::count())->toBe(0);

    expect(fn () => OrderTemplate::factory()->create(['company_id' => $companyA->id]))
        ->toThrow(AuthorizationException::class);
});

it('allows an explicit company system context to create a template and its lines', function () {
    $companyA = Company::factory()->create();

    CompanyContext::runForCompany($companyA->id, reason: 'test fixture setup', caller: __FILE__, callback: function () use ($companyA) {
        $template = OrderTemplate::factory()->create(['company_id' => $companyA->id]);
        $product = Product::factory()->create(['company_id' => $companyA->id]);

        $line = OrderTemplateProduct::create([
            'order_template_id' => $template->id,
            'product_id'        => $product->id,
            'name'              => 'line',
            'quantity'          => 1,
        ]);

        expect($line->company_id)->toBe($companyA->id);
        expect(OrderTemplate::count())->toBe(1);
    });
});

it('produces a coherent template, line and product triad from the bare factory default', function () {
    CompanyContext::runForAllCompanies(reason: 'test: bare factory coherence', caller: __FILE__, callback: function () {
        $line = OrderTemplateProduct::factory()->create();

        $template = OrderTemplate::withoutGlobalScope(CompanyScope::class)->findOrFail($line->order_template_id);
        $product = Product::withoutGlobalScope(CompanyScope::class)->findOrFail($line->product_id);

        expect($template->company_id)->not->toBeNull();
        expect($line->company_id)->toBe($template->company_id);
        expect($product->company_id)->toBe($template->company_id);
        expect($line->product_uom_id)->toBe($product->uom_id);
    });
});

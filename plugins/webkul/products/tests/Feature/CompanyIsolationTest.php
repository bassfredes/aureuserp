<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Webkul\Product\Models\Attribute;
use Webkul\Product\Models\AttributeOption;
use Webkul\Product\Models\Packaging;
use Webkul\Product\Models\PriceList;
use Webkul\Product\Models\PriceRule;
use Webkul\Product\Models\PriceRuleItem;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\ProductAttribute;
use Webkul\Product\Models\ProductAttributeValue;
use Webkul\Product\Models\ProductSupplier;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('products');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

// ── Product ──────────────────────────────────────────────────────────────────

it('hides products from companies the user is not allowed to see', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $userA = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $companyA->id,
    ]));

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->create(['company_id' => $companyB->id]);

    test()->actingAs($userA);

    expect(Product::find($productA->id))->not->toBeNull();
    expect(Product::find($productB->id))->toBeNull();
});

it('shows products from every company explicitly allowed to the user', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $companyA->id,
    ]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->create(['company_id' => $companyB->id]);

    test()->actingAs($user);

    expect(Product::query()->pluck('id')->all())
        ->toEqualCanonicalizing([$productA->id, $productB->id]);
});

it('hides all products from an authenticated user without company access', function () {
    $company = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => null,
    ]));

    Product::factory()->create(['company_id' => $company->id]);

    test()->actingAs($user);

    expect(Product::query()->count())->toBe(0);
});

it('lets a super_admin bypass product company isolation via forAllCompanies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $superAdmin = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $companyA->id,
    ]));
    $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->create(['company_id' => $companyB->id]);

    test()->actingAs($superAdmin);

    expect(Product::find($productB->id))->toBeNull();

    $bypassedIds = Product::forAllCompanies()->pluck('id')->all();

    expect($bypassedIds)->toContain($productA->id)
        ->and($bypassedIds)->toContain($productB->id);
});

it('keeps a generated variant in the same company as its parent product', function () {
    $companyA = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $companyA->id,
    ]));
    test()->actingAs($user);

    $parent = Product::factory()->create(['company_id' => $companyA->id, 'is_configurable' => true]);

    $attribute = Attribute::factory()->create();
    $optionOne = AttributeOption::factory()->create(['attribute_id' => $attribute->id]);
    $optionTwo = AttributeOption::factory()->create(['attribute_id' => $attribute->id]);

    $productAttribute = ProductAttribute::factory()->create([
        'product_id'   => $parent->id,
        'attribute_id' => $attribute->id,
    ]);

    ProductAttributeValue::query()->insert([
        [
            'product_id'           => $parent->id,
            'attribute_id'         => $attribute->id,
            'product_attribute_id' => $productAttribute->id,
            'attribute_option_id'  => $optionOne->id,
        ],
        [
            'product_id'           => $parent->id,
            'attribute_id'         => $attribute->id,
            'product_attribute_id' => $productAttribute->id,
            'attribute_option_id'  => $optionTwo->id,
        ],
    ]);

    $parent->generateVariants();

    $variants = $parent->variants()->get();

    expect($variants)->not->toBeEmpty();
    $variants->each(fn ($variant) => expect($variant->company_id)->toBe($companyA->id));
});

// ── Packaging ────────────────────────────────────────────────────────────────

it('hides packagings from companies the user is not allowed to see', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $userA = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $companyA->id,
    ]));

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->create(['company_id' => $companyB->id]);
    $packagingA = Packaging::factory()->create(['product_id' => $productA->id, 'company_id' => $companyA->id]);
    $packagingB = Packaging::factory()->create(['product_id' => $productB->id, 'company_id' => $companyB->id]);

    test()->actingAs($userA);

    expect(Packaging::find($packagingA->id))->not->toBeNull();
    expect(Packaging::find($packagingB->id))->toBeNull();
});

it('hides all packagings from an authenticated user without company access', function () {
    $company = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => null,
    ]));

    $product = Product::factory()->create(['company_id' => $company->id]);
    Packaging::factory()->create(['product_id' => $product->id, 'company_id' => $company->id]);

    test()->actingAs($user);

    expect(Packaging::query()->count())->toBe(0);
});

// ── ProductSupplier ──────────────────────────────────────────────────────────

it('hides product suppliers from companies the user is not allowed to see', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $userA = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $companyA->id,
    ]));

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->create(['company_id' => $companyB->id]);
    $supplierA = ProductSupplier::factory()->create(['product_id' => $productA->id, 'company_id' => $companyA->id]);
    $supplierB = ProductSupplier::factory()->create(['product_id' => $productB->id, 'company_id' => $companyB->id]);

    test()->actingAs($userA);

    expect(ProductSupplier::find($supplierA->id))->not->toBeNull();
    expect(ProductSupplier::find($supplierB->id))->toBeNull();
});

it('hides all product suppliers from an authenticated user without company access', function () {
    $company = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => null,
    ]));

    $product = Product::factory()->create(['company_id' => $company->id]);
    ProductSupplier::factory()->create(['product_id' => $product->id, 'company_id' => $company->id]);

    test()->actingAs($user);

    expect(ProductSupplier::query()->count())->toBe(0);
});

// ── PriceRule / PriceRuleItem / PriceList ───────────────────────────────────

it('hides price rules from companies the user is not allowed to see', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $userA = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $companyA->id,
    ]));

    $ruleA = PriceRule::factory()->create(['company_id' => $companyA->id]);
    $ruleB = PriceRule::factory()->create(['company_id' => $companyB->id]);

    test()->actingAs($userA);

    expect(PriceRule::find($ruleA->id))->not->toBeNull();
    expect(PriceRule::find($ruleB->id))->toBeNull();
});

it('hides all price rules from an authenticated user without company access', function () {
    $company = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => null,
    ]));

    PriceRule::factory()->create(['company_id' => $company->id]);

    test()->actingAs($user);

    expect(PriceRule::query()->count())->toBe(0);
});

it('hides price rule items from companies the user is not allowed to see', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $userA = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $companyA->id,
    ]));

    $ruleA = PriceRule::factory()->create(['company_id' => $companyA->id]);
    $ruleB = PriceRule::factory()->create(['company_id' => $companyB->id]);
    $itemA = PriceRuleItem::factory()->create(['price_rule_id' => $ruleA->id, 'company_id' => $companyA->id]);
    $itemB = PriceRuleItem::factory()->create(['price_rule_id' => $ruleB->id, 'company_id' => $companyB->id]);

    test()->actingAs($userA);

    expect(PriceRuleItem::find($itemA->id))->not->toBeNull();
    expect(PriceRuleItem::find($itemB->id))->toBeNull();
});

it('derives a price rule item company from its parent price rule when omitted', function () {
    $company = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $company->id,
    ]));
    test()->actingAs($user);

    $rule = PriceRule::factory()->create(['company_id' => $company->id]);

    $item = PriceRuleItem::factory()->create([
        'price_rule_id' => $rule->id,
        'company_id'    => null,
    ]);

    expect($item->fresh()->company_id)->toBe($company->id);
});

it('hides price lists from companies the user is not allowed to see', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $userA = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $companyA->id,
    ]));

    $listA = PriceList::factory()->create(['company_id' => $companyA->id]);
    $listB = PriceList::factory()->create(['company_id' => $companyB->id]);

    test()->actingAs($userA);

    expect(PriceList::find($listA->id))->not->toBeNull();
    expect(PriceList::find($listB->id))->toBeNull();
});

it('fails closed on products when there is no authenticated user and no system context', function () {
    $company = Company::factory()->create();
    Product::factory()->create(['company_id' => $company->id]);

    Auth::logout();

    expect(Product::query()->count())->toBe(0);
});

it('lets an explicit company system context see exactly that company\'s products with no authenticated user', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    Product::factory()->create(['company_id' => $companyB->id]);

    Auth::logout();

    $visibleIds = CompanyContext::runForCompany(
        $companyA->id,
        reason: 'test: company system context visibility',
        caller: __FILE__,
        callback: fn () => Product::query()->pluck('id'),
    );

    expect($visibleIds)->toContain($productA->id)
        ->and($visibleIds)->toHaveCount(1);
});

it('lets an explicit all_companies system context see every product with no authenticated user', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    Product::factory()->create(['company_id' => $companyA->id]);
    Product::factory()->create(['company_id' => $companyB->id]);

    Auth::logout();

    $count = CompanyContext::runForAllCompanies(
        reason: 'test: all_companies system context visibility',
        caller: __FILE__,
        callback: fn () => Product::query()->count(),
    );

    expect($count)->toBeGreaterThanOrEqual(2);
});

// ── Relation invariants, enforced at the model level (review on PR #11) ────
// These prove the invariant holds for ANY write path, not only the API
// controllers: Filament's Create/Edit pages call the exact same
// Model::create()/update() calls exercised directly below — there is no
// separate Filament-side validation layer for this invariant to bypass.

it('forbids creating a Packaging for company A referencing a Product from company B, even for a user allowed in both', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $productB = Product::factory()->create(['company_id' => $companyB->id]);

    expect(fn () => Packaging::factory()->create([
        'product_id' => $productB->id,
        'company_id' => $companyA->id,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('products_packagings', ['product_id' => $productB->id]);
});

it('forbids changing a Packaging to reference a Product from a different company on update', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->create(['company_id' => $companyB->id]);
    $packaging = Packaging::factory()->create([
        'product_id' => $productA->id,
        'company_id' => $companyA->id,
    ]);

    expect(fn () => $packaging->update(['product_id' => $productB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('products_packagings', [
        'id'         => $packaging->id,
        'product_id' => $productA->id,
    ]);
});

it('forbids creating a ProductSupplier for company A referencing a Product from company B, even for a user allowed in both', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $productB = Product::factory()->create(['company_id' => $companyB->id]);

    expect(fn () => ProductSupplier::factory()->create([
        'product_id' => $productB->id,
        'company_id' => $companyA->id,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('products_product_suppliers', ['product_id' => $productB->id]);
});

it('forbids changing a ProductSupplier to reference a Product from a different company on update', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $productA = Product::factory()->create(['company_id' => $companyA->id]);
    $productB = Product::factory()->create(['company_id' => $companyB->id]);
    $supplier = ProductSupplier::factory()->create([
        'product_id' => $productA->id,
        'company_id' => $companyA->id,
    ]);

    expect(fn () => $supplier->update(['product_id' => $productB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('products_product_suppliers', [
        'id'         => $supplier->id,
        'product_id' => $productA->id,
    ]);
});

it('forbids creating a PriceRuleItem with an explicit company_id that mismatches its parent PriceRule', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $rule = PriceRule::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => PriceRuleItem::factory()->create([
        'price_rule_id' => $rule->id,
        'company_id'    => $companyB->id,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('products_price_rule_items', ['price_rule_id' => $rule->id, 'company_id' => $companyB->id]);
});

it('forbids changing a PriceRuleItem to reference a PriceRule from a different company on update', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $ruleA = PriceRule::factory()->create(['company_id' => $companyA->id]);
    $ruleB = PriceRule::factory()->create(['company_id' => $companyB->id]);
    $item = PriceRuleItem::factory()->create(['price_rule_id' => $ruleA->id]);

    expect(fn () => $item->update(['price_rule_id' => $ruleB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('products_price_rule_items', [
        'id'            => $item->id,
        'price_rule_id' => $ruleA->id,
    ]);
});

// ── Isolated company_id-only update (review on PR #11, round 2) ────────────
// The updating() guards previously only fired on isDirty('product_id') /
// isDirty('price_rule_id') — an update that changes company_id ALONE, with
// the parent FK left untouched, skipped the check entirely and could
// persist a mismatched A/B row. These prove the fix watches both sides.

it('forbids changing only a Packaging\'s company_id to a company that mismatches its unchanged product', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $product = Product::factory()->create(['company_id' => $companyA->id]);
    $packaging = Packaging::factory()->create([
        'product_id' => $product->id,
        'company_id' => $companyA->id,
    ]);

    expect(fn () => $packaging->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('products_packagings', [
        'id'         => $packaging->id,
        'company_id' => $companyA->id,
    ]);
});

it('forbids changing only a ProductSupplier\'s company_id to a company that mismatches its unchanged product', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $product = Product::factory()->create(['company_id' => $companyA->id]);
    $supplier = ProductSupplier::factory()->create([
        'product_id' => $product->id,
        'company_id' => $companyA->id,
    ]);

    expect(fn () => $supplier->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('products_product_suppliers', [
        'id'         => $supplier->id,
        'company_id' => $companyA->id,
    ]);
});

it('forbids changing only a PriceRuleItem\'s company_id to a company that mismatches its unchanged PriceRule', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $rule = PriceRule::factory()->create(['company_id' => $companyA->id]);
    $item = PriceRuleItem::factory()->create(['price_rule_id' => $rule->id, 'company_id' => $companyA->id]);

    expect(fn () => $item->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('products_price_rule_items', [
        'id'         => $item->id,
        'company_id' => $companyA->id,
    ]);
});

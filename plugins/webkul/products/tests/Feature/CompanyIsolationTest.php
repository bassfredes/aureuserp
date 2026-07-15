<?php

use Illuminate\Support\Facades\Auth;
use Webkul\Product\Models\PriceList;
use Webkul\Product\Models\PriceRule;
use Webkul\Product\Models\PriceRuleItem;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\ProductSupplier;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

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

    $attribute = \Webkul\Product\Models\Attribute::factory()->create();
    $optionOne = \Webkul\Product\Models\AttributeOption::factory()->create(['attribute_id' => $attribute->id]);
    $optionTwo = \Webkul\Product\Models\AttributeOption::factory()->create(['attribute_id' => $attribute->id]);

    $productAttribute = \Webkul\Product\Models\ProductAttribute::factory()->create([
        'product_id'   => $parent->id,
        'attribute_id' => $attribute->id,
    ]);

    \Webkul\Product\Models\ProductAttributeValue::query()->insert([
        [
            'product_id'          => $parent->id,
            'attribute_id'        => $attribute->id,
            'product_attribute_id' => $productAttribute->id,
            'attribute_option_id' => $optionOne->id,
        ],
        [
            'product_id'          => $parent->id,
            'attribute_id'        => $attribute->id,
            'product_attribute_id' => $productAttribute->id,
            'attribute_option_id' => $optionTwo->id,
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

    $packagingA = \Webkul\Product\Models\Packaging::factory()->create(['company_id' => $companyA->id]);
    $packagingB = \Webkul\Product\Models\Packaging::factory()->create(['company_id' => $companyB->id]);

    test()->actingAs($userA);

    expect(\Webkul\Product\Models\Packaging::find($packagingA->id))->not->toBeNull();
    expect(\Webkul\Product\Models\Packaging::find($packagingB->id))->toBeNull();
});

it('hides all packagings from an authenticated user without company access', function () {
    $company = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => null,
    ]));

    \Webkul\Product\Models\Packaging::factory()->create(['company_id' => $company->id]);

    test()->actingAs($user);

    expect(\Webkul\Product\Models\Packaging::query()->count())->toBe(0);
});

// ── ProductSupplier ──────────────────────────────────────────────────────────

it('hides product suppliers from companies the user is not allowed to see', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $userA = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $companyA->id,
    ]));

    $supplierA = ProductSupplier::factory()->create(['company_id' => $companyA->id]);
    $supplierB = ProductSupplier::factory()->create(['company_id' => $companyB->id]);

    test()->actingAs($userA);

    expect(ProductSupplier::find($supplierA->id))->not->toBeNull();
    expect(ProductSupplier::find($supplierB->id))->toBeNull();
});

it('hides all product suppliers from an authenticated user without company access', function () {
    $company = Company::factory()->create();

    $user = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => null,
    ]));

    ProductSupplier::factory()->create(['company_id' => $company->id]);

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

    $itemA = PriceRuleItem::factory()->create(['company_id' => $companyA->id]);
    $itemB = PriceRuleItem::factory()->create(['company_id' => $companyB->id]);

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

it('does not filter products when there is no authenticated user', function () {
    $company = Company::factory()->create();
    Product::factory()->create(['company_id' => $company->id]);

    Auth::logout();

    expect(Product::query()->count())->toBeGreaterThanOrEqual(1);
});

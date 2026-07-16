<?php

namespace Webkul\Product\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Product\Enums\PriceRuleApplyTo;
use Webkul\Product\Enums\PriceRuleBase;
use Webkul\Product\Enums\PriceRuleType;
use Webkul\Product\Models\Category;
use Webkul\Product\Models\PriceRule;
use Webkul\Product\Models\PriceRuleItem;
use Webkul\Product\Models\Product;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Scopes\CompanyScope;

/**
 * @extends Factory<PriceRuleItem>
 */
class PriceRuleItemFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = PriceRuleItem::class;

    /**
     * Define the model's default state.
     *
     * `price_rule_id` is declared before `company_id`/`product_id` on
     * purpose — same rationale as PackagingFactory (D4, aureuserp#137
     * review): Factory::expandAttributes() resolves attributes in array
     * order and passes each already-resolved value forward, so the
     * closures below see price_rule_id's final value (override or
     * default) and derive a consistent company/product. An explicit
     * company_id override still replaces its closure outright, so a
     * caller building a deliberately mismatched fixture for a rejection
     * test is unaffected — PriceRuleItem::creating() anchors company_id to
     * price_rule_id regardless (D4), this factory only avoids generating
     * a random mismatch by default. product_id/category_id are NOT NULL
     * regardless of apply_to (schema quirk, not specific to this rollout),
     * so product_id is always created, aligned to the same company.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'apply_to'         => PriceRuleApplyTo::PRODUCT,
            'display_apply_to' => PriceRuleApplyTo::PRODUCT->value,
            'base'             => PriceRuleBase::LIST_PRICE,
            'type'             => PriceRuleType::PERCENTAGE,
            'price_rule_id'    => PriceRule::factory(),
            'company_id'       => fn (array $attributes) => PriceRule::withoutGlobalScope(CompanyScope::class)
                ->find($attributes['price_rule_id'])?->company_id,
            'product_id'       => fn (array $attributes) => Product::factory()->create([
                'company_id' => $attributes['company_id'],
            ])->id,
            'category_id'      => Category::factory(),
            'creator_id'       => User::query()->value('id') ?? User::factory(),
        ];
    }
}

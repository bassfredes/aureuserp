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
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'apply_to'         => PriceRuleApplyTo::PRODUCT,
            'display_apply_to' => PriceRuleApplyTo::PRODUCT->value,
            'base'             => PriceRuleBase::LIST_PRICE,
            'type'             => PriceRuleType::PERCENTAGE,
            // company_id is deliberately omitted here: PriceRuleItem::creating()
            // derives it from price_rule_id (D4, aureuserp#137), so the
            // factory doesn't need to duplicate that invariant.
            'price_rule_id'    => PriceRule::factory(),
            'product_id'       => Product::factory(),
            'category_id'      => Category::factory(),
            'creator_id'       => User::query()->value('id') ?? User::factory(),
        ];
    }
}

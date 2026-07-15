<?php

namespace Webkul\Product\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Partner\Models\Partner;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\ProductSupplier;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;
use Webkul\Support\Models\Scopes\CompanyScope;

/**
 * @extends Factory<ProductSupplier>
 */
class ProductSupplierFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = ProductSupplier::class;

    /**
     * Define the model's default state.
     *
     * `product_id` is declared before `company_id` on purpose — same
     * rationale as PackagingFactory (D3, aureuserp#137 review):
     * Factory::expandAttributes() resolves attributes in array order and
     * passes each already-resolved value forward, so company_id's closure
     * below sees product_id's final value (override or default) and
     * derives a consistent company. An explicit company_id override still
     * replaces the closure outright, so a caller building a deliberately
     * mismatched fixture for a rejection test is unaffected.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sort'         => fake()->randomNumber(),
            'delay'        => fake()->numberBetween(1, 30),
            'product_name' => fake()->words(3, true),
            'product_code' => fake()->bothify('SUP-####'),
            'starts_at'    => fake()->dateTimeBetween('-1 month', 'now'),
            'ends_at'      => fake()->dateTimeBetween('now', '+1 year'),
            'min_qty'      => fake()->numberBetween(1, 100),
            'price'        => fake()->randomFloat(2, 10, 1000),
            'discount'     => fake()->randomFloat(2, 0, 50),
            'product_id'   => Product::factory(),
            'partner_id'   => Partner::query()->value('id') ?? Partner::factory(),
            'currency_id'  => Currency::factory(),
            'creator_id'   => User::query()->value('id') ?? User::factory(),
            'company_id'   => fn (array $attributes) => Product::withoutGlobalScope(CompanyScope::class)
                ->find($attributes['product_id'])?->company_id
                ?? Company::factory()->create()->id,
        ];
    }
}

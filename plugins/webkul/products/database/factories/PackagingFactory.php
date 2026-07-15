<?php

namespace Webkul\Product\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Product\Models\Packaging;
use Webkul\Product\Models\Product;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;

/**
 * @extends Factory<Packaging>
 */
class PackagingFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Packaging::class;

    /**
     * Define the model's default state.
     *
     * `product_id` is declared before `company_id` on purpose: Laravel
     * resolves definition() attributes in array order and passes each
     * already-resolved value forward to later closures (see
     * Factory::expandAttributes()), so company_id's closure below sees
     * product_id's final value — the caller's override if one was
     * passed to create(), or this default Product::factory() otherwise
     * — and derives a company_id that's always consistent with it
     * (D2, aureuserp#137 review). An explicit company_id override still
     * replaces the closure outright (array_merge in getRawAttributes()
     * happens before expandAttributes() runs), so a caller that wants a
     * specific — including a deliberately mismatched, for a rejection
     * test — company_id is free to pass one; only the "neither specified"
     * default path is what this closure protects from producing a random
     * mismatch.
     */
    public function definition(): array
    {
        return [
            'name'       => fake()->name(),
            'qty'        => 1,
            'sort'       => 1,
            'creator_id' => User::query()->value('id') ?? User::factory(),
            'product_id' => Product::factory(),
            'company_id' => fn (array $attributes) => Product::withoutGlobalScope(CompanyScope::class)
                ->find($attributes['product_id'])?->company_id
                ?? Company::factory()->create()->id,
        ];
    }
}

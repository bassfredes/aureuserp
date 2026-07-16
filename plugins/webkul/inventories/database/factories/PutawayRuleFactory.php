<?php

namespace Webkul\Inventory\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Inventory\Models\Location;
use Webkul\Inventory\Models\PutawayRule;
use Webkul\Inventory\Models\StorageCategory;
use Webkul\Product\Models\Category;
use Webkul\Product\Models\Product;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * @extends Factory<PutawayRule>
 */
class PutawayRuleFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = PutawayRule::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sub_location'        => null,
            'sort'                => 0,
            'product_id'          => null,
            'category_id'         => null,
            'storage_category_id' => null,
            'in_location_id'      => Location::factory(),
            'out_location_id'     => Location::factory(),
            'company_id'          => Company::factory(),
            'creator_id'          => User::query()->value('id') ?? User::factory(),
        ];
    }

    /**
     * Does NOT align the created product's company to this rule's own:
     * withProduct() is a state, resolved before company_id's own
     * definition()-level value (Factory::getRawAttributes() runs before
     * expandAttributes()), and an afterMaking()-based correction here
     * would silently overwrite a caller's deliberately mismatched
     * product_id/company_id pair built for a rejection test (D5b,
     * aureuserp#137 review lesson, carried over from Packaging/
     * ProductSupplier). A caller that needs both to agree must pass a
     * matching company_id explicitly alongside withProduct().
     */
    public function withProduct(): static
    {
        return $this->state(fn (array $attributes) => [
            'product_id' => Product::factory(),
        ]);
    }

    public function withCategory(): static
    {
        return $this->state(fn (array $attributes) => [
            'category_id' => Category::factory(),
        ]);
    }

    public function withStorageCategory(): static
    {
        return $this->state(fn (array $attributes) => [
            'storage_category_id' => StorageCategory::factory(),
        ]);
    }
}

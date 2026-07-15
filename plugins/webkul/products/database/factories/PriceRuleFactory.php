<?php

namespace Webkul\Product\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Product\Models\PriceRule;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

/**
 * @extends Factory<PriceRule>
 */
class PriceRuleFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = PriceRule::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name'        => fake()->name(),
            'currency_id' => Currency::query()->value('id') ?? Currency::factory(),
            'company_id'  => Company::factory(),
            'creator_id'  => User::query()->value('id') ?? User::factory(),
        ];
    }
}

<?php

namespace Webkul\Purchase\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Product\Models\Product;
use Webkul\Purchase\Models\Requisition;
use Webkul\Purchase\Models\RequisitionLine;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Scopes\CompanyScope;

/**
 * @extends Factory<RequisitionLine>
 */
class RequisitionLineFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = RequisitionLine::class;

    /**
     * Define the model's default state.
     *
     * requisition_id is required (NOT NULL), so every ->create() call
     * already passes it explicitly or gets this default. company_id/
     * product_id are declared in this order on purpose (D5b,
     * aureuserp#137 — extends the existing D4 backfill-avoidance
     * rationale): Factory::expandAttributes() resolves attributes in
     * array order and passes each already-resolved value forward, so
     * company_id derives from the (possibly overridden) requisition's
     * own company, and product_id is created in that same company
     * instead of an independent random one. An explicit override for
     * either still wins outright, so a caller building a deliberately
     * mismatched fixture for a rejection test is unaffected.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => fn (array $attributes) => Requisition::withoutGlobalScope(CompanyScope::class)
                ->find($attributes['requisition_id'] ?? null)?->company_id,
            'product_id' => fn (array $attributes) => Product::factory()->create([
                'company_id' => $attributes['company_id'],
            ])->id,
            'qty'        => fake()->numberBetween(1, 100),
            'price_unit' => fake()->randomFloat(2, 10, 1000),
            'creator_id' => User::query()->value('id') ?? User::factory(),
        ];
    }
}

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
     * requisition_id is required (NOT NULL, no default here), so every
     * ->create() call already passes it explicitly. Default company_id from
     * that agreement when the caller didn't also override company_id —
     * otherwise every factory-built line reintroduces the NULL/mismatched
     * rows the backfill migration exists to fix (aureuserp#137, D4).
     */
    public function configure(): static
    {
        return $this->afterMaking(function (RequisitionLine $line) {
            if ($line->company_id === null && $line->requisition_id) {
                $line->company_id = Requisition::withoutGlobalScope(CompanyScope::class)
                    ->find($line->requisition_id)
                    ?->company_id;
            }
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'qty'        => fake()->numberBetween(1, 100),
            'price_unit' => fake()->randomFloat(2, 10, 1000),
            'creator_id' => User::query()->value('id') ?? User::factory(),
        ];
    }
}

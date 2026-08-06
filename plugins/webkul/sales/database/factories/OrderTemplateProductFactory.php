<?php

namespace Webkul\Sale\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Product\Models\Product;
use Webkul\Sale\Enums\OrderDisplayType;
use Webkul\Sale\Models\OrderTemplate;
use Webkul\Sale\Models\OrderTemplateProduct;
use Webkul\Support\Models\Scopes\CompanyScope;

/**
 * @extends Factory<OrderTemplateProduct>
 */
class OrderTemplateProductFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = OrderTemplateProduct::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name'              => fake()->sentence(3),
            'quantity'          => fake()->randomFloat(2, 1, 10),
            'display_type'      => null,
            // order_template_id is declared first and everything else
            // derives from it: Factory::expandAttributes() resolves in
            // array order and passes already-resolved values forward.
            //
            // Before (#138 PR4 A4H) the template, the company and the
            // product each resolved on their own, so the bare factory
            // produced a row whose declared company, whose template's
            // company and whose product's company were three DIFFERENT
            // companies. company_id is no longer declared at all: the
            // model derives it from the template, which is the only
            // authoritative source, and declaring it here would just be a
            // second opinion the model has to reject or override.
            'order_template_id' => OrderTemplate::factory(),
            'product_id'        => fn (array $attributes) => Product::factory()->create([
                'company_id' => OrderTemplate::withoutGlobalScope(CompanyScope::class)
                    ->find($attributes['order_template_id'])?->company_id,
            ])->id,
            // Left to the model, which derives it from the product this
            // line actually describes rather than from UOM::first().
            'product_uom_id'    => null,
            // No default creator_id: the model defaults it to Auth::id().
            // The previous `User::query()->value('id')` picked an
            // arbitrary pre-existing user, often a member of a different
            // company than the one this factory call targets — the same
            // correction already applied in A4E, A4F and A4G.
            'creator_id'        => null,
        ];
    }

    /**
     * A layout row: no product and no unit of measure, and the model must
     * not invent either.
     */
    public function section(): static
    {
        return $this->state(fn () => [
            'display_type'   => OrderDisplayType::SECTION->value,
            'product_id'     => null,
            'product_uom_id' => null,
        ]);
    }

    public function note(): static
    {
        return $this->state(fn () => [
            'display_type'   => OrderDisplayType::NOTE->value,
            'product_id'     => null,
            'product_uom_id' => null,
        ]);
    }
}

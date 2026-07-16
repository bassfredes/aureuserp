<?php

namespace Webkul\Purchase\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\PluginManager\Package;
use Webkul\Product\Models\Product;
use Webkul\Purchase\Enums\OrderState;
use Webkul\Purchase\Enums\QtyReceivedMethod;
use Webkul\Purchase\Models\Order;
use Webkul\Purchase\Models\OrderLine;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Scopes\CompanyScope;

/**
 * @extends Factory<OrderLine>
 */
class OrderLineFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = OrderLine::class;

    /**
     * Define the model's default state.
     *
     * order_id/company_id/product_id are declared in this order on purpose
     * (D5b, aureuserp#137 — extends the existing D4 backfill-avoidance
     * rationale): Factory::expandAttributes() resolves attributes in array
     * order and passes each already-resolved value forward to later
     * closures, so company_id derives from the (possibly overridden)
     * order's own company, and product_id is created in that same company
     * instead of an independent random one. An explicit override for
     * either still wins outright, so a caller building a deliberately
     * mismatched fixture for a rejection test is unaffected.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $productQty = fake()->randomFloat(2, 1, 100);
        $priceUnit = fake()->randomFloat(2, 10, 1000);
        $priceSubtotal = round($productQty * $priceUnit, 2);

        return [
            'name'                => fake()->words(3, true),
            'state'               => OrderState::DRAFT->value,
            'qty_received_method' => Package::isPluginInstalled('inventories')
                ? QtyReceivedMethod::STOCK_MOVE
                : QtyReceivedMethod::MANUAL,
            // order_id is required (NOT NULL), so every ->create() call
            // already passes it explicitly or gets this default.
            'company_id'          => fn (array $attributes) => Order::withoutGlobalScope(CompanyScope::class)
                ->find($attributes['order_id'] ?? null)?->company_id,
            'product_id'          => fn (array $attributes) => Product::factory()->create([
                'company_id' => $attributes['company_id'],
            ])->id,
            'planned_at'          => now()->addDays(7),
            'product_qty'         => $productQty,
            'product_uom_qty'     => $productQty,
            'price_unit'          => $priceUnit,
            'price_subtotal'      => $priceSubtotal,
            'price_tax'           => 0,
            'price_total'         => $priceSubtotal,
            'price_total_cc'      => $priceSubtotal,
            'discount'            => 0,
            'qty_invoiced'        => 0,
            'qty_received'        => 0,
            'qty_received_manual' => 0,
            'qty_to_invoice'      => 0,
            'creator_id'          => User::query()->value('id') ?? User::factory(),
        ];
    }
}

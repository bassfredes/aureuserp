<?php

namespace Webkul\Sale\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Schema;
use Webkul\Inventory\Models\Route;
use Webkul\Inventory\Models\Warehouse;
use Webkul\Partner\Models\Partner;
use Webkul\PluginManager\Package;
use Webkul\Product\Models\Packaging;
use Webkul\Product\Models\Product;
use Webkul\Sale\Enums\OrderState;
use Webkul\Sale\Enums\QtyDeliveredMethod;
use Webkul\Sale\Models\Order;
use Webkul\Sale\Models\OrderLine;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Models\UOM;

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
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->randomFloat(2, 1, 100);
        $priceUnit = fake()->randomFloat(4, 10, 1000);
        $discount = 0;

        $priceSubtotal = $quantity * $priceUnit * (1 - $discount / 100);
        $priceTax = $priceSubtotal * 0.15;
        $priceTotal = $priceSubtotal + $priceTax;
        $technicalPriceUnit = $priceUnit;
        $purchasePrice = $priceUnit * 0.6;
        $margin = $priceSubtotal - ($purchasePrice * $quantity);
        $marginPercent = $priceSubtotal > 0 ? ($margin / $priceSubtotal) * 100 : 0;

        return [
            'sort'                         => fake()->numberBetween(1, 100),
            'state'                        => OrderState::DRAFT,
            'display_type'                 => null,
            'virtual_id'                   => null,
            'linked_virtual_id'            => null,
            'qty_delivered_method'         => Package::isPluginInstalled('inventories') ? QtyDeliveredMethod::STOCK_MOVE : QtyDeliveredMethod::MANUAL,
            'invoice_status'               => null,
            'analytic_distribution'        => null,
            'name'                         => fake()->sentence(3),
            'product_uom_qty'              => $quantity,
            'price_unit'                   => $priceUnit,
            'discount'                     => $discount,
            'price_subtotal'               => $priceSubtotal,
            'price_total'                  => $priceTotal,
            'price_reduce_taxexcl'         => $priceSubtotal / $quantity,
            'price_reduce_taxinc'          => $priceTotal / $quantity,
            'qty_delivered'                => 0,
            'qty_invoiced'                 => 0,
            'qty_to_invoice'               => $quantity,
            'untaxed_amount_invoiced'      => 0,
            'untaxed_amount_to_invoice'    => $priceSubtotal,
            'is_downpayment'               => false,
            'is_expense'                   => false,
            'technical_price_unit'         => $technicalPriceUnit,
            'price_tax'                    => $priceTax,
            'product_qty'                  => $quantity,
            'product_packaging_qty'        => null,
            'customer_lead'                => fake()->numberBetween(1, 30),
            'purchase_price'               => $purchasePrice,
            'margin'                       => $margin,
            'margin_percent'               => $marginPercent,
            'create_date'                  => fake()->dateTimeBetween('-30 days', 'now'),
            'write_date'                   => fake()->dateTimeBetween('-30 days', 'now'),
            // order_id/company_id/product_id are declared in this order on
            // purpose (D5b, aureuserp#137): Factory::expandAttributes()
            // resolves attributes in array order and passes each
            // already-resolved value forward to later closures, so
            // company_id derives from the (possibly overridden) order's
            // own company, and product_id is created in that same company
            // — avoiding the independent-Company::factory() mismatch that
            // caused real test-fixture bugs in the products rollout
            // (aureuserp#11). An explicit override for either still wins
            // outright (array_merge replaces the closure before
            // expandAttributes runs), so a caller building a deliberately
            // mismatched fixture for a rejection test is unaffected.
            'order_id'                     => Order::factory(),
            'company_id'                   => fn (array $attributes) => Order::withoutGlobalScope(CompanyScope::class)
                ->find($attributes['order_id'])?->company_id
                ?? Company::factory()->create()->id,
            'currency_id'                  => Currency::factory(),
            'order_partner_id'             => Partner::query()->value('id') ?? Partner::factory(),
            'salesman_id'                  => User::query()->value('id') ?? User::factory(),
            'product_id'                   => fn (array $attributes) => Product::factory()->create([
                'company_id' => $attributes['company_id'],
            ])->id,
            'product_uom_id'               => UOM::factory(),
            'linked_sale_order_sale_id'    => null,
            'creator_id'                   => User::query()->value('id') ?? User::factory(),
            'product_packaging_id'         => null,
            ...(Schema::hasColumn('sales_order_lines', 'warehouse_id') ? ['warehouse_id' => null] : []),
            ...(Schema::hasColumn('sales_order_lines', 'route_id') ? ['route_id' => null] : []),
        ];
    }

    /**
     * Indicate that the line is in draft state.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => OrderState::DRAFT,
        ]);
    }

    /**
     * Indicate that the line is for a sale.
     */
    public function sale(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => OrderState::SALE,
        ]);
    }

    /**
     * Indicate that the line is a downpayment.
     */
    public function downpayment(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_downpayment' => true,
        ]);
    }

    /**
     * Indicate that the line is an expense.
     */
    public function expense(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_expense' => true,
        ]);
    }

    /**
     * Indicate that the line has a discount.
     */
    public function withDiscount(?float $discount = null): static
    {
        $discount = $discount ?? fake()->randomFloat(2, 5, 20);

        return $this->state(function (array $attributes) use ($discount) {
            $quantity = $attributes['product_uom_qty'];
            $priceUnit = $attributes['price_unit'];
            $priceSubtotal = $quantity * $priceUnit * (1 - $discount / 100);
            $priceTax = $priceSubtotal * 0.15;
            $priceTotal = $priceSubtotal + $priceTax;
            $purchasePrice = $attributes['purchase_price'];
            $margin = $priceSubtotal - ($purchasePrice * $quantity);
            $marginPercent = $priceSubtotal > 0 ? ($margin / $priceSubtotal) * 100 : 0;

            return [
                'discount'                  => $discount,
                'price_subtotal'            => $priceSubtotal,
                'price_total'               => $priceTotal,
                'price_reduce_taxexcl'      => $priceSubtotal / $quantity,
                'price_reduce_taxinc'       => $priceTotal / $quantity,
                'price_tax'                 => $priceTax,
                'untaxed_amount_to_invoice' => $priceSubtotal,
                'margin'                    => $margin,
                'margin_percent'            => $marginPercent,
            ];
        });
    }

    /**
     * Indicate that the line is partially delivered.
     */
    public function partiallyDelivered(): static
    {
        return $this->state(function (array $attributes) {
            $quantity = $attributes['product_uom_qty'];
            $deliveredQty = $quantity * 0.5;

            return [
                'qty_delivered' => $deliveredQty,
            ];
        });
    }

    /**
     * Indicate that the line is fully delivered.
     */
    public function fullyDelivered(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'qty_delivered' => $attributes['product_uom_qty'],
            ];
        });
    }

    /**
     * Indicate that the line has packaging.
     *
     * product_packaging_id is deliberately NOT set here as a state value:
     * Factory states resolve before order_id/company_id/product_id's own
     * definition()-level closures do (Factory::getRawAttributes() runs
     * before expandAttributes()), so a Packaging::factory() call here
     * couldn't see this line's actual resolved company. configure()'s
     * afterMaking() below runs after the full model is built instead,
     * when product_id/company_id are both real, final values.
     */
    public function withPackaging(): static
    {
        return $this->state(fn (array $attributes) => [
            'product_packaging_qty' => fake()->randomFloat(2, 1, 10),
        ]);
    }

    /**
     * withPackaging()'s companion: creates the actual Packaging once
     * product_id/company_id are fully resolved, aligned to this line's own
     * company (D5b, aureuserp#137) — Packaging.company_id must already
     * equal its Product's company (aureuserp#11), so passing this line's
     * resolved product_id/company_id straight through is sufficient.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (OrderLine $orderLine) {
            if ($orderLine->product_packaging_qty !== null && ! $orderLine->product_packaging_id) {
                $orderLine->product_packaging_id = Packaging::factory()->create([
                    'product_id' => $orderLine->product_id,
                    'company_id' => $orderLine->company_id,
                ])->id;
            }
        });
    }

    /**
     * Indicate that the line has a warehouse.
     */
    public function withWarehouse(): static
    {
        return $this->state(fn (array $attributes) => [
            'warehouse_id' => Warehouse::factory(),
        ]);
    }

    /**
     * Indicate that the line has a route.
     */
    public function withRoute(): static
    {
        return $this->state(fn (array $attributes) => [
            'route_id' => Route::factory(),
        ]);
    }
}

<?php

namespace Webkul\Sale\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Sale\Models\AdvancedPaymentInvoice;
use Webkul\Sale\Models\AdvancedPaymentInvoiceOrderSale;
use Webkul\Sale\Models\Order;
use Webkul\Support\Models\Scopes\CompanyScope;

/**
 * @extends Factory<AdvancedPaymentInvoiceOrderSale>
 */
class AdvancedPaymentInvoiceOrderSaleFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = AdvancedPaymentInvoiceOrderSale::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // order_id/advance_payment_invoice_id declared in this order on
            // purpose (#138 A4F, mirrors OrderLineFactory's own
            // order_id/company_id/product_id precedent, D5b aureuserp#137):
            // Factory::expandAttributes() resolves attributes in array
            // order, so advance_payment_invoice_id's company derives from
            // the (possibly overridden) order's own company, avoiding the
            // independent-Company::factory() mismatch two unrelated nested
            // factories would otherwise produce. An explicit override for
            // either key still wins outright.
            'order_id'                   => Order::factory(),
            'advance_payment_invoice_id' => fn (array $attributes) => AdvancedPaymentInvoice::factory()->create([
                'company_id' => Order::withoutGlobalScope(CompanyScope::class)->find($attributes['order_id'])?->company_id,
            ])->id,
        ];
    }
}

<?php

namespace Webkul\Sale\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Sale\Enums\AdvancedPayment;
use Webkul\Sale\Models\AdvancedPaymentInvoice;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

/**
 * @extends Factory<AdvancedPaymentInvoice>
 */
class AdvancedPaymentInvoiceFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = AdvancedPaymentInvoice::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 100, 5000);

        return [
            // 'sales_advance_payment_invoices.advance_payment_method' is a
            // NOT NULL column (2025_03_06_133433 migration) — null was
            // dormant here until this wave gave AdvancedPaymentInvoice its
            // own HasFactory, the same "dormant until this wave activated
            // real writes through this factory" gap already seen on the
            // recruitments pivots in A4E.
            'advance_payment_method' => AdvancedPayment::PERCENTAGE->value,
            'fixed_amount'           => null,
            'deduct_down_payments'   => false,
            'consolidated_billing'   => false,
            'amount'                 => $amount,
            'currency_id'            => Currency::factory(),
            'company_id'             => Company::factory(),
            // No default creator_id here (#138 A4F): the model's own
            // creating() listener already defaults it to Auth::id() when
            // omitted — the acting test/request user, who by construction
            // belongs to the company being written to. Picking an arbitrary
            // pre-existing user (the prior default) could easily be a
            // member of a different company than the one this factory call
            // targets, tripping the new creator-membership guard for
            // reasons unrelated to the scenario under test.
        ];
    }

    /**
     * Indicate that down payments should be deducted.
     */
    public function deductDownPayments(): static
    {
        return $this->state(fn (array $attributes) => [
            'deduct_down_payments' => true,
        ]);
    }

    /**
     * Indicate that billing should be consolidated.
     */
    public function consolidatedBilling(): static
    {
        return $this->state(fn (array $attributes) => [
            'consolidated_billing' => true,
        ]);
    }
}

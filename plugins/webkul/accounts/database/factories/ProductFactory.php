<?php

namespace Webkul\Account\Database\Factories;

use Webkul\Account\Models\Account;
use Webkul\Account\Models\Product;
use Webkul\Product\Database\Factories\ProductFactory as BaseProductFactory;

/**
 * @extends BaseProductFactory
 */
class ProductFactory extends BaseProductFactory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Product::class;

    /**
     * Account has no company_id of its own — a Product's own income/
     * expense accounts must be explicitly enabled for the Product's
     * company via the accounts_account_companies pivot, since MoveLine
     * strictly enforces that for whichever account it ultimately resolves
     * (including a Product's own, via getAccountsFromFiscalPosition())
     * (#138 review, 2026-07-18). Done after create, on the Product's own
     * FINAL company_id, so it still works when a caller overrides
     * company_id via create([...]).
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Product $product): void {
            foreach ([$product->property_account_income_id, $product->property_account_expense_id] as $accountId) {
                Account::ensureEnabledForCompany($accountId, $product->company_id);
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
        return array_merge(parent::definition(), [
            'property_account_income_id'  => null,
            'property_account_expense_id' => null,
            'image'                       => null,
            'service_type'                => null,
            'sale_line_warn'              => null,
            'expense_policy'              => null,
            'invoice_policy'              => null,
            'sale_line_warn_msg'          => null,
            'sales_ok'                    => true,
            'purchase_ok'                 => true,
        ]);
    }

    /**
     * Indicate that the product has income account.
     */
    public function withIncomeAccount(): static
    {
        return $this->state(fn (array $attributes) => [
            'property_account_income_id' => Account::factory()->income(),
        ]);
    }

    /**
     * Indicate that the product has expense account.
     */
    public function withExpenseAccount(): static
    {
        return $this->state(fn (array $attributes) => [
            'property_account_expense_id' => Account::factory()->expense(),
        ]);
    }

    /**
     * Indicate that the product has both income and expense accounts.
     */
    public function withAccounts(): static
    {
        return $this->state(fn (array $attributes) => [
            'property_account_income_id'  => Account::factory()->income(),
            'property_account_expense_id' => Account::factory()->expense(),
        ]);
    }
}

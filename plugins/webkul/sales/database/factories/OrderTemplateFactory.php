<?php

namespace Webkul\Sale\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Sale\Models\OrderTemplate;
use Webkul\Support\Models\Company;

/**
 * @extends Factory<OrderTemplate>
 */
class OrderTemplateFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = OrderTemplate::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sort'                       => fake()->randomNumber(),
            // Was null (#138 PR4 A4H): with OrderTemplate now under
            // HasStrictCompanyId a null company is only resolvable from an
            // authenticated actor, so the bare factory failed outright
            // under a system context. Every call site that cares still
            // passes an explicit company_id, which overrides this without
            // creating anything.
            'company_id'                 => Company::factory(),
            // Left null on purpose: a Journal has a company of its own and
            // this factory cannot guarantee a match, so inventing one here
            // would only trip the template's journal guard.
            'journal_id'                 => null,
            // No default creator_id: the model defaults it to Auth::id(),
            // the acting user, who by construction belongs to the company
            // being written to (#138 A4F).
            'creator_id'                 => null,
            'name'                       => fake()->name,
            'number_of_days'             => fake()->numberBetween(1, 90),
            'require_signature'          => fake()->boolean(30),
            'require_payment'            => fake()->boolean(30),
            // 'recurrence', 'recurrence_period', 'mail_template_id',
            // 'auto_confirmation' and 'confirmation_mail_template' used to
            // be declared here and exist in NO migration: factories write
            // unguarded, so every insert through this factory failed with
            // "Unknown column 'recurrence'". Dormant until this wave gave
            // OrderTemplate a working newFactory(), the same shape as the
            // NOT NULL advance_payment_method bug surfaced in A4F.
            'is_active'                  => fake()->boolean(80),
            'note'                       => fake()->optional()->paragraph(),
        ];
    }
}

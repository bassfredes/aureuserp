<?php

namespace Webkul\Sale\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Sale\Models\Team;
use Webkul\Support\Models\Company;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Team::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sort'            => fake()->randomNumber(),
            // Was null (#138 PR4 A4G): with Team now under
            // HasStrictCompanyId a null company is only resolvable from an
            // authenticated actor, so the bare factory failed outright
            // under a system context. Same default AdvancedPaymentInvoice
            // uses; every call site that cares still passes an explicit
            // company_id, which overrides this without creating anything.
            'company_id'      => Company::factory(),
            'user_id'         => null,
            'color'           => fake()->hexColor,
            // No default creator_id: the model defaults it to Auth::id(),
            // the acting user, who by construction belongs to the company
            // being written to. An arbitrary pre-existing user would often
            // be a member of a different one (#138 A4F).
            'creator_id'      => null,
            'name'            => fake()->name,
            'is_active'       => fake()->boolean,
            'invoiced_target' => fake()->randomNumber(),
        ];
    }
}

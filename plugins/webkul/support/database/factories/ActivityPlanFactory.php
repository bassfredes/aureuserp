<?php

namespace Webkul\Support\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ActivityPlan;
use Webkul\Support\Models\Company;

/**
 * @extends Factory<ActivityPlan>
 */
class ActivityPlanFactory extends Factory
{
    protected $model = ActivityPlan::class;

    public function definition(): array
    {
        return [
            'name'       => fake()->words(3, true),
            'plugin'     => 'support',
            'is_active'  => true,
            'company_id' => Company::factory(),
            'creator_id' => User::query()->value('id') ?? User::factory(),
        ];
    }
}

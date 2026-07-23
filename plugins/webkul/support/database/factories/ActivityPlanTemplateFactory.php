<?php

namespace Webkul\Support\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ActivityPlan;
use Webkul\Support\Models\ActivityPlanTemplate;
use Webkul\Support\Models\ActivityType;

/**
 * @extends Factory<ActivityPlanTemplate>
 */
class ActivityPlanTemplateFactory extends Factory
{
    protected $model = ActivityPlanTemplate::class;

    public function definition(): array
    {
        return [
            'sort'             => fake()->numberBetween(1, 100),
            'summary'          => fake()->sentence(),
            'note'             => fake()->optional()->paragraph(),
            'delay_count'      => fake()->numberBetween(0, 30),
            'delay_unit'       => fake()->randomElement(['days', 'weeks', 'months']),
            'delay_from'       => fake()->randomElement(['previous_activity', 'begin']),
            // ActivityPlanTemplate::saving() requires a resolvable,
            // authorized ActivityPlan (#138 PR4 ola4B) — a bare null would
            // now fail closed, unlike the pre-scoping factory default.
            'plan_id'          => ActivityPlan::factory(),
            'activity_type_id' => ActivityType::factory(),
            'responsible_id'   => null,
            'creator_id'       => User::query()->value('id') ?? User::factory(),
        ];
    }
}

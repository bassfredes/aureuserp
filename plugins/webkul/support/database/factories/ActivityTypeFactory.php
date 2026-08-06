<?php

namespace Webkul\Support\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ActivityType;

/**
 * @extends Factory<ActivityType>
 */
class ActivityTypeFactory extends Factory
{
    protected $model = ActivityType::class;

    public function definition(): array
    {
        return [
            'sort'        => fake()->numberBetween(1, 100),
            'plugin'      => 'support',
            'name'        => fake()->words(2, true),
            'summary'     => fake()->sentence(),
            'is_active'   => true,
            'keep_done'   => false,
            'creator_id'  => User::query()->value('id') ?? User::factory(),
        ];
    }
}

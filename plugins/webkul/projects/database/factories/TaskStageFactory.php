<?php

namespace Webkul\Project\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Project\Models\Project;
use Webkul\Project\Models\TaskStage;
use Webkul\Security\Models\User;

/**
 * @extends Factory<TaskStage>
 */
class TaskStageFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = TaskStage::class;

    /**
     * Define the model's default state.
     *
     * @return array<string => , mixed>
     */
    public function definition(): array
    {
        return [
            'name'         => fake()->name(),
            'sort'         => fake()->randomNumber(),
            'is_active'    => true,
            'is_collapsed' => false,
            'project_id'   => Project::factory(),
            // Left unset by default: the model's own saving() hook always
            // re-derives company_id from project_id and now rejects an
            // explicit mismatch — a random Company::factory() here would
            // spuriously conflict with whatever Project a caller overrides
            // project_id with (#138 PR4 ola4A).
            'user_id'      => User::query()->value('id') ?? User::factory(),
            'creator_id'   => User::query()->value('id') ?? User::factory(),
        ];
    }
}

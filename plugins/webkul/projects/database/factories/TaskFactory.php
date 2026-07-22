<?php

namespace Webkul\Project\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Partner\Models\Partner;
use Webkul\Project\Models\Project;
use Webkul\Project\Models\Task;
use Webkul\Project\Models\TaskStage;
use Webkul\Security\Models\User;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Task::class;

    /**
     * Define the model's default state.
     *
     * @return array<string => , mixed>
     */
    public function definition(): array
    {
        // Built eagerly (not as two independent Model::factory() placeholders)
        // so the default project_id and stage_id are always self-consistent —
        // Task now validates that its stage_id's own project_id matches its
        // own project_id, which two independently-random factory defaults
        // would violate almost every time (#138 PR4 ola4A round 2 review). A
        // caller overriding either key still wins — Factory::create() merges
        // overrides over this array, so this eager pair is simply unused
        // (and harmlessly orphaned) whenever project_id/stage_id are
        // explicitly provided.
        $project = Project::factory()->create();
        $stage = TaskStage::factory()->create(['project_id' => $project->id]);

        return [
            'title'               => fake()->name(),
            'description'         => fake()->sentence(),
            'visibility'          => 'public',
            'color'               => fake()->hexColor(),
            'priority'            => fake()->randomNumber(),
            'state'               => 'in_progress',
            'sort'                => fake()->randomNumber(),
            'deadline'            => fake()->date(),
            'is_active'           => true,
            'is_recurring'        => false,
            'working_hours_open'  => 0,
            'working_hours_close' => 0,
            'allocated_hours'     => $hours = fake()->randomNumber(),
            'effective_hours'     => 0,
            'remaining_hours'     => $hours,
            'total_hours_spent'   => 0,
            'overtime'            => 0,
            'progress'            => 0,
            'parent_id'           => null,
            'project_id'          => $project->id,
            'stage_id'            => $stage->id,
            'partner_id'          => Partner::query()->value('id') ?? Partner::factory(),
            // Left unset by default: the model's own saving() hook always
            // re-derives company_id from project_id and now rejects an
            // explicit mismatch — a random Company::factory() here would
            // spuriously conflict with whatever Project a caller overrides
            // project_id with (#138 PR4 ola4A).
            'creator_id'          => User::query()->value('id') ?? User::factory(),
        ];
    }
}

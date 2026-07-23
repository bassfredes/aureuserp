<?php

namespace Webkul\Project\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Partner\Models\Partner;
use Webkul\Project\Models\Project;
use Webkul\Project\Models\ProjectStage;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Project::class;

    /**
     * Define the model's default state.
     *
     * @return array<string => , mixed>
     */
    public function definition(): array
    {
        return [
            'name'                    => fake()->name(),
            'description'             => fake()->sentence(),
            'tasks_label'             => 'Tasks',
            'visibility'              => 'public',
            'color'                   => fake()->hexColor(),
            'sort'                    => fake()->randomNumber(),
            'start_date'              => fake()->date(),
            'end_date'                => fake()->date(),
            'allocated_hours'         => fake()->randomNumber(),
            'allow_timesheets'        => true,
            'allow_milestones'        => false,
            'allow_task_dependencies' => false,
            'is_active'               => true,
            // Reuses one of the shared (company_id-null) default stages
            // seeded by ProjectStageSeeder instead of creating a fresh,
            // independently-companied ProjectStage — since ProjectStage
            // now authorizes its own company_id on create (#138 PR4
            // ola4B), a bare ProjectStage::factory() default here would
            // trip that check whenever Project is created under a
            // specific CompanyContext (its own company_id and the fresh
            // stage's randomly-generated one would never match). Falls
            // back to null (a valid, nullable FK) when no shared stage is
            // visible to the current actor/context.
            'stage_id'                => ProjectStage::query()->whereNull('company_id')->value('id'),
            'partner_id'              => Partner::query()->value('id') ?? Partner::factory(),
            'company_id'              => Company::factory(),
            'user_id'                 => User::query()->value('id') ?? User::factory(),
            'creator_id'              => User::query()->value('id') ?? User::factory(),
        ];
    }
}

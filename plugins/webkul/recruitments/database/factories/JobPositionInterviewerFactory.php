<?php

namespace Webkul\Recruitment\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\JobPositionInterviewer;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Scopes\CompanyScope;

/**
 * user_id used to reuse whatever user happened to exist first,
 * independent of the JobPosition's own company — incompatible with the
 * membership check added in #138 PR4 A4E. Derived from the JobPosition's
 * resolved company_id instead, matching the EmployeeFactory precedent
 * (#138 PR4 A4D): no bypass inside the factory itself, only correlated
 * attributes.
 *
 * @extends Factory<JobPositionInterviewer>
 */
class JobPositionInterviewerFactory extends Factory
{
    protected $model = JobPositionInterviewer::class;

    public function definition(): array
    {
        return [
            'job_position_id' => JobPosition::factory(),
            'user_id'         => fn (array $attributes) => User::withoutEvents(fn () => User::factory()->create([
                'default_company_id' => JobPosition::withoutGlobalScope(CompanyScope::class)->find($attributes['job_position_id'])->company_id,
            ]))->id,
        ];
    }
}

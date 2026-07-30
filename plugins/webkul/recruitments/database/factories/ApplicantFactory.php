<?php

namespace Webkul\Recruitment\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Employee\Models\Department;
use Webkul\Recruitment\Enums\ApplicationStatus;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\RefuseReason;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\UTMMedium;
use Webkul\Support\Models\UTMSource;

/**
 * candidate_id/job_id/department_id/recruiter_id used to default to
 * independently-randomized companies (a fresh Candidate::factory(),
 * JobPosition::factory(), Department::factory() each rolling their own
 * Company::factory(), and recruiter_id reusing whatever user happened
 * to exist first) — harmless while Applicant carried no relation
 * validation, but incompatible with the same-company enforcement added
 * in #138 PR4 A4E. Each now derives from the already-resolved
 * company_id, matching the EmployeeFactory precedent (#138 PR4 A4D): no
 * bypass inside the factory itself, only correlated attributes relying
 * on whatever actor/context the caller already has active.
 */

/**
 * @extends Factory<Applicant>
 */
class ApplicantFactory extends Factory
{
    protected $model = Applicant::class;

    public function definition(): array
    {
        return [
            'state'                   => ApplicationStatus::ONGOING,
            'priority'                => 0,
            'is_active'               => true,
            'probability'             => 0,
            'salary_proposed'         => 0,
            'salary_expected'         => 0,
            'delay_close'             => 0,
            'email_cc'                => null,
            'salary_proposed_extra'   => null,
            'salary_expected_extra'   => null,
            'applicant_properties'    => null,
            'applicant_notes'         => null,
            'create_date'             => now(),
            'date_opened'             => null,
            'date_closed'             => null,
            'date_last_stage_updated' => null,
            'refuse_date'             => null,

            // Relationships
            'company_id'       => Company::factory(),
            'candidate_id'     => fn (array $attributes) => Candidate::factory()->create(['company_id' => $attributes['company_id']])->id,
            // Stage has no HasFactory of its own (dormant, pre-existing,
            // out of authorized scope to add) — StageFactory::new()
            // directly, not Stage::factory(); withLegend() supplies the
            // three NOT NULL legend_* columns StageFactory's own
            // definition() otherwise defaults to null.
            'stage_id'         => fn () => StageFactory::new()->withLegend()->create()->id,
            'last_stage_id'    => null,
            'recruiter_id'     => fn (array $attributes) => User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $attributes['company_id']]))->id,
            'job_id'           => fn (array $attributes) => JobPosition::factory()->create(['company_id' => $attributes['company_id']])->id,
            'department_id'    => fn (array $attributes) => Department::factory()->create(['company_id' => $attributes['company_id']])->id,
            'refuse_reason_id' => null,
            'creator_id'       => User::query()->value('id') ?? User::factory(),
            'source_id'        => null,
            'medium_id'        => null,
        ];
    }

    public function hired(): static
    {
        return $this->state(fn (array $attributes) => [
            'state'       => ApplicationStatus::HIRED,
            'probability' => 100,
            'date_closed' => now(),
        ]);
    }

    public function contract(): static
    {
        return $this->state(fn (array $attributes) => [
            'state'       => ApplicationStatus::ONGOING,
            'probability' => 80,
        ]);
    }

    public function refused(): static
    {
        return $this->state(fn (array $attributes) => [
            'state'            => ApplicationStatus::REFUSED,
            'is_active'        => false,
            'refuse_date'      => now(),
            'refuse_reason_id' => RefuseReason::factory(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function withUTM(): static
    {
        return $this->state(fn (array $attributes) => [
            'source_id' => UTMSource::factory(),
            'medium_id' => UTMMedium::factory(),
        ]);
    }

    public function withSalary(): static
    {
        return $this->state(fn (array $attributes) => [
            'salary_proposed' => fake()->randomFloat(2, 30000, 150000),
            'salary_expected' => fake()->randomFloat(2, 35000, 160000),
        ]);
    }

    public function highPriority(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => 3,
        ]);
    }
}

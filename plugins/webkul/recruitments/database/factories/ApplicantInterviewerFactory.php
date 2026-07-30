<?php

namespace Webkul\Recruitment\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\ApplicantInterviewer;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Scopes\CompanyScope;

/**
 * interviewer_id used to reuse whatever user happened to exist first,
 * independent of the Applicant's own company — incompatible with the
 * membership check added in #138 PR4 A4E. Derived from the Applicant's
 * resolved company_id instead, matching the EmployeeFactory precedent
 * (#138 PR4 A4D): no bypass inside the factory itself, only correlated
 * attributes.
 *
 * @extends Factory<ApplicantInterviewer>
 */
class ApplicantInterviewerFactory extends Factory
{
    protected $model = ApplicantInterviewer::class;

    public function definition(): array
    {
        return [
            'applicant_id'   => Applicant::factory(),
            'interviewer_id' => fn (array $attributes) => User::withoutEvents(fn () => User::factory()->create([
                'default_company_id' => Applicant::withoutGlobalScope(CompanyScope::class)->find($attributes['applicant_id'])->company_id,
            ]))->id,
        ];
    }
}

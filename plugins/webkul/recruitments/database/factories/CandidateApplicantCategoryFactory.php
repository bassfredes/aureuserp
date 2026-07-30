<?php

namespace Webkul\Recruitment\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Recruitment\Models\ApplicantCategory;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Recruitment\Models\CandidateApplicantCategory;

/**
 * @extends Factory<CandidateApplicantCategory>
 */
class CandidateApplicantCategoryFactory extends Factory
{
    protected $model = CandidateApplicantCategory::class;

    public function definition(): array
    {
        return [
            'candidate_id' => Candidate::factory(),
            // The real column is 'category_id' (2025_01_10_045048
            // migration) — 'applicant_category_id' never existed on this
            // table, dormant until this wave activated real writes
            // through this factory (#138 PR4 A4E).
            'category_id'  => ApplicantCategory::factory(),
        ];
    }
}

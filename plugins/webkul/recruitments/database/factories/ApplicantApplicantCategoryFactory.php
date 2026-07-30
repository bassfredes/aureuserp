<?php

namespace Webkul\Recruitment\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\ApplicantApplicantCategory;
use Webkul\Recruitment\Models\ApplicantCategory;

/**
 * @extends Factory<ApplicantApplicantCategory>
 */
class ApplicantApplicantCategoryFactory extends Factory
{
    protected $model = ApplicantApplicantCategory::class;

    public function definition(): array
    {
        return [
            'applicant_id' => Applicant::factory(),
            // The real column is 'category_id' (2025_01_13_075926
            // migration) — 'applicant_category_id' never existed on this
            // table, dormant until this wave activated real writes
            // through this factory (#138 PR4 A4E).
            'category_id'  => ApplicantCategory::factory(),
        ];
    }
}

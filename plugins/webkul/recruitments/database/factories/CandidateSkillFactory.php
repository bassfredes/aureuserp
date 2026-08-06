<?php

namespace Webkul\Recruitment\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Employee\Models\Skill;
use Webkul\Employee\Models\SkillLevel;
use Webkul\Employee\Models\SkillType;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Recruitment\Models\CandidateSkill;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Scopes\CompanyScope;

/**
 * @extends Factory<CandidateSkill>
 */
class CandidateSkillFactory extends Factory
{
    protected $model = CandidateSkill::class;

    public function definition(): array
    {
        return [
            'skill_id'       => Skill::factory(),
            'skill_level_id' => SkillLevel::factory(),
            'skill_type_id'  => SkillType::factory(),
            'user_id'        => null,
            'creator_id'     => User::query()->value('id') ?? User::factory(),
            'candidate_id'   => Candidate::factory(),
        ];
    }

    public function withUser(): static
    {
        // Derived from the Candidate's resolved company_id — a randomly
        // reused/independent user broke the membership check added in
        // #138 PR4 A4E.
        return $this->state(fn (array $attributes) => [
            'user_id' => User::withoutEvents(fn () => User::factory()->create([
                'default_company_id' => Candidate::withoutGlobalScope(CompanyScope::class)->find($attributes['candidate_id'])->company_id,
            ]))->id,
        ]);
    }
}

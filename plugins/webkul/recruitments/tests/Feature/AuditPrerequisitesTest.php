<?php

use Illuminate\Support\Facades\Artisan;
use Webkul\Employee\Models\Skill;
use Webkul\Employee\Models\SkillLevel;
use Webkul\Employee\Models\SkillType;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Recruitment\Models\CandidateSkill;
use Webkul\Security\Models\User;

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

/**
 * `recruitments` has no entry in TestBootstrapHelper::ensurePluginInstalled()'s
 * table map and no prior test suite of its own — installing it inline here
 * rather than extending that shared helper for a single, narrowly-scoped
 * regression test (Intelligent-Integration-Suite#138 audit, PR 0).
 */
beforeEach(function () {
    TestBootstrapHelper::ensureERPInstalled();

    if (! Illuminate\Support\Facades\Schema::hasTable('recruitments_candidate_skills')) {
        Artisan::call('recruitments:install', ['--no-interaction' => true]);
    }
});

/**
 * CandidateSkill.php declared `use Illuminate\Support\Facades\Auth;` twice
 * — a PHP fatal at class-load time ("Cannot use ... as Auth because the
 * name is already in use"), which blocked reflecting over or instantiating
 * this class at all (#138 audit, PR 0 prerequisite #2).
 *
 * Uses Model::create() directly throughout rather than any of the
 * ::factory() calls this chain would otherwise need (Candidate,
 * SkillFactory) — each hits its own separate pre-existing gap (missing
 * newFactory() override; SkillFactory.php calling
 * SkillTypeFactory::factory() on a Factory class instead of
 * SkillType::factory() on the model), both out of scope for this PR.
 */
it('loads CandidateSkill without a PHP fatal and can be created', function () {
    expect(fn () => new ReflectionClass(CandidateSkill::class))->not->toThrow(\Throwable::class);

    // Candidate::creating() derives creator_id from Auth::user()->id with
    // no null-safe operator — needs an authenticated actor, unrelated to
    // the fatal under test here.
    test()->actingAs(User::factory()->create());

    // Candidate::saved() auto-creates a linked Partner using the
    // Candidate's own `name`, which is NOT NULL on partners_partners.
    $candidate = Candidate::create(['name' => 'Test Candidate']);

    $skillType = SkillType::create(['name' => 'Technical']);
    $skill = Skill::create(['name' => 'PHP', 'skill_type_id' => $skillType->id]);
    $skillLevel = SkillLevel::create(['name' => 'Expert', 'skill_type_id' => $skillType->id]);

    $candidateSkill = CandidateSkill::create([
        'candidate_id'   => $candidate->id,
        'skill_id'       => $skill->id,
        'skill_level_id' => $skillLevel->id,
        'skill_type_id'  => $skillType->id,
    ]);

    expect(CandidateSkill::query()->whereKey($candidateSkill->id)->exists())->toBeTrue();
});

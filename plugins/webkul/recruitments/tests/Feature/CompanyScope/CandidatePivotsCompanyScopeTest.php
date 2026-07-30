<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Employee\Models\Skill;
use Webkul\Employee\Models\SkillLevel;
use Webkul\Employee\Models\SkillType;
use Webkul\Recruitment\Database\Factories\ApplicantCategoryFactory;
use Webkul\Recruitment\Models\Candidate;
use Webkul\Recruitment\Models\CandidateApplicantCategory;
use Webkul\Recruitment\Models\CandidateSkill;
use Webkul\Recruitment\Models\Scopes\ParentDerivedCompanyScope;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('recruitments');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

/**
 * SkillTypeFactory/SkillLevelFactory/SkillFactory carry dormant,
 * pre-existing bugs unrelated to company-scope (SkillTypeFactory sets a
 * 'status' key that has never existed on employees_skill_types — the
 * real column is 'is_active'; SkillLevelFactory's own skill_type_id
 * default nests the same broken SkillTypeFactory) — out of authorized
 * scope to fix (employees plugin, not one of the ten A4E models).
 * Bypassed here via direct ::create() calls with only real, fillable
 * columns, matching the SkillFactory/EmployeeSkillFactory workaround
 * precedent from #138 PR4 A4D.
 */
function makeSkillType(): SkillType
{
    return SkillType::create(['name' => fake()->word(), 'color' => fake()->hexColor(), 'is_active' => true]);
}

function makeSkillLevel(int $skillTypeId): SkillLevel
{
    return SkillLevel::create(['name' => fake()->word(), 'level' => fake()->numberBetween(5, 100), 'default_level' => false, 'skill_type_id' => $skillTypeId]);
}

function makeSkill(int $skillTypeId): Skill
{
    return Skill::create(['name' => fake()->word(), 'skill_type_id' => $skillTypeId]);
}

// ── CandidateApplicantCategory: parent_scoped via Candidate (#138 PR4 A4E) ──

it('shows a user only CandidateApplicantCategory rows of Candidates in their own company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $candidateA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Candidate::factory()->create(['company_id' => $companyA->id]));
    $candidateB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Candidate::factory()->create(['company_id' => $companyB->id]));

    $rowA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CandidateApplicantCategory::create(['candidate_id' => $candidateA->id, 'category_id' => ApplicantCategoryFactory::new()->create()->id]));
    $rowB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CandidateApplicantCategory::create(['candidate_id' => $candidateB->id, 'category_id' => ApplicantCategoryFactory::new()->create()->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $ids = CandidateApplicantCategory::query()->pluck('candidate_id');

    expect($ids)->toContain($rowA->candidate_id)
        ->not->toContain($rowB->candidate_id);
});

it('forbids creating a CandidateApplicantCategory under a Candidate the acting user is not authorized for', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $candidateB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Candidate::factory()->create(['company_id' => $companyB->id]));
    $category = ApplicantCategoryFactory::new()->create();

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => CandidateApplicantCategory::create(['candidate_id' => $candidateB->id, 'category_id' => $category->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids deleting a CandidateApplicantCategory whose Candidate is hidden in another company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $candidateB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Candidate::factory()->create(['company_id' => $companyB->id]));
    CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CandidateApplicantCategory::create(['candidate_id' => $candidateB->id, 'category_id' => ApplicantCategoryFactory::new()->create()->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $rowBUnscoped = CandidateApplicantCategory::withoutGlobalScope(ParentDerivedCompanyScope::class)->where('candidate_id', $candidateB->id)->firstOrFail();

    expect(fn () => $rowBUnscoped->delete())->toThrow(AuthorizationException::class);
});

it('attaches and detaches an ApplicantCategory through the real Candidate::categories() relation, respecting company scope', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $candidate = Candidate::factory()->create(['company_id' => $companyA->id]);
    $category = ApplicantCategoryFactory::new()->create();

    $candidate->categories()->attach($category->id);

    $this->assertDatabaseHas('recruitments_candidate_applicant_categories', [
        'candidate_id' => $candidate->id,
        'category_id'  => $category->id,
    ]);

    $candidate->categories()->detach($category->id);

    $this->assertDatabaseMissing('recruitments_candidate_applicant_categories', [
        'candidate_id' => $candidate->id,
        'category_id'  => $category->id,
    ]);
});

// ── CandidateSkill: parent_scoped via Candidate + membership (#138 PR4 A4E) ──

it('shows a user only CandidateSkills of Candidates in their own company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $candidateA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Candidate::factory()->create(['company_id' => $companyA->id]));
    $candidateB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Candidate::factory()->create(['company_id' => $companyB->id]));

    $skillTypeId = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => makeSkillType()->id);

    $skillA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CandidateSkill::create(['candidate_id' => $candidateA->id, 'skill_id' => makeSkill($skillTypeId)->id, 'skill_level_id' => makeSkillLevel($skillTypeId)->id, 'skill_type_id' => $skillTypeId]));
    $skillB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CandidateSkill::create(['candidate_id' => $candidateB->id, 'skill_id' => makeSkill($skillTypeId)->id, 'skill_level_id' => makeSkillLevel($skillTypeId)->id, 'skill_type_id' => $skillTypeId]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $ids = CandidateSkill::query()->pluck('id');

    expect($ids)->toContain($skillA->id)
        ->not->toContain($skillB->id);
});

it('forbids creating a CandidateSkill under a Candidate the acting user is not authorized for', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $candidateB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Candidate::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $skillType = makeSkillType();

    expect(fn () => CandidateSkill::create(['candidate_id' => $candidateB->id, 'skill_id' => makeSkill($skillType->id)->id, 'skill_level_id' => makeSkillLevel($skillType->id)->id, 'skill_type_id' => $skillType->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids creating a CandidateSkill without a resolvable candidate_id', function () {
    // #138 PR4 A4D review 4811942781,
    // CHANGES_REQUIRED_A4D_EMPLOYEES_FAIL_CLOSED_OWNER_RESOLUTION, applied
    // here from the start: an unresolvable parent must reject the
    // mutation, not silently pass it through.
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $skillType = makeSkillType();

    expect(fn () => CandidateSkill::create(['candidate_id' => null, 'skill_id' => makeSkill($skillType->id)->id, 'skill_level_id' => makeSkillLevel($skillType->id)->id, 'skill_type_id' => $skillType->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids a CandidateSkill.user_id referencing a User with no membership in the Candidate company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $candidateA = Candidate::factory()->create(['company_id' => $companyA->id]);
    $outsiderUser = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyB->id]));
    $skillType = makeSkillType();

    expect(fn () => CandidateSkill::create([
        'candidate_id'   => $candidateA->id,
        'skill_id'       => makeSkill($skillType->id)->id,
        'skill_level_id' => makeSkillLevel($skillType->id)->id,
        'skill_type_id'  => $skillType->id,
        'user_id'        => $outsiderUser->id,
    ]))->toThrow(AuthorizationException::class);
});

it('forbids moving a CandidateSkill to a Candidate in a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $candidateB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Candidate::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $candidateA = Candidate::factory()->create(['company_id' => $companyA->id]);
    $skillType = makeSkillType();

    $skill = CandidateSkill::create(['candidate_id' => $candidateA->id, 'skill_id' => makeSkill($skillType->id)->id, 'skill_level_id' => makeSkillLevel($skillType->id)->id, 'skill_type_id' => $skillType->id]);

    expect(fn () => $skill->update(['candidate_id' => $candidateB->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids deleting a CandidateSkill whose Candidate is hidden in another company, fetched via a partial column projection', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $candidateB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Candidate::factory()->create(['company_id' => $companyB->id]));
    $skillType = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => makeSkillType());
    $skillB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => CandidateSkill::create(['candidate_id' => $candidateB->id, 'skill_id' => makeSkill($skillType->id)->id, 'skill_level_id' => makeSkillLevel($skillType->id)->id, 'skill_type_id' => $skillType->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $skillBPartial = CandidateSkill::withoutGlobalScope(ParentDerivedCompanyScope::class)->select('id')->findOrFail($skillB->id);

    expect($skillBPartial->getOriginal('candidate_id'))->toBeNull();
    expect(fn () => $skillBPartial->delete())->toThrow(AuthorizationException::class);
    $this->assertDatabaseHas('recruitments_candidate_skills', ['id' => $skillB->id]);
});

it('allows creating, updating and deleting a CandidateSkill under the acting user own company', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $candidateA = Candidate::factory()->create(['company_id' => $companyA->id]);
    $skillType = makeSkillType();

    $skill = CandidateSkill::create(['candidate_id' => $candidateA->id, 'skill_id' => makeSkill($skillType->id)->id, 'skill_level_id' => makeSkillLevel($skillType->id)->id, 'skill_type_id' => $skillType->id]);

    $skill->update(['creator_id' => $user->id]);
    expect($skill->fresh()->creator_id)->toBe($user->id);

    $skill->delete();
    $this->assertDatabaseMissing('recruitments_candidate_skills', ['id' => $skill->id]);
});

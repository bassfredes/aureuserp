<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Recruitment\Database\Factories\ApplicantCategoryFactory;
use Webkul\Recruitment\Models\Applicant;
use Webkul\Recruitment\Models\ApplicantApplicantCategory;
use Webkul\Recruitment\Models\ApplicantInterviewer;
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

// ── ApplicantApplicantCategory: parent_scoped via Applicant (#138 PR4 A4E) ──

it('shows a user only ApplicantApplicantCategory rows of Applicants in their own company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $applicantA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Applicant::factory()->create(['company_id' => $companyA->id]));
    $applicantB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Applicant::factory()->create(['company_id' => $companyB->id]));

    $rowA = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => ApplicantApplicantCategory::create(['applicant_id' => $applicantA->id, 'category_id' => ApplicantCategoryFactory::new()->create()->id]));
    $rowB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => ApplicantApplicantCategory::create(['applicant_id' => $applicantB->id, 'category_id' => ApplicantCategoryFactory::new()->create()->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $ids = ApplicantApplicantCategory::query()->pluck('applicant_id');

    expect($ids)->toContain($rowA->applicant_id)
        ->not->toContain($rowB->applicant_id);
});

it('forbids creating an ApplicantApplicantCategory under an Applicant the acting user is not authorized for', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $applicantB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Applicant::factory()->create(['company_id' => $companyB->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => ApplicantApplicantCategory::create(['applicant_id' => $applicantB->id, 'category_id' => ApplicantCategoryFactory::new()->create()->id]))
        ->toThrow(AuthorizationException::class);
});

it('forbids deleting an ApplicantApplicantCategory whose Applicant is hidden in another company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $applicantB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Applicant::factory()->create(['company_id' => $companyB->id]));
    $rowB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => ApplicantApplicantCategory::create(['applicant_id' => $applicantB->id, 'category_id' => ApplicantCategoryFactory::new()->create()->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $rowBUnscoped = ApplicantApplicantCategory::withoutGlobalScope(ParentDerivedCompanyScope::class)->where('applicant_id', $applicantB->id)->firstOrFail();

    expect(fn () => $rowBUnscoped->delete())->toThrow(AuthorizationException::class);
});

it('attaches and detaches an ApplicantCategory through the real Applicant::categories() relation, respecting company scope', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $applicant = Applicant::factory()->create(['company_id' => $companyA->id]);
    $category = ApplicantCategoryFactory::new()->create();

    $applicant->categories()->attach($category->id);

    $this->assertDatabaseHas('recruitments_applicant_applicant_categories', [
        'applicant_id' => $applicant->id,
        'category_id'  => $category->id,
    ]);

    $applicant->categories()->detach($category->id);

    $this->assertDatabaseMissing('recruitments_applicant_applicant_categories', [
        'applicant_id' => $applicant->id,
        'category_id'  => $category->id,
    ]);
});

// ── ApplicantInterviewer: parent_scoped via Applicant + membership (#138 PR4 A4E) ──

it('forbids creating an ApplicantInterviewer whose interviewer has no membership in the Applicant company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $applicant = Applicant::factory()->create(['company_id' => $companyA->id]);
    $outsiderUser = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyB->id]));

    expect(fn () => ApplicantInterviewer::create(['applicant_id' => $applicant->id, 'interviewer_id' => $outsiderUser->id]))
        ->toThrow(AuthorizationException::class);
});

it('allows creating an ApplicantInterviewer whose interviewer has membership in the Applicant company', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $applicant = Applicant::factory()->create(['company_id' => $companyA->id]);
    $interviewerUser = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));

    $row = ApplicantInterviewer::create(['applicant_id' => $applicant->id, 'interviewer_id' => $interviewerUser->id]);

    expect($row->interviewer_id)->toBe($interviewerUser->id);
});

it('forbids deleting an ApplicantInterviewer whose Applicant is hidden in another company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $applicantB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => Applicant::factory()->create(['company_id' => $companyB->id]));
    $interviewerUser = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyB->id])));
    $rowB = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => ApplicantInterviewer::create(['applicant_id' => $applicantB->id, 'interviewer_id' => $interviewerUser->id]));

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $rowBUnscoped = ApplicantInterviewer::withoutGlobalScope(ParentDerivedCompanyScope::class)->where('applicant_id', $applicantB->id)->firstOrFail();

    expect(fn () => $rowBUnscoped->delete())->toThrow(AuthorizationException::class);
});

it('attaches and detaches an interviewer through the real Applicant::interviewer() relation, respecting company scope', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $applicant = Applicant::factory()->create(['company_id' => $companyA->id]);
    $interviewerUser = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));

    $applicant->interviewer()->attach($interviewerUser->id);

    $this->assertDatabaseHas('recruitments_applicant_interviewers', [
        'applicant_id'   => $applicant->id,
        'interviewer_id' => $interviewerUser->id,
    ]);

    $applicant->interviewer()->detach($interviewerUser->id);

    $this->assertDatabaseMissing('recruitments_applicant_interviewers', [
        'applicant_id'   => $applicant->id,
        'interviewer_id' => $interviewerUser->id,
    ]);
});

it('forbids attaching an interviewer with no membership in the Applicant company through the real relation', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $applicant = Applicant::factory()->create(['company_id' => $companyA->id]);
    $outsiderUser = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyB->id]));

    expect(fn () => $applicant->interviewer()->attach($outsiderUser->id))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('recruitments_applicant_interviewers', [
        'applicant_id'   => $applicant->id,
        'interviewer_id' => $outsiderUser->id,
    ]);
});

<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Models\UtmCampaign;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../Helpers/SecurityHelper.php';
require_once __DIR__.'/../Helpers/TestBootstrapHelper.php';

/**
 * #138 PR4 A4J: UtmCampaign — strict_company, no shared rows. A marketing
 * campaign always belongs to the company running it, same
 * HasCompanyScope + HasStrictCompanyId contract as Journal/PaymentTerm.
 * stage_id points at UtmStage, a global catalog carrying no company_id, so
 * no cross-company stage check is needed or exercised here.
 */
beforeEach(function () {
    TestBootstrapHelper::ensureERPInstalled();
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

function utmCampaignMemberOf(Company $company): User
{
    return User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id]));
}

function utmCampaignFor(Company $company): UtmCampaign
{
    return CompanyContext::runForCompany(
        $company->id,
        reason: 'test fixture setup',
        caller: __FILE__,
        callback: fn () => UtmCampaign::factory()->create(['company_id' => $company->id]),
    );
}

// ── read isolation ──────────────────────────────────────────────────────

it('hides a UtmCampaign from a company the user is not allowed to see', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $campaignA = utmCampaignFor($companyA);
    $campaignB = utmCampaignFor($companyB);

    test()->actingAs(utmCampaignMemberOf($companyA));

    expect(UtmCampaign::find($campaignA->id))->not->toBeNull();
    expect(UtmCampaign::find($campaignB->id))->toBeNull();
});

it('shows a multi-company actor campaigns from both allowed companies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $companyC = Company::factory()->create();

    $campaignA = utmCampaignFor($companyA);
    $campaignB = utmCampaignFor($companyB);
    $campaignC = utmCampaignFor($companyC);

    $user = utmCampaignMemberOf($companyA);
    $user->allowedCompanies()->syncWithoutDetaching([$companyB->id]);
    test()->actingAs($user);

    $ids = UtmCampaign::query()->pluck('id');

    expect($ids)->toContain($campaignA->id, $campaignB->id)
        ->not->toContain($campaignC->id);
});

// ── create ───────────────────────────────────────────────────────────────

it('allows creating a UtmCampaign for the acting user\'s own company', function () {
    $companyA = Company::factory()->create();

    test()->actingAs(utmCampaignMemberOf($companyA));

    $campaign = UtmCampaign::factory()->create(['company_id' => $companyA->id]);

    expect($campaign->exists)->toBeTrue();
    expect($campaign->company_id)->toBe($companyA->id);
});

it('forbids creating a UtmCampaign for a company the acting user is not allowed to write to', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    test()->actingAs(utmCampaignMemberOf($companyA));

    expect(fn () => UtmCampaign::factory()->create(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('utm_campaigns', ['company_id' => $companyB->id]);
});

it('forbids creating a UtmCampaign when no company_id can be resolved', function () {
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => null]));
    test()->actingAs($user);

    $before = DB::table('utm_campaigns')->count();

    expect(fn () => UtmCampaign::factory()->create(['company_id' => null]))
        ->toThrow(AuthorizationException::class);

    expect(DB::table('utm_campaigns')->count())->toBe($before);
});

it('defaults company_id from the acting user when none is given', function () {
    $companyA = Company::factory()->create();

    test()->actingAs(utmCampaignMemberOf($companyA));

    $campaign = UtmCampaign::factory()->create(['company_id' => null]);

    expect($campaign->company_id)->toBe($companyA->id);
});

// ── immutability ─────────────────────────────────────────────────────────

it('forbids changing a UtmCampaign\'s company_id on update', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = utmCampaignMemberOf($companyA);
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $campaign = UtmCampaign::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => $campaign->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('utm_campaigns', ['id' => $campaign->id, 'company_id' => $companyA->id]);
});

it('forbids updating a UtmCampaign that belongs to another company, even for a harmless field', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $campaignB = utmCampaignFor($companyB);

    test()->actingAs(utmCampaignMemberOf($companyA));

    $loaded = UtmCampaign::withoutGlobalScope(CompanyScope::class)->findOrFail($campaignB->id);

    expect(fn () => $loaded->update(['is_active' => false]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('utm_campaigns', ['id' => $campaignB->id, 'company_id' => $companyB->id]);
});

// ── delete ───────────────────────────────────────────────────────────────

it('forbids deleting a UtmCampaign from a different company than the acting user', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $campaignB = utmCampaignFor($companyB);

    test()->actingAs(utmCampaignMemberOf($companyA));

    $loaded = UtmCampaign::withoutGlobalScope(CompanyScope::class)->findOrFail($campaignB->id);

    expect(fn () => $loaded->delete())->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('utm_campaigns', ['id' => $campaignB->id]);
});

it('allows deleting a UtmCampaign from the acting user\'s own company', function () {
    $companyA = Company::factory()->create();

    test()->actingAs(utmCampaignMemberOf($companyA));

    $campaign = UtmCampaign::factory()->create(['company_id' => $companyA->id]);
    $campaign->delete();

    $this->assertDatabaseMissing('utm_campaigns', ['id' => $campaign->id]);
});

// ── fail closed and system context ─────────────────────────────────────

it('fails closed on UtmCampaign reads and writes when there is no authenticated user and no system context', function () {
    $companyA = Company::factory()->create();

    utmCampaignFor($companyA);

    expect(UtmCampaign::count())->toBe(0);

    expect(fn () => UtmCampaign::factory()->create(['company_id' => $companyA->id]))
        ->toThrow(AuthorizationException::class);
});

it('allows an explicit company system context to create a UtmCampaign', function () {
    $companyA = Company::factory()->create();

    CompanyContext::runForCompany($companyA->id, reason: 'test fixture setup', caller: __FILE__, callback: function () use ($companyA) {
        $campaign = UtmCampaign::factory()->create(['company_id' => $companyA->id]);

        expect($campaign->company_id)->toBe($companyA->id);
        expect(UtmCampaign::count())->toBe(1);
    });
});

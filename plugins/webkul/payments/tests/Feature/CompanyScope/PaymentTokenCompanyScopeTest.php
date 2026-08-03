<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Webkul\Payment\Models\PaymentToken;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';

/**
 * #138 PR4 A4J: PaymentToken — strict_company, no shared rows. A stored
 * payment-method token always belongs to a single company's gateway
 * credentials, unlike CurrencyRate's company_or_shared pattern. Same
 * HasCompanyScope + HasStrictCompanyId contract as Journal/PaymentTerm.
 */
beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('payments');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

function paymentTokenMemberOf(Company $company): User
{
    return User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id]));
}

function paymentTokenFor(Company $company): PaymentToken
{
    return CompanyContext::runForCompany(
        $company->id,
        reason: 'test fixture setup',
        caller: __FILE__,
        callback: fn () => PaymentToken::factory()->create(['company_id' => $company->id]),
    );
}

// ── read isolation ──────────────────────────────────────────────────────

it('hides a PaymentToken from a company the user is not allowed to see', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $tokenA = paymentTokenFor($companyA);
    $tokenB = paymentTokenFor($companyB);

    test()->actingAs(paymentTokenMemberOf($companyA));

    expect(PaymentToken::find($tokenA->id))->not->toBeNull();
    expect(PaymentToken::find($tokenB->id))->toBeNull();
});

it('shows a multi-company actor tokens from both allowed companies', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $companyC = Company::factory()->create();

    $tokenA = paymentTokenFor($companyA);
    $tokenB = paymentTokenFor($companyB);
    $tokenC = paymentTokenFor($companyC);

    $user = paymentTokenMemberOf($companyA);
    $user->allowedCompanies()->syncWithoutDetaching([$companyB->id]);
    test()->actingAs($user);

    $ids = PaymentToken::query()->pluck('id');

    expect($ids)->toContain($tokenA->id, $tokenB->id)
        ->not->toContain($tokenC->id);
});

// ── create ───────────────────────────────────────────────────────────────

it('allows creating a PaymentToken for the acting user\'s own company', function () {
    $companyA = Company::factory()->create();

    test()->actingAs(paymentTokenMemberOf($companyA));

    $token = PaymentToken::factory()->create(['company_id' => $companyA->id]);

    expect($token->exists)->toBeTrue();
    expect($token->company_id)->toBe($companyA->id);
});

it('forbids creating a PaymentToken for a company the acting user is not allowed to write to', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    test()->actingAs(paymentTokenMemberOf($companyA));

    expect(fn () => PaymentToken::factory()->create(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('payments_payment_tokens', ['company_id' => $companyB->id]);
});

it('forbids creating a PaymentToken when no company_id can be resolved', function () {
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => null]));
    test()->actingAs($user);

    $before = DB::table('payments_payment_tokens')->count();

    expect(fn () => PaymentToken::factory()->create(['company_id' => null]))
        ->toThrow(AuthorizationException::class);

    expect(DB::table('payments_payment_tokens')->count())->toBe($before);
});

it('defaults company_id from the acting user when none is given', function () {
    $companyA = Company::factory()->create();

    test()->actingAs(paymentTokenMemberOf($companyA));

    $token = PaymentToken::factory()->create(['company_id' => null]);

    expect($token->company_id)->toBe($companyA->id);
});

// ── immutability ─────────────────────────────────────────────────────────

it('forbids changing a PaymentToken\'s company_id on update', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = paymentTokenMemberOf($companyA);
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $token = PaymentToken::factory()->create(['company_id' => $companyA->id]);

    expect(fn () => $token->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('payments_payment_tokens', ['id' => $token->id, 'company_id' => $companyA->id]);
});

it('forbids updating a PaymentToken that belongs to another company, even for a harmless field', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $tokenB = paymentTokenFor($companyB);

    test()->actingAs(paymentTokenMemberOf($companyA));

    $loaded = PaymentToken::withoutGlobalScope(CompanyScope::class)->findOrFail($tokenB->id);

    expect(fn () => $loaded->update(['is_active' => false]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('payments_payment_tokens', ['id' => $tokenB->id, 'company_id' => $companyB->id]);
});

// ── delete ───────────────────────────────────────────────────────────────

it('forbids deleting a PaymentToken from a different company than the acting user', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $tokenB = paymentTokenFor($companyB);

    test()->actingAs(paymentTokenMemberOf($companyA));

    $loaded = PaymentToken::withoutGlobalScope(CompanyScope::class)->findOrFail($tokenB->id);

    expect(fn () => $loaded->delete())->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('payments_payment_tokens', ['id' => $tokenB->id]);
});

it('allows deleting a PaymentToken from the acting user\'s own company', function () {
    $companyA = Company::factory()->create();

    test()->actingAs(paymentTokenMemberOf($companyA));

    $token = PaymentToken::factory()->create(['company_id' => $companyA->id]);
    $token->delete();

    $this->assertDatabaseMissing('payments_payment_tokens', ['id' => $token->id]);
});

// ── fail closed and system context ─────────────────────────────────────

it('fails closed on PaymentToken reads and writes when there is no authenticated user and no system context', function () {
    $companyA = Company::factory()->create();

    paymentTokenFor($companyA);

    expect(PaymentToken::count())->toBe(0);

    expect(fn () => PaymentToken::factory()->create(['company_id' => $companyA->id]))
        ->toThrow(AuthorizationException::class);
});

it('allows an explicit company system context to create a PaymentToken', function () {
    $companyA = Company::factory()->create();

    CompanyContext::runForCompany($companyA->id, reason: 'test fixture setup', caller: __FILE__, callback: function () use ($companyA) {
        $token = PaymentToken::factory()->create(['company_id' => $companyA->id]);

        expect($token->company_id)->toBe($companyA->id);
        expect(PaymentToken::count())->toBe(1);
    });
});

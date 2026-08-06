<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Webkul\Account\Models\Move;
use Webkul\Payment\Models\PaymentTransaction;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';

/**
 * #138 PR4 A4J: PaymentTransaction — strict_company, no shared rows. A
 * gateway transaction always belongs to the company whose journal entry it
 * settles, same HasCompanyScope + HasStrictCompanyId contract as
 * Journal/PaymentTerm. move_id/journal_id company coherence is out of
 * scope here (not implemented, same as CurrencyRate's own currency_id) —
 * only PaymentTransaction's own company_id is exercised.
 *
 * move_id is required and Move's own factory nests a Journal::factory()
 * by default, which itself carries HasStrictCompanyId — every fixture
 * Move is therefore built inside CompanyContext::runForAllCompanies
 * (console-only bypass, valid here since Pest runs in console) BEFORE
 * actingAs() is called, since CompanyContext refuses to open for an
 * already-authenticated actor.
 */
beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('payments');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

function paymentTransactionMemberOf(Company $company): User
{
    return User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id]));
}

function paymentTransactionMoveFor(Company $company): Move
{
    return CompanyContext::runForAllCompanies(
        reason: 'test fixture setup — unrelated Move/Journal chain',
        caller: __FILE__,
        callback: fn () => Move::factory()->create(['company_id' => $company->id]),
    );
}

function paymentTransactionFor(Company $company): PaymentTransaction
{
    return CompanyContext::runForAllCompanies(
        reason: 'test fixture setup',
        caller: __FILE__,
        callback: fn () => PaymentTransaction::factory()->create(['company_id' => $company->id]),
    );
}

// ── read isolation ──────────────────────────────────────────────────────

it('hides a PaymentTransaction from a company the user is not allowed to see', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $transactionA = paymentTransactionFor($companyA);
    $transactionB = paymentTransactionFor($companyB);

    test()->actingAs(paymentTransactionMemberOf($companyA));

    expect(PaymentTransaction::find($transactionA->id))->not->toBeNull();
    expect(PaymentTransaction::find($transactionB->id))->toBeNull();
});

// ── create ───────────────────────────────────────────────────────────────

it('allows creating a PaymentTransaction for the acting user\'s own company', function () {
    $companyA = Company::factory()->create();
    $move = paymentTransactionMoveFor($companyA);

    test()->actingAs(paymentTransactionMemberOf($companyA));

    $transaction = PaymentTransaction::factory()->create([
        'company_id' => $companyA->id,
        'move_id'    => $move->id,
        'journal_id' => null,
    ]);

    expect($transaction->exists)->toBeTrue();
    expect($transaction->company_id)->toBe($companyA->id);
});

it('forbids creating a PaymentTransaction for a company the acting user is not allowed to write to', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $move = paymentTransactionMoveFor($companyA);

    test()->actingAs(paymentTransactionMemberOf($companyA));

    expect(fn () => PaymentTransaction::factory()->create([
        'company_id' => $companyB->id,
        'move_id'    => $move->id,
        'journal_id' => null,
    ]))->toThrow(AuthorizationException::class);

    $this->assertDatabaseMissing('payments_payment_transactions', ['company_id' => $companyB->id]);
});

it('forbids creating a PaymentTransaction when no company_id can be resolved', function () {
    $throwaway = Company::factory()->create();
    $move = paymentTransactionMoveFor($throwaway);

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => null]));
    test()->actingAs($user);

    $before = DB::table('payments_payment_transactions')->count();

    expect(fn () => PaymentTransaction::factory()->create([
        'company_id' => null,
        'move_id'    => $move->id,
        'journal_id' => null,
    ]))->toThrow(AuthorizationException::class);

    expect(DB::table('payments_payment_transactions')->count())->toBe($before);
});

it('defaults company_id from the acting user when none is given', function () {
    $companyA = Company::factory()->create();
    $move = paymentTransactionMoveFor($companyA);

    test()->actingAs(paymentTransactionMemberOf($companyA));

    $transaction = PaymentTransaction::factory()->create([
        'company_id' => null,
        'move_id'    => $move->id,
        'journal_id' => null,
    ]);

    expect($transaction->company_id)->toBe($companyA->id);
});

// ── immutability ─────────────────────────────────────────────────────────

it('forbids changing a PaymentTransaction\'s company_id on update', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $move = paymentTransactionMoveFor($companyA);

    $user = paymentTransactionMemberOf($companyA);
    $user->allowedCompanies()->attach([$companyA->id, $companyB->id]);
    test()->actingAs($user);

    $transaction = PaymentTransaction::factory()->create([
        'company_id' => $companyA->id,
        'move_id'    => $move->id,
        'journal_id' => null,
    ]);

    expect(fn () => $transaction->update(['company_id' => $companyB->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('payments_payment_transactions', ['id' => $transaction->id, 'company_id' => $companyA->id]);
});

it('forbids updating a PaymentTransaction that belongs to another company, even for a harmless field', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $transactionB = paymentTransactionFor($companyB);

    test()->actingAs(paymentTransactionMemberOf($companyA));

    $loaded = PaymentTransaction::withoutGlobalScope(CompanyScope::class)->findOrFail($transactionB->id);

    expect(fn () => $loaded->update(['is_reconciled' => true]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('payments_payment_transactions', ['id' => $transactionB->id, 'company_id' => $companyB->id]);
});

// ── delete ───────────────────────────────────────────────────────────────

it('forbids deleting a PaymentTransaction from a different company than the acting user', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $transactionB = paymentTransactionFor($companyB);

    test()->actingAs(paymentTransactionMemberOf($companyA));

    $loaded = PaymentTransaction::withoutGlobalScope(CompanyScope::class)->findOrFail($transactionB->id);

    expect(fn () => $loaded->delete())->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('payments_payment_transactions', ['id' => $transactionB->id]);
});

it('allows deleting a PaymentTransaction from the acting user\'s own company', function () {
    $companyA = Company::factory()->create();
    $move = paymentTransactionMoveFor($companyA);

    test()->actingAs(paymentTransactionMemberOf($companyA));

    $transaction = PaymentTransaction::factory()->create([
        'company_id' => $companyA->id,
        'move_id'    => $move->id,
        'journal_id' => null,
    ]);
    $transaction->delete();

    $this->assertDatabaseMissing('payments_payment_transactions', ['id' => $transaction->id]);
});

// ── fail closed and system context ─────────────────────────────────────

it('fails closed on PaymentTransaction reads and writes when there is no authenticated user and no system context', function () {
    $companyA = Company::factory()->create();
    $move = paymentTransactionMoveFor($companyA);

    paymentTransactionFor($companyA);

    expect(PaymentTransaction::count())->toBe(0);

    expect(fn () => PaymentTransaction::factory()->create([
        'company_id' => $companyA->id,
        'move_id'    => $move->id,
        'journal_id' => null,
    ]))->toThrow(AuthorizationException::class);
});

it('allows an explicit company system context to create a PaymentTransaction', function () {
    $companyA = Company::factory()->create();

    CompanyContext::runForCompany($companyA->id, reason: 'test fixture setup', caller: __FILE__, callback: function () use ($companyA) {
        $move = Move::factory()->create(['company_id' => $companyA->id, 'journal_id' => null]);

        $transaction = PaymentTransaction::factory()->create([
            'company_id' => $companyA->id,
            'move_id'    => $move->id,
            'journal_id' => null,
        ]);

        expect($transaction->company_id)->toBe($companyA->id);
        expect(PaymentTransaction::count())->toBe(1);
    });
});

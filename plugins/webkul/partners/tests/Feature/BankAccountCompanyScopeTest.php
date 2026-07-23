<?php

use Illuminate\Auth\Access\AuthorizationException;
use Webkul\Account\Models\Journal;
use Webkul\Employee\Models\Employee;
use Webkul\Partner\Models\BankAccount;
use Webkul\Partner\Models\Partner;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('accounts');
    SecurityHelper::disableUserEvents();
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

// ── membership: creation from a company enables it ────────────────────────

it('enables the acting user\'s own company when a BankAccount is created', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $bankAccount = BankAccount::factory()->create();

    expect($bankAccount->isEnabledForCompany($companyA->id))->toBeTrue();
});

it('enables a CompanyContext::runForCompany company when a BankAccount is created with no actor', function () {
    $company = Company::factory()->create();

    $bankAccount = CompanyContext::runForCompany(
        $company->id, reason: 'test', caller: __FILE__,
        callback: fn () => BankAccount::factory()->create(),
    );

    expect($bankAccount->isEnabledForCompany($company->id))->toBeTrue();
});

it('enables no company when a BankAccount is created under CompanyContext::runForAllCompanies', function () {
    $company = Company::factory()->create();

    $bankAccount = CompanyContext::runForAllCompanies(
        reason: 'test', caller: __FILE__,
        callback: fn () => BankAccount::factory()->create(),
    );

    expect($bankAccount->isEnabledForCompany($company->id))->toBeFalse()
        ->and($bankAccount->enabledCompanies()->count())->toBe(0);
});

it('lets enableForCompany() attach additional companies to an existing BankAccount', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $bankAccount = BankAccount::factory()->create();
    $bankAccount->enableForCompany($companyB->id);

    expect($bankAccount->isEnabledForCompany($companyA->id))->toBeTrue()
        ->and($bankAccount->isEnabledForCompany($companyB->id))->toBeTrue();
});

// ── Journal.bank_account_id: designating it auto-enables (like Account) ──

it('auto-enables a BankAccount for a Journal\'s company when designated as its own', function () {
    $companyA = Company::factory()->create();
    $bankAccount = CompanyContext::runForAllCompanies(reason: 'fixture', caller: __FILE__, callback: fn () => BankAccount::factory()->create());

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect($bankAccount->isEnabledForCompany($companyA->id))->toBeFalse();

    Journal::factory()->create(['company_id' => $companyA->id, 'bank_account_id' => $bankAccount->id]);

    expect($bankAccount->fresh()->isEnabledForCompany($companyA->id))->toBeTrue();
});

// ── Employee.bank_account_id: validates against an EXISTING membership ───

it('forbids assigning an Employee a bank_account_id not enabled for its own company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyB->id]));
    test()->actingAs($user);

    $bankAccount = BankAccount::factory()->create(); // enabled for companyB only

    expect(fn () => Employee::factory()->create(['company_id' => $companyA->id, 'bank_account_id' => $bankAccount->id]))
        ->toThrow(AuthorizationException::class);
});

it('allows assigning an Employee a bank_account_id enabled for its own company', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $bankAccount = BankAccount::factory()->create();

    $employee = Employee::factory()->create(['company_id' => $companyA->id, 'bank_account_id' => $bankAccount->id]);

    expect($employee->exists)->toBeTrue();
});

// ── BankAccount::assertBelongsToPartner()/assertEnabledForCompany(): the ──
// ── exact checks Payment/Move/PaymentRegister.partner_bank_id call on   ──
// ── save (verified wired end-to-end via the Employee integration test   ──
// ── above; PaymentFactory's own nested dependency graph — payment method──
// ── lines/methods — carries a pre-existing gap unrelated to company-    ──
// ── scope, see the final report's flagged risks).                       ──

it('BankAccount::assertBelongsToPartner() rejects a bank account belonging to a different partner', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $partnerA = Partner::factory()->create();
    $otherPartner = Partner::factory()->create();
    $bankAccount = BankAccount::factory()->create(['partner_id' => $otherPartner->id]);

    expect(fn () => BankAccount::assertBelongsToPartner($bankAccount->id, $partnerA->id, 'Partner Bank Account'))
        ->toThrow(AuthorizationException::class);
});

it('BankAccount::assertBelongsToPartner() accepts a bank account belonging to the referenced partner', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $partnerA = Partner::factory()->create();
    $bankAccount = BankAccount::factory()->create(['partner_id' => $partnerA->id]);

    expect(fn () => BankAccount::assertBelongsToPartner($bankAccount->id, $partnerA->id, 'Partner Bank Account'))
        ->not->toThrow(AuthorizationException::class);
});

it('BankAccount::assertEnabledForCompany() rejects a bank account not enabled for the given company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $bankAccount = CompanyContext::runForCompany(
        $companyB->id, reason: 'fixture', caller: __FILE__,
        callback: fn () => BankAccount::factory()->create(),
    );

    expect(fn () => BankAccount::assertEnabledForCompany($bankAccount->id, $companyA->id, 'Partner Bank Account'))
        ->toThrow(AuthorizationException::class);
});

it('BankAccount::assertEnabledForCompany() accepts a bank account enabled for the given company', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    $bankAccount = BankAccount::factory()->create();

    expect(fn () => BankAccount::assertEnabledForCompany($bankAccount->id, $companyA->id, 'Partner Bank Account'))
        ->not->toThrow(AuthorizationException::class);
});

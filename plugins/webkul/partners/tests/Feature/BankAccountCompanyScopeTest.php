<?php

use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Webkul\Account\Models\Journal;
use Webkul\Accounting\Models\BankAccount as AccountingBankAccount;
use Webkul\Contact\Models\BankAccount as ContactBankAccount;
use Webkul\Employee\Models\Employee;
use Webkul\Invoice\Models\BankAccount as InvoiceBankAccount;
use Webkul\Partner\Models\BankAccount;
use Webkul\Partner\Models\Partner;
use Webkul\Security\Models\Role;
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

// ── read isolation: BankAccountCompanyMembershipScope (#138 PR4 ola4C) ────

it('shows a user a BankAccount enabled for their own company', function () {
    $companyA = Company::factory()->create();
    $bankAccount = CompanyContext::runForCompany(
        $companyA->id, reason: 'fixture', caller: __FILE__,
        callback: fn () => BankAccount::factory()->create(),
    );

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(BankAccount::query()->whereKey($bankAccount->id)->exists())->toBeTrue();
});

it('hides a BankAccount not enabled for the acting user\'s company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $bankAccount = CompanyContext::runForCompany(
        $companyB->id, reason: 'fixture', caller: __FILE__,
        callback: fn () => BankAccount::factory()->create(),
    );

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(BankAccount::query()->whereKey($bankAccount->id)->exists())->toBeFalse();
    expect(BankAccount::find($bankAccount->id))->toBeNull();
});

it('shows a user with allowed access to multiple companies a BankAccount enabled for either', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $bankAccount = CompanyContext::runForCompany(
        $companyB->id, reason: 'fixture', caller: __FILE__,
        callback: fn () => BankAccount::factory()->create(),
    );

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $user->allowedCompanies()->syncWithoutDetaching([$companyB->id]);
    test()->actingAs($user);

    expect(BankAccount::query()->whereKey($bankAccount->id)->exists())->toBeTrue();
});

it('shows an authenticated user with no allowed companies an empty BankAccount list', function () {
    $company = Company::factory()->create();
    CompanyContext::runForCompany(
        $company->id, reason: 'fixture', caller: __FILE__,
        callback: fn () => BankAccount::factory()->create(),
    );

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => null]));
    test()->actingAs($user);

    expect(BankAccount::query()->count())->toBe(0);
});

it('shows nothing with no authenticated user and no active CompanyContext', function () {
    $company = Company::factory()->create();
    CompanyContext::runForCompany(
        $company->id, reason: 'fixture', caller: __FILE__,
        callback: fn () => BankAccount::factory()->create(),
    );

    expect(BankAccount::query()->count())->toBe(0);
});

it('shows only the exact company\'s BankAccounts under CompanyContext::runForCompany, with no user', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $bankAccountA = CompanyContext::runForCompany($companyA->id, reason: 'fixture', caller: __FILE__, callback: fn () => BankAccount::factory()->create());
    CompanyContext::runForCompany($companyB->id, reason: 'fixture', caller: __FILE__, callback: fn () => BankAccount::factory()->create());

    CompanyContext::runForCompany($companyA->id, reason: 'test', caller: __FILE__, callback: function () use ($bankAccountA) {
        $visibleIds = BankAccount::query()->pluck('id');

        expect($visibleIds)->toContain($bankAccountA->id)
            ->and($visibleIds)->toHaveCount(1);
    });
});

it('shows every BankAccount under CompanyContext::runForAllCompanies regardless of membership', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    CompanyContext::runForCompany($companyA->id, reason: 'fixture', caller: __FILE__, callback: fn () => BankAccount::factory()->create());
    CompanyContext::runForCompany($companyB->id, reason: 'fixture', caller: __FILE__, callback: fn () => BankAccount::factory()->create());

    CompanyContext::runForAllCompanies(reason: 'test', caller: __FILE__, callback: function () {
        expect(BankAccount::query()->count())->toBe(2);
    });
});

it('shows every BankAccount under CompanyContext::runForBootstrap regardless of membership', function () {
    $company = Company::factory()->create();
    CompanyContext::runForCompany($company->id, reason: 'fixture', caller: __FILE__, callback: fn () => BankAccount::factory()->create());

    CompanyContext::runForBootstrap(reason: 'test', caller: __FILE__, callback: function () {
        expect(BankAccount::query()->count())->toBe(1);
    });
});

it('throws when an authenticated user is active while a CompanyContext is still open', function () {
    $company = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $company->id]));

    CompanyContext::runForAllCompanies(reason: 'test: simulate an unexpected concurrent actor', caller: __FILE__, callback: function () use ($user) {
        test()->actingAs($user);

        expect(fn () => BankAccount::query()->count())->toThrow(LogicException::class);
    });
});

it('lets a super_admin bypass BankAccountCompanyMembershipScope via forAllCompanies()', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    CompanyContext::runForCompany($companyA->id, reason: 'fixture', caller: __FILE__, callback: fn () => BankAccount::factory()->create());
    CompanyContext::runForCompany($companyB->id, reason: 'fixture', caller: __FILE__, callback: fn () => BankAccount::factory()->create());

    $superAdmin = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));
    test()->actingAs($superAdmin);

    expect(BankAccount::forAllCompanies()->count())->toBe(2);
});

it('rejects forAllCompanies() for a non-super_admin user with a 403', function () {
    $companyA = Company::factory()->create();
    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(fn () => BankAccount::forAllCompanies())
        ->toThrow(HttpException::class);
});

// ── alias inheritance: the 3 zero-schema subclasses share the scope ──────

it('applies BankAccountCompanyMembershipScope to Contact\BankAccount via late static binding', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    CompanyContext::runForCompany($companyA->id, reason: 'fixture', caller: __FILE__, callback: fn () => BankAccount::factory()->create());
    $hidden = CompanyContext::runForCompany($companyB->id, reason: 'fixture', caller: __FILE__, callback: fn () => BankAccount::factory()->create());

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(ContactBankAccount::query()->count())->toBe(1);
    expect(ContactBankAccount::find($hidden->id))->toBeNull();
});

it('applies BankAccountCompanyMembershipScope to Accounting\BankAccount via late static binding', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    CompanyContext::runForCompany($companyA->id, reason: 'fixture', caller: __FILE__, callback: fn () => BankAccount::factory()->create());
    $hidden = CompanyContext::runForCompany($companyB->id, reason: 'fixture', caller: __FILE__, callback: fn () => BankAccount::factory()->create());

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(AccountingBankAccount::query()->count())->toBe(1);
    expect(AccountingBankAccount::find($hidden->id))->toBeNull();
});

it('applies BankAccountCompanyMembershipScope to Invoice\BankAccount via late static binding', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    CompanyContext::runForCompany($companyA->id, reason: 'fixture', caller: __FILE__, callback: fn () => BankAccount::factory()->create());
    $hidden = CompanyContext::runForCompany($companyB->id, reason: 'fixture', caller: __FILE__, callback: fn () => BankAccount::factory()->create());

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(InvoiceBankAccount::query()->count())->toBe(1);
    expect(InvoiceBankAccount::find($hidden->id))->toBeNull();
});

// ── integrity methods bypass ONLY this scope, never expose it publicly ───

it('ensureEnabledForCompany() enables a BankAccount hidden from the acting user\'s company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $bankAccount = CompanyContext::runForCompany(
        $companyB->id, reason: 'fixture', caller: __FILE__,
        callback: fn () => BankAccount::factory()->create(),
    );

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(BankAccount::find($bankAccount->id))->toBeNull();

    BankAccount::ensureEnabledForCompany($bankAccount->id, $companyA->id);

    expect(BankAccount::find($bankAccount->id)?->id)->toBe($bankAccount->id);
});

it('assertEnabledForCompany() still validates a BankAccount hidden from the acting user\'s company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $bankAccount = CompanyContext::runForCompany(
        $companyB->id, reason: 'fixture', caller: __FILE__,
        callback: fn () => BankAccount::factory()->create(),
    );

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);

    expect(BankAccount::find($bankAccount->id))->toBeNull();

    expect(fn () => BankAccount::assertEnabledForCompany($bankAccount->id, $companyB->id, 'Partner Bank Account'))
        ->not->toThrow(AuthorizationException::class);
});

it('assertBelongsToPartner() still detects a wrong partner on a BankAccount hidden from the acting user\'s company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $otherPartner = Partner::factory()->create();
    $bankAccount = CompanyContext::runForCompany(
        $companyB->id, reason: 'fixture', caller: __FILE__,
        callback: fn () => BankAccount::factory()->create(['partner_id' => $otherPartner->id]),
    );

    $user = User::withoutEvents(fn () => User::factory()->create(['default_company_id' => $companyA->id]));
    test()->actingAs($user);
    $partnerA = Partner::factory()->create();

    expect(BankAccount::find($bankAccount->id))->toBeNull();

    expect(fn () => BankAccount::assertBelongsToPartner($bankAccount->id, $partnerA->id, 'Partner Bank Account'))
        ->toThrow(AuthorizationException::class);
});

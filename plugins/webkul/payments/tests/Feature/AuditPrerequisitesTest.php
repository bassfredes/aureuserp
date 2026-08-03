<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Webkul\Account\Models\Move;
use Webkul\Payment\Models\Payment;
use Webkul\Payment\Models\PaymentToken;
use Webkul\Payment\Models\PaymentTransaction;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;
use Webkul\Support\Services\CompanyContext;

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

/**
 * `payments` has no entry in TestBootstrapHelper::ensurePluginInstalled()'s
 * table map and no prior test suite of its own — installing it inline here
 * rather than extending that shared helper for a single, narrowly-scoped
 * regression test (Intelligent-Integration-Suite#138 audit, PR 0).
 */
beforeEach(function () {
    TestBootstrapHelper::ensureERPInstalled();

    if (! Schema::hasTable('payments_payment_tokens')) {
        Artisan::call('payments:install', ['--no-interaction' => true]);
    }
});

/**
 * PaymentToken/PaymentTransaction declared no $table property, so
 * Eloquent's naming convention resolved to `payment_tokens`/
 * `payment_transactions` instead of the real migrated (prefixed) tables
 * `payments_payment_tokens`/`payments_payment_transactions` (#138 audit,
 * PR 0 prerequisite #4/#5).
 *
 * Both models now carry HasCompanyScope + HasStrictCompanyId (#138 PR4
 * A4J), so a bare save() with no resolvable company_id fails closed —
 * these rows are built inside an explicit company system context instead
 * of via direct property assignment.
 */
it('resolves PaymentToken to its real migrated table and can be queried', function () {
    expect((new PaymentToken)->getTable())->toBe('payments_payment_tokens');

    $company = Company::factory()->create();

    $token = CompanyContext::runForCompany($company->id, reason: 'test fixture setup', caller: __FILE__, callback: function () use ($company) {
        $token = new PaymentToken;
        $token->company_id = $company->id;
        $token->save();

        return $token;
    });

    // Read happens after the fixture context has closed, and CompanyScope
    // fails closed with no user and no active context — bypass the scope
    // here since this test is about table resolution, not visibility.
    expect(PaymentToken::withoutGlobalScope(CompanyScope::class)->whereKey($token->id)->exists())->toBeTrue();
});

it('resolves PaymentTransaction to its real migrated table and can be queried', function () {
    expect((new PaymentTransaction)->getTable())->toBe('payments_payment_transactions');

    $company = Company::factory()->create();

    $transaction = CompanyContext::runForAllCompanies(reason: 'test fixture setup', caller: __FILE__, callback: function () use ($company) {
        $transaction = new PaymentTransaction;
        $transaction->company_id = $company->id;
        $transaction->move_id = Move::factory()->create(['company_id' => $company->id])->id;
        $transaction->save();

        return $transaction;
    });

    expect(PaymentTransaction::withoutGlobalScope(CompanyScope::class)->whereKey($transaction->id)->exists())->toBeTrue();
});

/**
 * Webkul\Payment\Models\Payment had no backing migration anywhere in the
 * repo (no `create_payments_payments_table`, no seeder logic, no
 * Resource/Policy) — genuinely orphaned code, removed in this PR rather
 * than given a `$table` fix (#138 audit, PR 0, "modelo huérfano" case 1 of 2).
 */
it('no longer ships the orphaned Payment model', function () {
    expect(class_exists(Payment::class))->toBeFalse();
});

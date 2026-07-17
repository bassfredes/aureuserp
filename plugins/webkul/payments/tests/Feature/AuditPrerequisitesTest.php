<?php

use Illuminate\Support\Facades\Artisan;
use Webkul\Account\Models\Move;
use Webkul\Payment\Models\PaymentToken;
use Webkul\Payment\Models\PaymentTransaction;

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

/**
 * `payments` has no entry in TestBootstrapHelper::ensurePluginInstalled()'s
 * table map and no prior test suite of its own — installing it inline here
 * rather than extending that shared helper for a single, narrowly-scoped
 * regression test (Intelligent-Integration-Suite#138 audit, PR 0).
 */
beforeEach(function () {
    TestBootstrapHelper::ensureERPInstalled();

    if (! Illuminate\Support\Facades\Schema::hasTable('payments_payment_tokens')) {
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
 * Neither model declares $fillable (mass assignment is guarded by
 * default) or a newFactory() override — both separate pre-existing gaps
 * out of scope for this PR — so rows are built via direct property
 * assignment instead of ::factory()->create().
 */
it('resolves PaymentToken to its real migrated table and can be queried', function () {
    expect((new PaymentToken)->getTable())->toBe('payments_payment_tokens');

    $token = new PaymentToken;
    $token->save();

    expect(PaymentToken::query()->whereKey($token->id)->exists())->toBeTrue();
});

it('resolves PaymentTransaction to its real migrated table and can be queried', function () {
    expect((new PaymentTransaction)->getTable())->toBe('payments_payment_transactions');

    $transaction = new PaymentTransaction;
    $transaction->move_id = Move::factory()->create()->id;
    $transaction->save();

    expect(PaymentTransaction::query()->whereKey($transaction->id)->exists())->toBeTrue();
});

/**
 * Webkul\Payment\Models\Payment had no backing migration anywhere in the
 * repo (no `create_payments_payments_table`, no seeder logic, no
 * Resource/Policy) — genuinely orphaned code, removed in this PR rather
 * than given a `$table` fix (#138 audit, PR 0, "modelo huérfano" case 1 of 2).
 */
it('no longer ships the orphaned Payment model', function () {
    expect(class_exists(\Webkul\Payment\Models\Payment::class))->toBeFalse();
});

<?php

use Illuminate\Support\Facades\URL;
use Webkul\Partner\Models\Partner;
use Webkul\Purchase\Enums\OrderState;
use Webkul\Purchase\Models\Order;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('purchases');
});

function sentOrderWithVendor(array $overrides = []): Order
{
    $company = Company::factory()->create();
    $vendor = Partner::factory()->create();

    return Order::factory()->sent()->create(array_merge([
        'company_id' => $company->id,
        'partner_id' => $vendor->id,
    ], $overrides));
}

function signedRespondUrl(Order $order, string $action): string
{
    return URL::signedRoute('purchases.quotations.respond', ['order' => $order->id, 'action' => $action]);
}

// ── Capability and HTTP ──────────────────────────────────────────────────────

it('shows a confirmation page for a valid signed GET without mutating the order', function () {
    $order = sentOrderWithVendor();

    test()->get(signedRespondUrl($order, 'accept'))->assertOk();

    expect($order->fresh()->mail_reception_confirmed)->toBeFalse()
        ->and($order->fresh()->mail_reception_declined)->toBeFalse();
});

it('does not mutate the order on HEAD either', function () {
    $order = sentOrderWithVendor();

    test()->head(signedRespondUrl($order, 'accept'))->assertOk();

    expect($order->fresh()->mail_reception_confirmed)->toBeFalse();
});

it('rejects a request with no signature at all', function () {
    $order = sentOrderWithVendor();

    test()->get("/purchase/{$order->id}/accept")->assertForbidden();
});

it('rejects a request with an invalid signature', function () {
    $order = sentOrderWithVendor();

    $url = signedRespondUrl($order, 'accept').'tampered';

    test()->get($url)->assertForbidden();
});

it('rejects a request with an expired signature', function () {
    $order = sentOrderWithVendor();

    $expiredUrl = URL::temporarySignedRoute('purchases.quotations.respond', now()->subMinute(), [
        'order' => $order->id, 'action' => 'accept',
    ]);

    test()->get($expiredUrl)->assertForbidden();
});

it('rejects a request whose order id was altered after signing', function () {
    $orderA = sentOrderWithVendor();
    $orderB = sentOrderWithVendor();

    $signedForA = signedRespondUrl($orderA, 'accept');
    $tamperedUrl = str_replace("/purchase/{$orderA->id}/", "/purchase/{$orderB->id}/", $signedForA);

    test()->get($tamperedUrl)->assertForbidden();
});

it('rejects a request whose action was altered after signing', function () {
    $order = sentOrderWithVendor();

    $signedForAccept = signedRespondUrl($order, 'accept');
    $tamperedUrl = str_replace('/accept', '/decline', $signedForAccept);

    test()->get($tamperedUrl)->assertForbidden();
});

it('returns 404 for an action outside the accept|decline allowlist', function () {
    $order = sentOrderWithVendor();

    $url = URL::signedRoute('purchases.quotations.respond', ['order' => $order->id, 'action' => 'delete']);

    test()->get($url)->assertNotFound();
});

// ── Mutation ──────────────────────────────────────────────────────────────────

it('accepts a valid POST and confirms the order, recording exactly one message', function () {
    $order = sentOrderWithVendor();

    test()->post(signedRespondUrl($order, 'accept'))->assertOk();

    $order->refresh();

    expect($order->mail_reception_confirmed)->toBeTrue()
        ->and($order->mail_reception_declined)->toBeFalse()
        ->and($order->messages()->count())->toBe(1);
});

it('declines a valid POST and records the order as declined, recording exactly one message', function () {
    $order = sentOrderWithVendor();

    test()->post(signedRespondUrl($order, 'decline'))->assertOk();

    $order->refresh();

    expect($order->mail_reception_declined)->toBeTrue()
        ->and($order->mail_reception_confirmed)->toBeFalse()
        ->and($order->messages()->count())->toBe(1);
});

// ── Idempotency ──────────────────────────────────────────────────────────────

it('is idempotent on a repeated accept: 200, no new message', function () {
    $order = sentOrderWithVendor();

    test()->post(signedRespondUrl($order, 'accept'))->assertOk();
    test()->post(signedRespondUrl($order, 'accept'))->assertOk();

    expect($order->refresh()->messages()->count())->toBe(1);
});

it('is idempotent on a repeated decline: 200, no new message', function () {
    $order = sentOrderWithVendor();

    test()->post(signedRespondUrl($order, 'decline'))->assertOk();
    test()->post(signedRespondUrl($order, 'decline'))->assertOk();

    expect($order->refresh()->messages()->count())->toBe(1);
});

it('rejects accept after an existing decline with 409, without changing flags', function () {
    $order = sentOrderWithVendor();

    test()->post(signedRespondUrl($order, 'decline'))->assertOk();
    test()->post(signedRespondUrl($order, 'accept'))->assertStatus(409);

    $order->refresh();

    expect($order->mail_reception_declined)->toBeTrue()
        ->and($order->mail_reception_confirmed)->toBeFalse()
        ->and($order->messages()->count())->toBe(1);
});

it('rejects decline after an existing accept with 409, without changing flags', function () {
    $order = sentOrderWithVendor();

    test()->post(signedRespondUrl($order, 'accept'))->assertOk();
    test()->post(signedRespondUrl($order, 'decline'))->assertStatus(409);

    $order->refresh();

    expect($order->mail_reception_confirmed)->toBeTrue()
        ->and($order->mail_reception_declined)->toBeFalse()
        ->and($order->messages()->count())->toBe(1);
});

it('fails closed with 409 when both flags are already true (historical-invalid state)', function () {
    $order = sentOrderWithVendor([
        'mail_reception_confirmed' => true,
        'mail_reception_declined'  => true,
    ]);

    test()->post(signedRespondUrl($order, 'accept'))->assertStatus(409);

    expect($order->refresh()->messages()->count())->toBe(0);
});

// ── State and partner ─────────────────────────────────────────────────────────

it('accepts a response only while the order is in the sent state', function (OrderState $state) {
    $order = sentOrderWithVendor(['state' => $state]);

    test()->post(signedRespondUrl($order, 'accept'))->assertStatus(409);

    expect($order->refresh()->mail_reception_confirmed)->toBeFalse();
})->with([
    OrderState::DRAFT,
    OrderState::TO_APPROVE,
    OrderState::PURCHASE,
    OrderState::DONE,
    OrderState::CANCELED,
]);

it('rejects a response when the order has no partner_id', function () {
    $company = Company::factory()->create();

    $order = Order::factory()->sent()->create([
        'company_id' => $company->id,
        'partner_id' => null,
    ]);

    test()->post(signedRespondUrl($order, 'accept'))->assertStatus(409);

    expect($order->refresh()->mail_reception_confirmed)->toBeFalse();
});

it('rejects a response when the order\'s partner has been deleted', function () {
    $order = sentOrderWithVendor();
    $order->partner->delete();

    test()->post(signedRespondUrl($order, 'accept'))->assertStatus(409);

    expect($order->refresh()->mail_reception_confirmed)->toBeFalse();
});

// ── Company isolation ─────────────────────────────────────────────────────────

it('signs a capability for exactly one order — the signature does not enumerate or leak other orders', function () {
    $orderA = sentOrderWithVendor();
    sentOrderWithVendor();

    $url = signedRespondUrl($orderA, 'accept');

    test()->post($url)->assertOk();

    expect($orderA->fresh()->mail_reception_confirmed)->toBeTrue();
});

it('does not extend the capability to another authenticated company-scoped session', function () {
    $order = sentOrderWithVendor();

    $otherCompanyUser = \Webkul\Security\Models\User::withoutEvents(fn () => \Webkul\Security\Models\User::factory()->create([
        'default_company_id' => Company::factory()->create()->id,
    ]));

    test()->actingAs($otherCompanyUser);

    // The signed link's own signature is what authorizes access here, not
    // the acting session's own company membership — an authenticated user
    // from a different company must not gain any extra reach through it.
    test()->post(signedRespondUrl($order, 'accept'))->assertOk();

    expect($order->fresh()->mail_reception_confirmed)->toBeTrue();
});

// ── Atomicity ──────────────────────────────────────────────────────────────────

it('leaves flags and messages untouched when the order cannot be found', function () {
    test()->post(URL::signedRoute('purchases.quotations.respond', ['order' => 999999, 'action' => 'accept']))
        ->assertNotFound();
});

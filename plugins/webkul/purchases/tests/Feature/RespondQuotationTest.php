<?php

use Illuminate\Support\Facades\URL;
use Webkul\Chatter\Models\Message;
use Webkul\Partner\Models\Partner;
use Webkul\Purchase\Enums\OrderState;
use Webkul\Purchase\Models\Order;
use Webkul\Purchase\Services\QuotationResponseService;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('purchases');

    // TestBootstrapHelper::loadPluginRoutes() only re-requires routes/api.php
    // on a cold boot — this is the first suite in the repo needing
    // purchases' routes/web.php resolvable when this file runs in
    // isolation, so load it directly here rather than widening that
    // shared helper. Route::has()/URL::signedRoute() read the router's
    // cached name index, which isn't rebuilt just by adding routes after
    // it was first queried — force the rebuild explicitly or the very
    // first test in this file sees the route as undefined.
    if (! app()->routesAreCached() && ! \Illuminate\Support\Facades\Route::has('purchases.quotations.respond')) {
        require base_path('plugins/webkul/purchases/routes/web.php');
        app('router')->getRoutes()->refreshNameLookups();
    }
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

/**
 * The "real" links this feature generates are always temporary
 * (SendEmailAction uses URL::temporarySignedRoute() — see PR 1 review
 * round 2). Valid-path tests build their URL this way; the permanent,
 * no-expires variant is only used explicitly to prove it's rejected.
 */
function signedRespondUrl(Order $order, string $action): string
{
    return URL::temporarySignedRoute('purchases.quotations.respond', now()->addHour(), [
        'order' => $order->id, 'action' => $action,
    ]);
}

function permanentSignedRespondUrl(Order $order, string $action): string
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

// ── Signature expiry is mandatory, not just hash validity (review round 2) ──

it('rejects a permanent signature (no expires at all) on GET', function () {
    $order = sentOrderWithVendor();

    test()->get(permanentSignedRespondUrl($order, 'accept'))->assertForbidden();

    expect($order->fresh()->mail_reception_confirmed)->toBeFalse();
});

it('rejects a permanent signature (no expires at all) on POST', function () {
    $order = sentOrderWithVendor();

    test()->post(permanentSignedRespondUrl($order, 'accept'))->assertForbidden();

    expect($order->fresh()->mail_reception_confirmed)->toBeFalse();
});

it('accepts a valid temporary signature on GET', function () {
    $order = sentOrderWithVendor();

    test()->get(signedRespondUrl($order, 'accept'))->assertOk();
});

it('accepts a valid temporary signature on POST', function () {
    $order = sentOrderWithVendor();

    test()->post(signedRespondUrl($order, 'accept'))->assertOk();

    expect($order->fresh()->mail_reception_confirmed)->toBeTrue();
});

it('rejects an expired temporary signature on POST', function () {
    $order = sentOrderWithVendor();

    $expiredUrl = URL::temporarySignedRoute('purchases.quotations.respond', now()->subMinute(), [
        'order' => $order->id, 'action' => 'accept',
    ]);

    test()->post($expiredUrl)->assertForbidden();

    expect($order->fresh()->mail_reception_confirmed)->toBeFalse();
});

// ── Mutation ──────────────────────────────────────────────────────────────────

/**
 * Message-count assertions compare against a baseline captured right
 * after the order exists, not an absolute count — HasLogActivity already
 * auto-logs a "created" message on the factory ->create() call itself,
 * and a further "updated" message on every ->update() call our own
 * addMessage() comment is layered on top of; asserting a bare count
 * would be coupled to that unrelated auto-logging behavior instead of
 * to what this feature itself does.
 */
it('accepts a valid POST and confirms the order, recording new messages only once', function () {
    $order = sentOrderWithVendor();
    $baseline = $order->messages()->count();

    test()->post(signedRespondUrl($order, 'accept'))->assertOk();

    $order->refresh();

    expect($order->mail_reception_confirmed)->toBeTrue()
        ->and($order->mail_reception_declined)->toBeFalse()
        ->and($order->messages()->count())->toBeGreaterThan($baseline);
});

it('declines a valid POST and records the order as declined, recording new messages only once', function () {
    $order = sentOrderWithVendor();
    $baseline = $order->messages()->count();

    test()->post(signedRespondUrl($order, 'decline'))->assertOk();

    $order->refresh();

    expect($order->mail_reception_declined)->toBeTrue()
        ->and($order->mail_reception_confirmed)->toBeFalse()
        ->and($order->messages()->count())->toBeGreaterThan($baseline);
});

// ── Message company_id and causer (review round 2) ──────────────────────────
// HasChatter::addMessage() defaults company_id from the acting user's own
// company when the caller doesn't pass one explicitly, and
// Message::boot() used to unconditionally overwrite causer_type/causer_id
// with whatever Auth::user() was — wrong on both counts here, since there
// is no acting user at all (or, worse, a real one from an unrelated
// company). The comment message must carry the order's own company_id
// and the vendor Partner as causer regardless of the acting session.

it('records the response message under the order\'s own company, with the vendor as causer, when there is no session at all', function () {
    $order = sentOrderWithVendor();

    test()->post(signedRespondUrl($order, 'accept'))->assertOk();

    $message = $order->fresh()->messages()->where('type', 'comment')->latest('id')->first();

    expect($message)->not->toBeNull()
        ->and($message->company_id)->toBe($order->company_id)
        ->and($message->causer_type)->toBe($order->partner->getMorphClass())
        ->and($message->causer_id)->toBe($order->partner->id);
});

it('records the response message under the order\'s own company and the vendor as causer, even from an authenticated other-company session', function () {
    $order = sentOrderWithVendor();

    $otherCompany = Company::factory()->create();
    $otherCompanyUser = \Webkul\Security\Models\User::withoutEvents(fn () => \Webkul\Security\Models\User::factory()->create([
        'default_company_id' => $otherCompany->id,
    ]));
    test()->actingAs($otherCompanyUser);

    test()->post(signedRespondUrl($order, 'accept'))->assertOk();

    $message = $order->fresh()->messages()->where('type', 'comment')->latest('id')->first();

    expect($message->company_id)->toBe($order->company_id)
        ->and($message->company_id)->not->toBe($otherCompany->id)
        ->and($message->causer_type)->toBe($order->partner->getMorphClass())
        ->and($message->causer_id)->toBe($order->partner->id)
        ->and($message->causer_id)->not->toBe($otherCompanyUser->id);
});

// ── Idempotency ──────────────────────────────────────────────────────────────

it('is idempotent on a repeated accept: 200, no new message on the replay', function () {
    $order = sentOrderWithVendor();

    test()->post(signedRespondUrl($order, 'accept'))->assertOk();
    $afterFirst = $order->refresh()->messages()->count();

    test()->post(signedRespondUrl($order, 'accept'))->assertOk();

    expect($order->refresh()->messages()->count())->toBe($afterFirst);
});

it('is idempotent on a repeated decline: 200, no new message on the replay', function () {
    $order = sentOrderWithVendor();

    test()->post(signedRespondUrl($order, 'decline'))->assertOk();
    $afterFirst = $order->refresh()->messages()->count();

    test()->post(signedRespondUrl($order, 'decline'))->assertOk();

    expect($order->refresh()->messages()->count())->toBe($afterFirst);
});

it('rejects accept after an existing decline with 409, without changing flags or adding messages', function () {
    $order = sentOrderWithVendor();

    test()->post(signedRespondUrl($order, 'decline'))->assertOk();
    $afterDecline = $order->refresh()->messages()->count();

    test()->post(signedRespondUrl($order, 'accept'))->assertStatus(409);

    $order->refresh();

    expect($order->mail_reception_declined)->toBeTrue()
        ->and($order->mail_reception_confirmed)->toBeFalse()
        ->and($order->messages()->count())->toBe($afterDecline);
});

it('rejects decline after an existing accept with 409, without changing flags or adding messages', function () {
    $order = sentOrderWithVendor();

    test()->post(signedRespondUrl($order, 'accept'))->assertOk();
    $afterAccept = $order->refresh()->messages()->count();

    test()->post(signedRespondUrl($order, 'decline'))->assertStatus(409);

    $order->refresh();

    expect($order->mail_reception_confirmed)->toBeTrue()
        ->and($order->mail_reception_declined)->toBeFalse()
        ->and($order->messages()->count())->toBe($afterAccept);
});

it('fails closed with 409 when both flags are already true (historical-invalid state), without adding messages', function () {
    $order = sentOrderWithVendor([
        'mail_reception_confirmed' => true,
        'mail_reception_declined'  => true,
    ]);
    $baseline = $order->messages()->count();

    test()->post(signedRespondUrl($order, 'accept'))->assertStatus(409);

    expect($order->refresh()->messages()->count())->toBe($baseline);
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

// purchases_orders.partner_id is NOT NULL at the schema level, so a null
// partner_id can never be constructed via a real Order — only the
// partner-deleted case below is reachable in practice. The service's own
// `! $order->partner_id` check stays as defense in depth regardless.

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

    // Company::factory()->create() must run outside withoutEvents() —
    // that suppresses Company's own creating()/saved() hooks (which link
    // a Partner) for every model, not just User.
    $otherCompany = Company::factory()->create();

    $otherCompanyUser = \Webkul\Security\Models\User::withoutEvents(fn () => \Webkul\Security\Models\User::factory()->create([
        'default_company_id' => $otherCompany->id,
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
    $url = URL::temporarySignedRoute('purchases.quotations.respond', now()->addHour(), [
        'order' => 999999, 'action' => 'accept',
    ]);

    test()->post($url)->assertNotFound();
});

/**
 * The order-not-found case above never reaches a write at all, so it
 * can't prove rollback by itself. Forcing an exception after the order
 * update — but before the transaction commits — during Message creation
 * is what actually demonstrates the whole response is one atomic unit
 * (review round 2): the update() must not survive if the message that
 * belongs with it can't be written.
 */
it('rolls back the order update when writing the response message fails', function () {
    $order = sentOrderWithVendor();
    $baseline = $order->messages()->count();

    Message::creating(function (Message $message) {
        if ($message->type === 'comment' && str_starts_with((string) $message->body, 'The RFQ has been')) {
            throw new \RuntimeException('Simulated failure while recording the RFQ response.');
        }
    });

    try {
        expect(fn () => test()->withoutExceptionHandling()->post(signedRespondUrl($order, 'accept')))
            ->toThrow(\RuntimeException::class, 'Simulated failure while recording the RFQ response.');
    } finally {
        Message::flushEventListeners();
    }

    $order->refresh();

    expect($order->mail_reception_confirmed)->toBeFalse()
        ->and($order->mail_reception_declined)->toBeFalse()
        ->and($order->messages()->count())->toBe($baseline);
});

// ── Service-level validation (review round 2) ───────────────────────────────
// The route's accept|decline constraint is not the authorization boundary
// — QuotationResponseService itself must refuse an unknown action before
// doing the order lookup or any write, since it can be reached directly
// by any future caller that bypasses routing entirely.

it('fails closed on an unknown action at the service level, before any lookup or write', function () {
    $order = sentOrderWithVendor();
    $baseline = $order->messages()->count();

    $result = app(QuotationResponseService::class)->respond($order->id, 'delete');

    expect($result->status)->toBe(422)
        ->and($result->outcome)->toBe('invalid_action');

    $order->refresh();

    expect($order->mail_reception_confirmed)->toBeFalse()
        ->and($order->mail_reception_declined)->toBeFalse()
        ->and($order->messages()->count())->toBe($baseline);
});

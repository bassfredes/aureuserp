<?php

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Webkul\Partner\Models\Partner;
use Webkul\Purchase\Filament\Customer\Clusters\Account\Resources\PurchaseOrderResource\Pages\ListPurchaseOrders;
use Webkul\Purchase\Models\Order;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;

require_once __DIR__.'/../../../../support/tests/Helpers/SecurityHelper.php';
require_once __DIR__.'/../../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('purchases');
    SecurityHelper::disableUserEvents();

    Filament::setCurrentPanel(Filament::getPanel('customer'));
});

afterEach(fn () => SecurityHelper::restoreUserEvents());

it('does not 500 when a Partner (no company membership contract) is authenticated under the customer guard', function () {
    $partner = Partner::factory()->create();

    test()->actingAs($partner, 'customer');
    Auth::shouldUse('customer');

    expect(fn () => Livewire::test(ListPurchaseOrders::class))->not->toThrow(\Throwable::class);
});

it('fails closed for a generic company-scoped query under the customer guard', function () {
    $company = Company::factory()->create();
    Order::factory()->create(['company_id' => $company->id]);

    $partner = Partner::factory()->create();

    test()->actingAs($partner, 'customer');
    Auth::shouldUse('customer');

    expect(CompanyScope::allowedCompanyIds($partner))->toBeEmpty();
    expect(Order::query()->count())->toBe(0);
});

it('lets a vendor see their own orders across every company that ordered from them', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $vendor = Partner::factory()->create();

    $orderA = Order::factory()->create(['company_id' => $companyA->id, 'partner_id' => $vendor->id]);
    $orderB = Order::factory()->create(['company_id' => $companyB->id, 'partner_id' => $vendor->id]);

    test()->actingAs($vendor, 'customer');
    Auth::shouldUse('customer');

    $ids = Webkul\Purchase\Filament\Customer\Clusters\Account\Resources\PurchaseOrderResource::getEloquentQuery()
        ->pluck('id')
        ->all();

    expect($ids)->toEqualCanonicalizing([$orderA->id, $orderB->id]);
});

it('does not let a vendor see another vendor\'s orders', function () {
    $company = Company::factory()->create();

    $vendorX = Partner::factory()->create();
    $vendorY = Partner::factory()->create();

    $orderX = Order::factory()->create(['company_id' => $company->id, 'partner_id' => $vendorX->id]);
    $orderY = Order::factory()->create(['company_id' => $company->id, 'partner_id' => $vendorY->id]);

    test()->actingAs($vendorX, 'customer');
    Auth::shouldUse('customer');

    $ids = Webkul\Purchase\Filament\Customer\Clusters\Account\Resources\PurchaseOrderResource::getEloquentQuery()
        ->pluck('id')
        ->all();

    expect($ids)->toContain($orderX->id)
        ->and($ids)->not->toContain($orderY->id);
});

it('preserves internal user A/B company isolation alongside the customer-guard bypass', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $userA = User::withoutEvents(fn () => User::factory()->create([
        'default_company_id' => $companyA->id,
    ]));

    $orderA = Order::factory()->create(['company_id' => $companyA->id]);
    $orderB = Order::factory()->create(['company_id' => $companyB->id]);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    test()->actingAs($userA, 'web');
    Auth::shouldUse('web');

    expect(Order::find($orderA->id))->not->toBeNull();
    expect(Order::find($orderB->id))->toBeNull();
});

/**
 * As of Intelligent-Integration-Suite#138 PR 1, the signed GET only
 * renders a confirmation page — it no longer mutates the order. The
 * actual accept/decline write happens on POST to the same signed URL.
 * Both still work with no authenticated actor, bypassing CompanyScope
 * only for the exact signed order id (see QuotationResponseService).
 */
it('lets the signed RespondQuotation GET render without mutating, and POST update mail_reception_confirmed, with no authenticated actor', function () {
    $company = Company::factory()->create();
    $vendor = Partner::factory()->create();

    $order = Order::factory()->create([
        'company_id' => $company->id,
        'partner_id' => $vendor->id,
        'state'      => \Webkul\Purchase\Enums\OrderState::SENT,
    ]);

    Auth::logout();

    // Real links are always temporary now (review round 2, #138 PR 1) —
    // a permanent signedRoute() is rejected outright before reaching this
    // flow at all.
    $signedUrl = URL::temporarySignedRoute('purchases.quotations.respond', now()->addHour(), [
        'order'  => $order->id,
        'action' => 'accept',
    ]);

    test()->get($signedUrl)->assertOk();

    expect($order->fresh()->mail_reception_confirmed)->toBeFalse();

    test()->post($signedUrl)->assertOk();

    expect($order->fresh()->mail_reception_confirmed)->toBeTrue();
});

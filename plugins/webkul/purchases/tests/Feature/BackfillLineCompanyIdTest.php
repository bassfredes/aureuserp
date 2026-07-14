<?php

use Webkul\Purchase\Models\Order;
use Webkul\Purchase\Models\OrderLine;
use Webkul\Purchase\Models\Requisition;
use Webkul\Purchase\Models\RequisitionLine;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(fn () => TestBootstrapHelper::ensurePluginInstalled('purchases'));

function runPurchasesLineCompanyIdBackfill(): void
{
    (require __DIR__.'/../../database/migrations/2026_07_14_120000_backfill_purchases_line_company_id.php')->up();
}

it('backfills purchases_order_lines.company_id from its parent order, both null and mismatched', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $order = Order::factory()->create(['company_id' => $companyA->id]);

    $nullLine = OrderLine::factory()->create(['order_id' => $order->id, 'company_id' => null]);
    $mismatchLine = OrderLine::factory()->create(['order_id' => $order->id, 'company_id' => $companyB->id]);
    $correctLine = OrderLine::factory()->create(['order_id' => $order->id, 'company_id' => $companyA->id]);

    runPurchasesLineCompanyIdBackfill();

    expect(OrderLine::find($nullLine->id)->company_id)->toBe($companyA->id)
        ->and(OrderLine::find($mismatchLine->id)->company_id)->toBe($companyA->id)
        ->and(OrderLine::find($correctLine->id)->company_id)->toBe($companyA->id);
});

it('backfills purchases_requisition_lines.company_id from its parent agreement, both null and mismatched', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $agreement = Requisition::factory()->create(['company_id' => $companyA->id]);

    $nullLine = RequisitionLine::factory()->create(['requisition_id' => $agreement->id, 'company_id' => null]);
    $mismatchLine = RequisitionLine::factory()->create(['requisition_id' => $agreement->id, 'company_id' => $companyB->id]);

    runPurchasesLineCompanyIdBackfill();

    expect(RequisitionLine::find($nullLine->id)->company_id)->toBe($companyA->id)
        ->and(RequisitionLine::find($mismatchLine->id)->company_id)->toBe($companyA->id);
});

it('is idempotent: running the backfill twice does not change already-correct rows', function () {
    $companyA = Company::factory()->create();
    $order = Order::factory()->create(['company_id' => $companyA->id]);
    $line = OrderLine::factory()->create(['order_id' => $order->id, 'company_id' => null]);

    runPurchasesLineCompanyIdBackfill();
    $afterFirstRun = OrderLine::find($line->id)->company_id;

    runPurchasesLineCompanyIdBackfill();
    $afterSecondRun = OrderLine::find($line->id)->company_id;

    expect($afterFirstRun)->toBe($companyA->id)
        ->and($afterSecondRun)->toBe($companyA->id);
});

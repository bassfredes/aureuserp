<?php

use Illuminate\Support\Facades\DB;
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

/**
 * OrderLineFactory/RequisitionLineFactory now default company_id from their
 * parent (D4 factory fix), so `->create(['company_id' => null])` no longer
 * produces a null row — the factory corrects it before insert. Force the
 * column back to null via a raw update afterward, bypassing the factory
 * entirely, to still exercise the historical-bad-data path this migration
 * repairs.
 */
function forceNullCompanyId(string $table, int $id): void
{
    DB::table($table)->where('id', $id)->update(['company_id' => null]);
}

it('backfills purchases_order_lines.company_id from its parent order, both null and mismatched', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $order = Order::factory()->create(['company_id' => $companyA->id]);

    $nullLine = OrderLine::factory()->create(['order_id' => $order->id]);
    forceNullCompanyId('purchases_order_lines', $nullLine->id);

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

    $nullLine = RequisitionLine::factory()->create(['requisition_id' => $agreement->id]);
    forceNullCompanyId('purchases_requisition_lines', $nullLine->id);

    $mismatchLine = RequisitionLine::factory()->create(['requisition_id' => $agreement->id, 'company_id' => $companyB->id]);

    runPurchasesLineCompanyIdBackfill();

    expect(RequisitionLine::find($nullLine->id)->company_id)->toBe($companyA->id)
        ->and(RequisitionLine::find($mismatchLine->id)->company_id)->toBe($companyA->id);
});

it('is idempotent: running the backfill twice does not change already-correct rows', function () {
    $companyA = Company::factory()->create();
    $order = Order::factory()->create(['company_id' => $companyA->id]);
    $line = OrderLine::factory()->create(['order_id' => $order->id]);
    forceNullCompanyId('purchases_order_lines', $line->id);

    runPurchasesLineCompanyIdBackfill();
    $afterFirstRun = OrderLine::find($line->id)->company_id;

    runPurchasesLineCompanyIdBackfill();
    $afterSecondRun = OrderLine::find($line->id)->company_id;

    expect($afterFirstRun)->toBe($companyA->id)
        ->and($afterSecondRun)->toBe($companyA->id);
});

it('defaults a new OrderLine to its order company_id when the factory does not override it', function () {
    $company = Company::factory()->create();
    $order = Order::factory()->create(['company_id' => $company->id]);

    $line = OrderLine::factory()->create(['order_id' => $order->id]);

    expect($line->company_id)->toBe($company->id);
});

it('defaults a new RequisitionLine to its agreement company_id when the factory does not override it', function () {
    $company = Company::factory()->create();
    $agreement = Requisition::factory()->create(['company_id' => $company->id]);

    $line = RequisitionLine::factory()->create(['requisition_id' => $agreement->id]);

    expect($line->company_id)->toBe($company->id);
});

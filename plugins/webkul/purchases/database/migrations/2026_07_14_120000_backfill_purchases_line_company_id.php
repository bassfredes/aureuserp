<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data repair for aureuserp#137 (D4): OrderLine/RequisitionLine company_id
 * must always match their parent's. Before this rollout, Filament's line
 * repeaters derived company_id from the acting user (OrderResource) or
 * omitted it entirely (PurchaseAgreementResource's lines repeater had no
 * company_id field at all) — see the fixes in both resources in this same
 * PR. Runs as raw SQL, not Eloquent, so it is unaffected by HasCompanyScope
 * being applied to these models in this same migration set, and is safe to
 * re-run: each statement only ever narrows a row toward its parent's
 * company_id, never touches an already-correct row.
 */
return new class extends Migration
{
    public function up(): void
    {
        // "lines" is a reserved word in MySQL's grammar (LOAD DATA ... LINES
        // TERMINATED BY), so it can't be used bare as a table alias here —
        // "ol"/"rl" sidestep that instead of relying on backtick-quoting.
        DB::statement(<<<'SQL'
            UPDATE purchases_order_lines AS ol
            INNER JOIN purchases_orders AS po ON po.id = ol.order_id
            SET ol.company_id = po.company_id
            WHERE ol.company_id IS NULL OR ol.company_id <> po.company_id
        SQL);

        DB::statement(<<<'SQL'
            UPDATE purchases_requisition_lines AS rl
            INNER JOIN purchases_requisitions AS pr ON pr.id = rl.requisition_id
            SET rl.company_id = pr.company_id
            WHERE rl.company_id IS NULL OR rl.company_id <> pr.company_id
        SQL);
    }

    public function down(): void
    {
        // Data repair only: reversing would reintroduce the exact
        // NULL/mismatched company_id rows this migration exists to fix.
    }
};

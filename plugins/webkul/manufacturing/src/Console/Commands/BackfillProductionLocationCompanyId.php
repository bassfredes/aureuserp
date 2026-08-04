<?php

namespace Webkul\Manufacturing\Console\Commands;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Webkul\Manufacturing\Models\Warehouse;
use Webkul\Support\Services\CompanyContext;

/**
 * Preflight-check and repoint each manufacturing-enabled Warehouse's
 * "Pre-Production -> Production" Rule to its own company's Production
 * Location (aureuserp #138 PR4 gap: Warehouse::createManufacturingRules()
 * and Warehouse::syncManufacturingWarehouseConfiguration() used to resolve
 * "the" Production location via an unscoped, company-blind global lookup —
 * see git history for the prior implementation. A Rule created for company
 * B could therefore end up pointing at company A's Production Location
 * whenever A's row happened to be the query's arbitrary "first" match).
 *
 * Unlike sales' BackfillTagCompanyId, there is no ambiguous case to detect
 * here: a Warehouse always has exactly one company, so the corrective
 * action is always the same — resolve (or idempotently provision) that
 * company's own Production Location and point the Warehouse's
 * "Pre-Production -> Production" Rule at it. That Rule is located the same
 * way Warehouse::syncManufacturingWarehouseConfiguration() already matches
 * it (route_id + operation_type_id + source_location_id) since that
 * transition can be individually archived/restored per manufacture_steps
 * and there is no dedicated column linking a Warehouse to it directly.
 *
 * Reads happen via the DB facade (must see every row regardless of tenant,
 * including archived/soft-deleted Rule rows, the same reasoning
 * BackfillTagCompanyId uses). Provisioning a missing Production Location
 * goes through Warehouse::resolveOrCreateProductionLocation() (Eloquent),
 * not a raw insert, because Location::boot() has side effects (parent_path,
 * full_name, the single-Production-per-company guard) that must run.
 * Repointing an existing Rule's destination_location_id is a plain column
 * update via the DB facade — Rule carries no equivalent boot() guard to
 * preserve.
 *
 * The Eloquent portion (Warehouse::find(), resolveOrCreateProductionLocation()'s
 * own "does one already exist?" read, and Location::boot()'s
 * guardSingleProductionLocationPerCompany() duplicate check) all go through
 * CompanyScope. In real deploy execution there is no authenticated user, and
 * CompanyScope::apply() fails closed (`1 = 0`) with no user and no active
 * CompanyContext (ADR 0007) — every one of those reads would silently see
 * zero rows, defeating both the idempotency check and the uniqueness guard
 * and letting duplicate Production locations slip through on every run.
 * withCompanyContext() opens CompanyContext::runForCompany() for exactly
 * that per-warehouse company around the Eloquent calls when there is no
 * authenticated actor (console/queue execution — the normal case for this
 * command), matching TestBootstrapHelper::withSystemContextIfNoUser()'s
 * "already authenticated? just run; otherwise open a system context" shape.
 *
 * Out of scope: Move/MoveLine rows created before this fix that already
 * reference a wrong-company Production location are NOT repointed by this
 * command — it only fixes the Warehouse's own
 * "Pre-Production -> Production" Rule, the single write path that produced
 * the corruption. No production data exists in any environment yet (#138
 * PR4), and this dev/test worktree's own database was audited when this
 * fix was authored: a single company, a single warehouse, and its one
 * Production location was already correctly owned — so there are no known
 * affected Move/MoveLine rows to backfill anywhere today.
 */
class BackfillProductionLocationCompanyId extends Command
{
    protected $signature = 'manufacturing:production-location:backfill
                            {--dry-run : Report the preflight only, do not write any changes.}';

    protected $description = "Repoint each Warehouse's Pre-Production -> Production Rule to its own company's Production Location.";

    public function handle(): int
    {
        $warehouses = DB::table('inventories_warehouses')
            ->whereNotNull('company_id')
            ->whereNotNull('pbm_route_id')
            ->whereNotNull('manu_type_id')
            ->whereNotNull('pbm_loc_id')
            ->get(['id', 'code', 'company_id', 'pbm_route_id', 'manu_type_id', 'pbm_loc_id']);

        if ($warehouses->isEmpty()) {
            $this->info('No manufacturing-enabled warehouses found. Nothing to do.');

            return self::SUCCESS;
        }

        $toRepoint = [];
        $toProvision = [];
        $alreadyCorrect = 0;
        $noRule = 0;

        foreach ($warehouses as $warehouse) {
            $rule = DB::table('inventories_rules')
                ->where('route_id', $warehouse->pbm_route_id)
                ->where('operation_type_id', $warehouse->manu_type_id)
                ->where('source_location_id', $warehouse->pbm_loc_id)
                ->first(['id', 'destination_location_id']);

            if (! $rule) {
                $noRule++;

                continue;
            }

            $productionLocationId = DB::table('inventories_locations')
                ->where('type', 'production')
                ->where('company_id', $warehouse->company_id)
                ->whereNull('deleted_at')
                ->value('id');

            if (! $productionLocationId) {
                $toProvision[] = $warehouse;

                continue;
            }

            if ((int) $rule->destination_location_id === (int) $productionLocationId) {
                $alreadyCorrect++;

                continue;
            }

            $toRepoint[] = [
                'warehouse_id'   => $warehouse->id,
                'warehouse_code' => $warehouse->code,
                'company_id'     => $warehouse->company_id,
                'rule_id'        => $rule->id,
                'from'           => $rule->destination_location_id,
                'to'             => $productionLocationId,
            ];
        }

        $this->info(sprintf(
            'Preflight: %d warehouse(s) already correct, %d rule(s) to repoint, %d warehouse(s) need a Production location provisioned, %d warehouse(s) have no matching Rule row.',
            $alreadyCorrect,
            count($toRepoint),
            count($toProvision),
            $noRule,
        ));

        foreach ($toRepoint as $entry) {
            $this->warn(sprintf(
                '  - Warehouse #%d (%s, company #%d): Rule #%d points at Location #%d, should point at the company\'s own Production Location #%d.',
                $entry['warehouse_id'],
                $entry['warehouse_code'],
                $entry['company_id'],
                $entry['rule_id'],
                $entry['from'],
                $entry['to'],
            ));
        }

        foreach ($toProvision as $warehouse) {
            $this->warn(sprintf(
                '  - Warehouse #%d (%s, company #%d): no Production Location exists yet for this company; one will be provisioned.',
                $warehouse->id,
                $warehouse->code,
                $warehouse->company_id,
            ));
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run: no changes were written.');

            return self::SUCCESS;
        }

        if (empty($toRepoint) && empty($toProvision)) {
            $this->info('Nothing to backfill.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($toRepoint, $toProvision): void {
            foreach ($toProvision as $warehouseRow) {
                $this->withCompanyContext((int) $warehouseRow->company_id, function () use ($warehouseRow): void {
                    $warehouse = Warehouse::find($warehouseRow->id);

                    if (! $warehouse) {
                        return;
                    }

                    $productionLocation = $warehouse->resolveOrCreateProductionLocation();

                    $rule = DB::table('inventories_rules')
                        ->where('route_id', $warehouseRow->pbm_route_id)
                        ->where('operation_type_id', $warehouseRow->manu_type_id)
                        ->where('source_location_id', $warehouseRow->pbm_loc_id)
                        ->first(['id']);

                    if ($rule) {
                        DB::table('inventories_rules')->where('id', $rule->id)->update(['destination_location_id' => $productionLocation->id]);
                    }
                });
            }

            foreach ($toRepoint as $entry) {
                DB::table('inventories_rules')->where('id', $entry['rule_id'])->update(['destination_location_id' => $entry['to']]);
            }
        });

        $this->info(sprintf('Repointed %d rule(s) and provisioned %d missing Production location(s).', count($toRepoint), count($toProvision)));

        return self::SUCCESS;
    }

    /**
     * Runs $callback under CompanyScope correctly scoped to $companyId —
     * required for every Eloquent read/write inside it
     * (Warehouse::find(), resolveOrCreateProductionLocation()'s own
     * existence check, Location's single-Production-per-company guard) to
     * see the right rows instead of CompanyScope's fail-closed `1 = 0`
     * default. An already-authenticated actor (e.g. a test wrapping this
     * command in actingAs()) is scoped by CompanyScope through its own
     * allowedCompanyIds() already, and CompanyContext::run() refuses to
     * open a context on top of an authenticated user (ADR 0007) — so this
     * only opens one when there truly is no actor, the normal console/queue
     * case for this command.
     */
    private function withCompanyContext(int $companyId, Closure $callback): mixed
    {
        if (Auth::check()) {
            return $callback();
        }

        return CompanyContext::runForCompany(
            companyId: $companyId,
            reason: 'manufacturing:production-location:backfill — resolve/provision this company\'s own Production location',
            caller: self::class,
            callback: $callback,
        );
    }
}

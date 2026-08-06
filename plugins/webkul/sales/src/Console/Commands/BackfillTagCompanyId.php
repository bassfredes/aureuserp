<?php

namespace Webkul\Sale\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Preflight-check and backfill sales_tags.company_id (#138 PR4 gap: Tag had
 * no company_id at all before HasCompanyScope/HasStrictCompanyId were added
 * to it). Deliberately queries the DB facade instead of the Tag/Order
 * Eloquent models — Tag now carries HasCompanyScope, and this is a one-time
 * data migration that must see every row regardless of tenant, the same
 * reasoning that lets a raw schema migration bypass model scopes entirely.
 *
 * Company is derived only from the sales_order_tags pivot -> sales_orders.company_id
 * (2026-08-03 adversarial design review, #138 PR4 A4K): no company_or_shared
 * contract and no Calendar-style default seeder exists for tags, so:
 *
 * - A tag used by orders from more than one company is a genuine data
 *   conflict and aborts the whole run (no writes at all) rather than being
 *   resolved silently.
 * - A tag with no associated orders (orphan) is left untouched and reported
 *   as requiring manual resolution — it is NEVER inferred from its
 *   creator's default_company_id. Its company_id stays null, which under
 *   strict_company visibility means it is invisible/unwritable until a
 *   human assigns it a company (see Tag::class docblock).
 *
 * The backfill write only runs after the preflight has reported zero
 * conflicts, and never runs at all in --dry-run mode.
 */
class BackfillTagCompanyId extends Command
{
    protected $signature = 'sales:tags:backfill-company
                            {--dry-run : Report the preflight only, do not write any changes.}';

    protected $description = 'Preflight-check and backfill sales_tags.company_id from the companies of its associated orders.';

    public function handle(): int
    {
        $tagIds = DB::table('sales_tags')->whereNull('company_id')->pluck('id');

        if ($tagIds->isEmpty()) {
            $this->info('No sales_tags rows with a null company_id. Nothing to do.');

            return self::SUCCESS;
        }

        $companyIdsByTag = DB::table('sales_order_tags')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_tags.order_id')
            ->whereIn('sales_order_tags.tag_id', $tagIds)
            ->whereNotNull('sales_orders.company_id')
            ->select('sales_order_tags.tag_id', 'sales_orders.company_id')
            ->distinct()
            ->get()
            ->groupBy('tag_id');

        $conflicts = [];
        $orphans = [];
        $resolved = [];

        foreach ($tagIds as $tagId) {
            $companyIds = $companyIdsByTag->get($tagId, collect())
                ->pluck('company_id')
                ->unique()
                ->values();

            if ($companyIds->count() > 1) {
                $conflicts[$tagId] = $companyIds->all();
            } elseif ($companyIds->count() === 1) {
                $resolved[$tagId] = $companyIds->first();
            } else {
                $orphans[] = $tagId;
            }
        }

        $this->info(sprintf(
            'Preflight: %d tag(s) resolvable, %d conflict(s), %d orphan(s) requiring manual resolution.',
            count($resolved),
            count($conflicts),
            count($orphans),
        ));

        if (! empty($conflicts)) {
            $this->error('Aborting: the following tags are used by orders from more than one company and cannot be backfilled automatically. Resolve the conflict manually (e.g. split the tag per company) and re-run:');

            foreach ($conflicts as $tagId => $companyIds) {
                $this->error(sprintf('  - Tag #%d is used by orders from companies: %s', $tagId, implode(', ', $companyIds)));
            }

            return self::FAILURE;
        }

        if (! empty($orphans)) {
            $this->warn('The following tags have no associated orders and were left untouched (company_id stays null) — requires manual resolution. Under strict_company visibility they are invisible and unwritable to every tenant until a company_id is assigned explicitly:');

            foreach ($orphans as $tagId) {
                $this->warn(sprintf('  - Tag #%d', $tagId));
            }
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run: no changes were written.');

            return self::SUCCESS;
        }

        if (empty($resolved)) {
            $this->info('No tags to backfill after preflight.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($resolved): void {
            foreach ($resolved as $tagId => $companyId) {
                DB::table('sales_tags')->where('id', $tagId)->update(['company_id' => $companyId]);
            }
        });

        $this->info(sprintf('Backfilled company_id for %d tag(s).', count($resolved)));

        return self::SUCCESS;
    }
}

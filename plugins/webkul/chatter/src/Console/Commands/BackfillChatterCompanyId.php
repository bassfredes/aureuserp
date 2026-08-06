<?php

namespace Webkul\Chatter\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Webkul\Support\Models\Company;
use Webkul\Support\Traits\HasCompanyScope;

/**
 * Preflight-check and backfill company_id across the three chatter tables
 * that gained the column in this rollout — chatter_messages,
 * chatter_attachments (owner relation: messageable) and chatter_followers
 * (owner relation: followable) — #138 PR4 chatter gap, 2026-08-03 Codex
 * adversarial review. Same discipline as
 * Sale\Console\Commands\BackfillTagCompanyId: queries the DB facade
 * directly rather than the Eloquent models (which now carry
 * HasCompanyScope/fail-closed write guards of their own via
 * ResolvesChatterCompany), reports every row it cannot resolve instead of
 * guessing, and never writes in --dry-run mode.
 *
 * Unlike Tag (many orders -> one tag, real many-to-one conflicts
 * possible), each chatter row has exactly ONE polymorphic owner, so there
 * is no "conflicting company" case to abort a whole table on — a row is
 * either resolvable (its owner is Company itself, or uses HasCompanyScope,
 * including a legitimate null on a company_or_shared owner) or it requires
 * manual resolution (owner missing/hard-deleted, unknown morph class, or a
 * global_party_identity owner such as Partner with no company scope of its
 * own — NEVER inferred from a creator's default_company_id, matching
 * ResolvesChatterCompany's own fail-closed rule for live writes).
 *
 * Also scans rows that already carry a non-null company_id (not just
 * whereNull) — legacy data written by the pre-fix caller-derived logic
 * (company_id copied from the acting user's default company rather than
 * the owner) can be wrong without ever being null, so a null-only scan
 * would leave a mislabeled row permanently and silently visible/notifiable
 * only to the wrong company under HasCompanyScope + IncludesSharedCompanyRows
 * enforcement — the exact leak this command exists to close. A mismatch
 * (persisted company_id differs from the value derived from the same owner
 * resolution path) is only ever reported, in both --dry-run and a real
 * run — never auto-corrected, since overwriting a persisted value without
 * human review carries its own risk of masking a legitimately reassigned
 * owner.
 */
class BackfillChatterCompanyId extends Command
{
    protected $signature = 'chatter:backfill-company
                            {--dry-run : Report the preflight only, do not write any changes.}';

    protected $description = 'Preflight-check and backfill company_id on chatter_messages/chatter_attachments/chatter_followers from their polymorphic owner.';

    private const OWNER_COLUMNS = [
        'chatter_messages'    => ['type' => 'messageable_type', 'id' => 'messageable_id'],
        'chatter_attachments' => ['type' => 'messageable_type', 'id' => 'messageable_id'],
        'chatter_followers'   => ['type' => 'followable_type', 'id' => 'followable_id'],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        foreach (self::OWNER_COLUMNS as $table => $columns) {
            $this->backfillTable($table, $columns['type'], $columns['id'], $dryRun);
        }

        return self::SUCCESS;
    }

    private function backfillTable(string $table, string $typeColumn, string $idColumn, bool $dryRun): void
    {
        $rows = DB::table($table)
            ->select('id', $typeColumn, $idColumn, 'company_id')
            ->get();

        if ($rows->isEmpty()) {
            $this->info("{$table}: no rows found. Nothing to do.");

            return;
        }

        $ownerGroups = $rows->groupBy(fn ($row) => $row->{$typeColumn}.'#'.$row->{$idColumn});

        $writableCompanyByOwnerKey = [];
        $manualReasonByOwnerKey = [];
        $mismatchedRowsByOwnerKey = [];

        foreach ($ownerGroups as $ownerKey => $groupRows) {
            $type = $groupRows->first()->{$typeColumn};
            $id = $groupRows->first()->{$idColumn};

            [$resolved, $manualReason] = $this->resolveOwnerCompanyId($type, $id);

            if ($manualReason !== null) {
                $manualReasonByOwnerKey[$ownerKey] = $manualReason;

                continue;
            }

            $nullRows = $groupRows->filter(fn ($row) => $row->company_id === null);

            // A legitimately null company_id (owner is company_or_shared
            // and is itself shared) needs no write — the row is already
            // null, which is the correct, already-shared state.
            if ($resolved !== null && $nullRows->isNotEmpty()) {
                $writableCompanyByOwnerKey[$ownerKey] = $resolved;
            }

            // Rows that already carry a non-null company_id must match the
            // value derived from the same owner resolution used above —
            // never silently trusted, never silently rewritten. A legacy
            // row mislabeled by the pre-fix caller-derived logic is exactly
            // this case, and stays permanently mislabeled if only null rows
            // are ever inspected.
            $mismatched = $groupRows->filter(
                fn ($row) => $row->company_id !== null && (int) $row->company_id !== $resolved
            );

            if ($mismatched->isNotEmpty()) {
                $mismatchedRowsByOwnerKey[$ownerKey] = [
                    'expected' => $resolved,
                    'rows'     => $mismatched->map(fn ($row) => [
                        'id'         => $row->id,
                        'company_id' => (int) $row->company_id,
                    ])->all(),
                ];
            }
        }

        $writableRowCount = collect($writableCompanyByOwnerKey)->keys()
            ->sum(fn ($ownerKey) => $ownerGroups[$ownerKey]->filter(fn ($row) => $row->company_id === null)->count());

        $mismatchedRowCount = collect($mismatchedRowsByOwnerKey)
            ->sum(fn (array $info) => count($info['rows']));

        $this->info(sprintf(
            '%s: %d owner(s) resolvable and writable (%d row(s)), %d owner(s) require manual resolution, %d row(s) already have a mismatched company_id.',
            $table,
            count($writableCompanyByOwnerKey),
            $writableRowCount,
            count($manualReasonByOwnerKey),
            $mismatchedRowCount,
        ));

        if (! empty($manualReasonByOwnerKey)) {
            $this->warn("{$table}: the following owners could not be resolved automatically and were left untouched (null rows stay null, non-null rows stay as-is) — requires manual resolution. Under company_or_shared visibility these rows remain visible everywhere until resolved, they are never hidden:");

            foreach ($manualReasonByOwnerKey as $ownerKey => $reason) {
                $this->warn("  - owner {$ownerKey}: {$reason}");
            }
        }

        if (! empty($mismatchedRowsByOwnerKey)) {
            $this->warn("{$table}: the following rows already have a non-null company_id that does NOT match the company_id derived from their owner — left untouched, requires manual review before any write:");

            foreach ($mismatchedRowsByOwnerKey as $ownerKey => $info) {
                $expected = $info['expected'] === null ? 'null (shared)' : (string) $info['expected'];

                foreach ($info['rows'] as $row) {
                    $this->warn("  - owner {$ownerKey}: row id {$row['id']} has company_id={$row['company_id']}, expected {$expected}");
                }
            }
        }

        if ($dryRun) {
            $this->info("{$table}: dry run, no changes written.");

            return;
        }

        if (empty($writableCompanyByOwnerKey)) {
            $this->info("{$table}: no rows to backfill after preflight.");

            return;
        }

        DB::transaction(function () use ($table, $ownerGroups, $writableCompanyByOwnerKey): void {
            foreach ($writableCompanyByOwnerKey as $ownerKey => $companyId) {
                $ids = $ownerGroups[$ownerKey]
                    ->filter(fn ($row) => $row->company_id === null)
                    ->pluck('id');

                DB::table($table)->whereIn('id', $ids)->update(['company_id' => $companyId]);
            }
        });

        $this->info(sprintf('%s: backfilled company_id for %d row(s).', $table, $writableRowCount));
    }

    /**
     * @return array{0: ?int, 1: ?string} [resolvedCompanyIdOrNull, manualResolutionReasonOrNull]
     */
    private function resolveOwnerCompanyId(?string $type, mixed $id): array
    {
        if (! $type || ! $id) {
            return [null, 'empty owner type/id on the chatter row itself'];
        }

        $ownerClass = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($ownerClass) || ! is_subclass_of($ownerClass, Model::class)) {
            return [null, "unknown owner class for morph type '{$type}'"];
        }

        if (is_a($ownerClass, Company::class, true)) {
            return [(int) $id, null];
        }

        if (! in_array(HasCompanyScope::class, class_uses_recursive($ownerClass), true)) {
            return [null, "owner {$ownerClass} carries no company scope of its own (e.g. Partner) — never inferred automatically"];
        }

        $ownerTable = (new $ownerClass)->getTable();
        $ownerRow = DB::table($ownerTable)->where('id', $id)->first();

        if (! $ownerRow) {
            return [null, "owner {$ownerClass}#{$id} could not be found (missing or hard-deleted)"];
        }

        return [$ownerRow->company_id !== null ? (int) $ownerRow->company_id : null, null];
    }
}

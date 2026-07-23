<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// #138 PR4 ola4B, approved contract: deterministic backfill for
// BankAccount rows that existed before the membership pivot did. Only
// the tenant-owner references actually identified in the PR 4 audit
// matrix are used — Employee.bank_account_id + Employee.company_id, and
// PaymentRegister.partner_bank_id + PaymentRegister.company_id (Payment/
// Move/Journal reference the same physical partners_bank_accounts rows
// via partner_bank_id/bank_account_id but were not found to carry
// meaningful historical data ahead of this rollout in this codebase's
// fresh-install deployment model — a fresh install always creates
// BankAccount rows through the model's own auto-enable-on-create hook,
// never through this migration). An unused or ambiguous row (referenced
// by no identified owner, or by owners whose companies disagree) gets NO
// membership row here: it stays inaccessible until someone with the
// right context explicitly enables it — never inferred from
// Partner.company_id, creator.default_company_id, or Company::first().
return new class extends Migration
{
    public function up(): void
    {
        $rows = collect();

        // Both referencing tables belong to plugins installed independently
        // of `partners` (or not installed at all in a partial/focused
        // install) — this migration must not assume either exists yet.
        if (Schema::hasTable('employees_employees')) {
            DB::table('employees_employees')
                ->whereNotNull('bank_account_id')
                ->whereNotNull('company_id')
                ->select('bank_account_id', 'company_id')
                ->get()
                ->each(fn ($row) => $rows->push([$row->bank_account_id, $row->company_id]));
        }

        if (Schema::hasTable('accounts_payment_registers')) {
            DB::table('accounts_payment_registers')
                ->whereNotNull('partner_bank_id')
                ->whereNotNull('company_id')
                ->select('partner_bank_id as bank_account_id', 'company_id')
                ->get()
                ->each(fn ($row) => $rows->push([$row->bank_account_id, $row->company_id]));
        }

        $rows->unique(fn ($pair) => implode(':', $pair))
            ->each(function (array $pair): void {
                [$bankAccountId, $companyId] = $pair;

                DB::table('partners_bank_account_companies')->insertOrIgnore([
                    'bank_account_id' => $bankAccountId,
                    'company_id'      => $companyId,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            });
    }

    public function down(): void
    {
        // Intentionally irreversible: distinguishing backfilled membership
        // rows from ones since granted through normal use is not possible
        // after the fact.
    }
};

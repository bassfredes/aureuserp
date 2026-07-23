<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// #138 PR4 ola4B, approved contract: BankAccount hangs off Partner
// (global_party_identity, cross-company by design) so a single company_id
// column would conflict with a partner transacting with several companies
// — a membership pivot instead. A row present here means "this
// BankAccount is enabled for this Company"; absence means inaccessible
// until explicit remediation (see the sibling backfill migration).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners_bank_account_companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained('partners_bank_accounts')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['bank_account_id', 'company_id'], 'bank_account_companies_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partners_bank_account_companies');
    }
};

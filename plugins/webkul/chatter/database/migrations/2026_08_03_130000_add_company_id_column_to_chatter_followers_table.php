<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Deliberately NOT added to chatter_followers_unique
     * (followable_type/followable_id/partner_id) — see Follower::class
     * docblock: company_id is a pure function of followable_id, so widening
     * the unique key would only let a genuine duplicate-follower bug hide
     * behind a mismatched company_id (#138 PR4 chatter gap).
     */
    public function up(): void
    {
        Schema::table('chatter_followers', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chatter_followers', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HasLogActivity::logModelActivity() writes `causer_type`/`causer_id` as
     * null when there is no authenticated user (Auth::user() is null), e.g.
     * models created via factories, seeders, or system processes. The
     * `morphs('causer')` column pair from the original migration is NOT NULL,
     * so any such insert fails with a constraint violation instead of
     * recording an activity with no causer.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE chatter_messages MODIFY causer_type VARCHAR(255) NULL');
        DB::statement('ALTER TABLE chatter_messages MODIFY causer_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        DB::table('chatter_messages')->whereNull('causer_type')->orWhereNull('causer_id')->delete();

        DB::statement('ALTER TABLE chatter_messages MODIFY causer_type VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE chatter_messages MODIFY causer_id BIGINT UNSIGNED NOT NULL');
    }
};

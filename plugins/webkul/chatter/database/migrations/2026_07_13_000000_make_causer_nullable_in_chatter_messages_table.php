<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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

    /**
     * Refuses to restore NOT NULL over existing null-causer rows instead of
     * deleting them: those rows are legitimate activity records left by
     * commands, factories, or system processes, not migration debris. If
     * this needs to roll back on an environment that already has null-causer
     * rows, decide and apply an explicit backfill (e.g. a documented system
     * actor) before re-running the down migration.
     */
    public function down(): void
    {
        $orphaned = DB::table('chatter_messages')
            ->whereNull('causer_type')
            ->orWhereNull('causer_id')
            ->count();

        if ($orphaned > 0) {
            throw new RuntimeException(
                "Cannot restore NOT NULL on chatter_messages.causer_type/causer_id: {$orphaned} row(s) have a null causer. ".
                'Backfill them to a documented system actor before rolling back this migration; this migration will not delete them.'
            );
        }

        DB::statement('ALTER TABLE chatter_messages MODIFY causer_type VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE chatter_messages MODIFY causer_id BIGINT UNSIGNED NOT NULL');
    }
};

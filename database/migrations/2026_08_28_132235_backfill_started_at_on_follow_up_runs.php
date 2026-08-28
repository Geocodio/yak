<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RunFollowUpJob never stamped started_at, and ThreadBuilder hides any run
 * without one — so every historical follow-up is invisible in its
 * conversation thread. The job now stamps it; this recovers the runs that
 * already finished by falling back to when they were created.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tasks')
            ->whereNotNull('parent_task_id')
            ->whereNull('started_at')
            ->whereNotNull('completed_at')
            ->update(['started_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        // Irreversible: the original null is indistinguishable from a
        // legitimately backfilled timestamp once this has run.
    }
};

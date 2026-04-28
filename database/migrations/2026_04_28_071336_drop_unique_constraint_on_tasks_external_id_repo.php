<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Multiple review tasks per PR are expected (one per re-review on a new
     * push). Every channel already enforces dedup in PHP — Sentry returns
     * 409 from its WebhookController, ScanCiCommand calls isDuplicate(),
     * EnqueuePrReview checks for in-flight reviews. The unique constraint
     * was a defensive backstop that no caller relied on, and it was
     * actively breaking the synchronize → incremental review path.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropUnique('tasks_external_id_repo_unique');
            $table->index(['external_id', 'repo']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['external_id', 'repo']);
            $table->unique(['external_id', 'repo']);
        });
    }
};

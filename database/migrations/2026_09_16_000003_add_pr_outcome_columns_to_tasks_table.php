<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // When Yak opened the PR, distinct from created_at (when the
            // request arrived) so time-to-deliver and time-open are exact.
            $table->timestamp('pr_opened_at')->nullable()->after('pr_number');

            // Commits on the PR branch by someone other than Yak, counted by
            // yak:reconcile-pr-state. A human touching the branch before
            // merge is the clearest "the first pass wasn't enough" signal.
            $table->unsignedInteger('human_commits')->nullable()->after('pr_opened_at');

            // Last time the reconciler asked GitHub about this PR.
            $table->timestamp('pr_state_checked_at')->nullable()->after('human_commits');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['pr_opened_at', 'human_commits', 'pr_state_checked_at']);
        });
    }
};

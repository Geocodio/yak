<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Set by DrainForDeployCommand alongside the existing Failed
            // status/message when a straggler is force-failed. Status stays
            // Failed so the audit trail and channel notification are
            // unchanged; this column is what tells yak:resume-interrupted-tasks
            // the failure was infrastructure, not the task's own merits.
            $table->timestamp('interrupted_by_deploy_at')->nullable()->after('completed_at');

            // Bounds how many times yak:resume-interrupted-tasks will
            // requeue the same task, so a task that keeps getting caught by
            // deploys doesn't retry forever.
            $table->unsignedInteger('deploy_resume_count')->default(0)->after('interrupted_by_deploy_at');

            // The job class that actually claimed the task, set by
            // App\Jobs\Concerns\ClaimsTask::claimTask() at claim time. Resume
            // must re-dispatch the class the task was really running, not
            // guess from `mode` — mode alone can't distinguish, e.g., a
            // follow-up (RunFollowUpJob) from a fresh RunYakJob.
            $table->string('claimed_job_class')->nullable()->after('deploy_resume_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['interrupted_by_deploy_at', 'deploy_resume_count', 'claimed_job_class']);
        });
    }
};

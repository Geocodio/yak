<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ci_job_failures', function (Blueprint $table) {
            $table->id();
            $table->string('repo');
            $table->string('workflow_path');
            $table->string('workflow_name');
            $table->string('job_name');
            $table->string('failed_step')->nullable();
            $table->json('test_names')->nullable();
            $table->string('branch');
            $table->string('head_sha', 40);
            $table->unsignedBigInteger('job_id')->unique();
            $table->unsignedBigInteger('run_id');
            $table->string('job_url');
            $table->string('actor_login');
            $table->dateTime('failed_at');
            $table->dateTime('resolved_at')->nullable();
            $table->string('resolution')->nullable();

            $table->index(['repo', 'workflow_path', 'resolved_at', 'failed_at'], 'ci_job_failures_lookup_index');
        });

        Schema::create('ci_failure_claims', function (Blueprint $table) {
            $table->id();
            $table->string('repo');
            $table->string('workflow_path');
            $table->string('job_name')->nullable();
            $table->string('test_class')->nullable();
            $table->foreignId('yak_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->string('skipped_pr_url')->nullable();
            $table->string('fix_merge_sha', 40)->nullable();
            $table->string('last_outcome')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->dateTime('blocked_until')->nullable();
            $table->dateTime('created_at')->nullable();

            $table->index(['repo', 'workflow_path']);
        });

        Schema::create('ci_default_branch_greens', function (Blueprint $table) {
            $table->id();
            $table->string('repo');
            $table->string('workflow_name');
            $table->string('job_name');
            $table->dateTime('completed_at');

            $table->unique(['repo', 'workflow_name', 'job_name'], 'ci_default_branch_greens_job_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ci_default_branch_greens');
        Schema::dropIfExists('ci_failure_claims');
        Schema::dropIfExists('ci_job_failures');
    }
};

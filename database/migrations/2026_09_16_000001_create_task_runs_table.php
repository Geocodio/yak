<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('yak_task_id')->constrained('tasks')->cascadeOnDelete();

            // initial | retry | follow_up | clarification | research | review | setup
            $table->string('kind', 24);
            $table->unsignedSmallInteger('attempt_number')->default(1);
            $table->string('job_class')->nullable();
            $table->string('queue', 32)->nullable();

            // Denormalised from the task so the Analytics page can group
            // without a join, and so the row still reads after a reroute.
            $table->string('repo')->nullable();
            $table->string('source', 32)->nullable();
            $table->string('mode', 16)->nullable();

            // success | error | clarification | no_changes | exception
            $table->string('outcome', 24)->nullable();
            $table->string('error_subtype', 64)->nullable();
            $table->text('error_message')->nullable();

            $table->string('model', 64)->nullable();
            $table->string('cli_version', 32)->nullable();
            $table->string('session_id', 64)->nullable();
            $table->boolean('resumed')->default(false);
            $table->boolean('stale_session_retry')->default(false);
            $table->boolean('synthesized_result')->default(false);
            $table->string('forced_termination', 32)->nullable();

            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('agent_started_at')->nullable();
            $table->timestamp('agent_finished_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            // Stage durations. Typed columns for the stages every run has,
            // plus a JSON bag for anything a specific job marks on top.
            $table->unsignedInteger('queue_wait_ms')->nullable();
            $table->unsignedInteger('sandbox_create_ms')->nullable();
            $table->unsignedInteger('git_prepare_ms')->nullable();
            $table->unsignedInteger('agent_ms')->nullable();
            $table->unsignedInteger('agent_api_ms')->nullable();
            $table->unsignedInteger('post_agent_ms')->nullable();
            $table->unsignedInteger('teardown_ms')->nullable();
            $table->unsignedInteger('total_ms')->nullable();
            $table->json('stages')->nullable();

            $table->decimal('cost_usd', 10, 4)->default(0);
            $table->unsignedInteger('num_turns')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cache_read_tokens')->default(0);
            $table->unsignedBigInteger('cache_creation_tokens')->default(0);
            $table->json('model_usage')->nullable();

            $table->unsignedInteger('tool_calls')->default(0);
            $table->unsignedInteger('tool_errors')->default(0);
            $table->unsignedInteger('tool_ms')->default(0);
            $table->unsignedInteger('mcp_calls')->default(0);
            $table->json('tool_breakdown')->nullable();
            $table->unsignedInteger('api_retries')->default(0);
            $table->json('api_retry_breakdown')->nullable();
            $table->unsignedInteger('permission_denials')->default(0);
            $table->unsignedInteger('assistant_messages')->default(0);

            $table->unsignedInteger('commits')->nullable();
            $table->unsignedInteger('files_changed')->nullable();
            $table->unsignedInteger('lines_added')->nullable();
            $table->unsignedInteger('lines_removed')->nullable();

            $table->unsignedInteger('worker_peak_mb')->nullable();
            $table->unsignedInteger('prompt_chars')->nullable();

            $table->timestamps();

            $table->index('started_at');
            $table->index(['kind', 'started_at']);
            $table->index(['repo', 'started_at']);
            $table->index(['outcome', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_runs');
    }
};

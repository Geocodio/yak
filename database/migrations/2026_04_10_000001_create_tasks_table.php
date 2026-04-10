<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();

            // Source
            $table->string('source');
            $table->string('repo');
            $table->string('external_id');
            $table->string('external_url')->nullable();

            // Slack threading
            $table->string('slack_channel')->nullable();
            $table->string('slack_thread_ts')->nullable();

            // Task
            $table->string('mode')->default('fix');
            $table->string('visual')->default('none');
            $table->text('description');
            $table->text('context')->nullable();

            // Execution
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('model_used')->nullable();
            $table->string('branch_name')->nullable();
            $table->string('session_id')->nullable();

            // Clarification
            $table->json('clarification_options')->nullable();
            $table->timestamp('clarification_expires_at')->nullable();

            // Results
            $table->string('pr_url')->nullable();
            $table->text('result_summary')->nullable();
            $table->text('error_log')->nullable();

            // Artifacts
            $table->json('screenshots')->nullable();
            $table->string('video_url')->nullable();

            // Metrics
            $table->decimal('cost_usd', 10, 4)->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->unsignedInteger('num_turns')->default(0);

            // Timestamps
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['external_id', 'repo']);
            $table->index('status');
            $table->index('branch_name');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};

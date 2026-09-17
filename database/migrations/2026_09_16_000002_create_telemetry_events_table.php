<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemetry_events', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at', 3);

            // Dotted, low-cardinality name: task.status_changed,
            // webhook.received, pr.merged, feature.used, queue.sampled ...
            $table->string('name', 64);

            $table->string('repo')->nullable();
            $table->string('source', 32)->nullable();

            // nullOnDelete: deleting a task must not erase the record that
            // it happened. Same reasoning as observations.
            $table->foreignId('yak_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->foreignId('task_run_id')->nullable()->constrained('task_runs')->nullOnDelete();

            // Optional non-task subject (PrReview, BranchDeployment, ...).
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->unsignedInteger('duration_ms')->nullable();
            $table->decimal('value', 14, 4)->nullable();

            // Names, counts and durations only -- never prompt text, tool
            // output or diff content. task_logs already holds those.
            $table->json('properties')->nullable();

            $table->index('occurred_at');
            $table->index(['name', 'occurred_at']);
            $table->index(['repo', 'occurred_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemetry_events');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thread_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_run_id')->nullable()->constrained('task_runs')->nullOnDelete();
            $table->foreignId('follow_up_run_id')->nullable()->constrained('task_runs')->nullOnDelete();
            $table->string('task_id', 64);
            $table->string('parent_task_id', 64)->nullable();
            $table->string('tool_use_id', 96)->nullable();
            $table->unsignedTinyInteger('depth')->default(1);
            $table->string('kind', 16)->default('agent');
            $table->string('agent_type', 96)->nullable();
            $table->text('description')->nullable();
            $table->string('workflow_name', 191)->nullable();
            $table->string('model', 96)->nullable();
            $table->boolean('is_background')->default(false);
            // False once Claude will never reply to this agent (its runner was lost), so it opens no follow-up turn.
            $table->boolean('needs_follow_up')->default(true);
            $table->string('status', 16)->default('running');
            $table->string('last_tool', 96)->nullable();
            $table->text('summary')->nullable();
            $table->unsignedBigInteger('total_tokens')->default(0);
            $table->unsignedInteger('tool_uses')->default(0);
            $table->unsignedBigInteger('duration_ms')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('stop_requested_at')->nullable();
            $table->string('stop_error', 500)->nullable();
            $table->json('workflow_progress')->nullable();
            $table->timestamps();

            $table->unique(['thread_id', 'task_id']);
            $table->index(['thread_id', 'status']);
            $table->index(['status', 'thread_id']);
            $table->index(['thread_id', 'follow_up_run_id', 'finished_at']);
        });

        Schema::table('task_runs', function (Blueprint $table) {
            // Set when a reply to one agent ended while others still ran and nothing was announced yet.
            $table->timestamp('quiet_unannounced_at')->nullable();
        });

        Schema::create('thread_agent_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_agent_id')->constrained('thread_agents')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('text');
            $table->timestamp('forwarded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thread_agent_messages');

        Schema::table('task_runs', function (Blueprint $table) {
            $table->dropColumn('quiet_unannounced_at');
        });

        Schema::dropIfExists('thread_agents');
    }
};

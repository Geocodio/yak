<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->unsignedInteger('applied_generation')->default(0);
            $table->unsignedBigInteger('applied_sequence')->default(0);
            $table->string('runner_model', 64)->nullable();
            $table->text('append_system_prompt')->nullable();
            $table->timestamp('runner_restart_at')->nullable();
        });

        Schema::table('task_runs', function (Blueprint $table) {
            $table->uuid('prompt_uuid')->nullable();
            $table->text('prompt')->nullable();
            $table->json('tracker_state')->nullable();
            $table->unsignedInteger('assistant_turns')->default(0);
        });

        Schema::create('thread_tool_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('threads')->cascadeOnDelete();
            $table->uuid('call_id');
            $table->unsignedInteger('generation');
            $table->string('tool');
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->unique(['thread_id', 'call_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thread_tool_calls');

        Schema::table('task_runs', function (Blueprint $table) {
            $table->dropColumn(['prompt_uuid', 'prompt', 'tracker_state', 'assistant_turns']);
        });

        Schema::table('threads', function (Blueprint $table) {
            $table->dropColumn(['applied_generation', 'applied_sequence', 'runner_model', 'append_system_prompt', 'runner_restart_at']);
        });
    }
};

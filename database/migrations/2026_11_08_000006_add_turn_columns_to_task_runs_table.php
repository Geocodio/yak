<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_runs', function (Blueprint $table) {
            $table->foreignId('thread_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('claude_account_id')->nullable();
            $table->foreignId('driver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('turn_state')->nullable();
            $table->string('prompt_uuid')->nullable();
            $table->json('command_states')->nullable();
            $table->unsignedInteger('background_task_count')->default(0);
            $table->boolean('awaiting_background_result')->default(false);
            $table->unsignedInteger('result_count')->default(0);
            $table->json('last_result_event')->nullable();
            $table->json('turn_cost_usd_by_session')->nullable();
            $table->boolean('restarted')->default(false);
            $table->string('guard_reason')->nullable();
            $table->timestamp('completion_dispatched_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('task_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('thread_id');
            $table->dropConstrainedForeignId('driver_user_id');
            $table->dropColumn([
                'claude_account_id', 'turn_state', 'prompt_uuid', 'command_states', 'background_task_count',
                'awaiting_background_result', 'result_count', 'last_result_event', 'turn_cost_usd_by_session',
                'restarted', 'guard_reason', 'completion_dispatched_at',
            ]);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_runs', function (Blueprint $table) {
            $table->foreignId('thread_id')->nullable()->constrained('threads')->nullOnDelete();
            $table->foreignId('claude_account_id')->nullable()->constrained('claude_accounts')->nullOnDelete();
            $table->foreignId('driver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('turn_state', 24)->default('pending');
            $table->timestamp('completion_event_emitted_at')->nullable();
            $table->decimal('recorded_cost_usd', 10, 4)->default(0);
            $table->unsignedSmallInteger('restart_count')->default(0);
            $table->unsignedInteger('runner_generation')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('task_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('thread_id');
            $table->dropConstrainedForeignId('claude_account_id');
            $table->dropConstrainedForeignId('driver_user_id');
            $table->dropColumn(['turn_state', 'completion_event_emitted_at', 'recorded_cost_usd', 'restart_count', 'runner_generation']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('threads', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('title');
            $table->string('repo');
            $table->string('status', 16)->default('open');
            $table->string('visibility', 16)->default('team');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('continued_from_thread_id')->nullable()->constrained('threads')->nullOnDelete();
            $table->foreignId('parent_thread_id')->nullable()->constrained('threads')->cascadeOnDelete();
            $table->foreignId('root_thread_id')->nullable()->constrained('threads')->cascadeOnDelete();
            $table->foreignId('spawned_by_task_run_id')->nullable()->constrained('task_runs')->nullOnDelete();
            $table->string('kind', 16)->default('general');
            $table->text('brief')->nullable();
            $table->text('result_summary')->nullable();
            $table->unsignedSmallInteger('child_awake_limit')->default(3);
            $table->foreignId('claude_account_id')->nullable()->constrained('claude_accounts')->nullOnDelete();
            $table->string('model', 64)->nullable();
            $table->string('claude_config_dir')->default('/home/yak/.claude');
            $table->string('branch')->nullable();
            $table->string('sandbox_host')->nullable();
            $table->string('sandbox_container')->nullable();
            $table->string('sandbox_state', 16)->default('none');
            $table->timestamp('sandbox_state_changed_at')->nullable();
            $table->string('runner_state', 16)->default('none');
            $table->unsignedInteger('runner_generation')->default(0);
            $table->timestamp('runner_started_at')->nullable();
            $table->string('lock_holder', 16)->default('none');
            $table->string('lock_turn_id', 64)->nullable();
            $table->timestamp('lock_acquired_at')->nullable();
            $table->string('owner_side', 16)->default('yak');
            $table->unsignedBigInteger('owner_device_id')->nullable();
            $table->string('blocked_reason', 32)->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason')->nullable();
            $table->timestamps();

            $table->unique(['parent_thread_id', 'name']);
            $table->index('status');
            $table->index('owner_user_id');
            $table->index('last_activity_at');
            $table->index('sandbox_state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('threads');
    }
};

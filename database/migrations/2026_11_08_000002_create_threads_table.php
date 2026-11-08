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
            $table->string('repo');
            $table->string('status')->default('open');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('claude_account_id')->nullable();
            $table->string('model')->nullable();
            $table->foreignId('account_switched_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('account_switched_at')->nullable();
            $table->string('claude_config_dir')->default('/home/yak/.claude');
            $table->string('sandbox_container')->nullable();
            $table->string('sandbox_state')->default('none');
            $table->timestamp('sandbox_state_changed_at')->nullable();
            $table->string('runner_state')->default('none');
            $table->timestamp('runner_started_at')->nullable();
            $table->unsignedInteger('runner_generation')->default(0);
            $table->string('lock_holder')->default('none');
            $table->unsignedBigInteger('lock_turn_id')->nullable();
            $table->timestamp('lock_acquired_at')->nullable();
            $table->string('blocked_reason')->nullable();
            $table->string('out_cursor')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'sandbox_state', 'last_activity_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('threads');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('co_owner_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('root_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('repository_id')->constrained();
            $table->string('status')->default('pending');
            $table->string('profile_source');
            $table->string('profile_commit_sha')->nullable();
            $table->json('matched_areas');
            $table->json('hold_reasons');
            $table->json('changed_files');
            $table->unsignedInteger('additions')->default(0);
            $table->unsignedInteger('deletions')->default(0);
            $table->unsignedInteger('file_count')->default(0);
            $table->json('asked_user_ids');
            $table->json('ask_attempts');
            $table->string('thread_message_ts')->nullable();
            $table->json('direct_messages');
            $table->timestamp('held_at');
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('expires_at');
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decided_by_login')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('feedback')->nullable();
            $table->json('notified_areas');
            $table->timestamps();
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('co_owner_requests');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thread_diffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('task_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('base_sha', 64);
            $table->string('head_sha', 64);
            $table->unsignedInteger('files_count')->default(0);
            $table->unsignedInteger('additions')->default(0);
            $table->unsignedInteger('deletions')->default(0);
            $table->boolean('is_truncated')->default(false);
            $table->timestamp('captured_at');
            $table->timestamps();
        });

        Schema::create('thread_diff_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_diff_id')->constrained()->cascadeOnDelete();
            $table->text('path');
            $table->text('old_path')->nullable();
            $table->string('status');
            $table->unsignedInteger('additions')->nullable();
            $table->unsignedInteger('deletions')->nullable();
            $table->boolean('is_binary')->default(false);
            $table->boolean('is_too_large')->default(false);
            $table->longText('patch')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thread_diff_files');
        Schema::dropIfExists('thread_diffs');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pr_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('yak_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('repo');
            $table->unsignedInteger('pr_number');
            $table->string('pr_url');
            $table->unsignedBigInteger('github_review_id')->nullable();
            $table->string('commit_sha_reviewed', 64);
            $table->enum('review_scope', ['full', 'incremental']);
            $table->string('incremental_base_sha', 64)->nullable();
            $table->text('summary')->nullable();
            $table->text('verdict')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('pr_closed_at')->nullable();
            $table->timestamp('pr_merged_at')->nullable();
            $table->timestamps();

            $table->index(['repo', 'pr_number']);
            $table->index(['pr_url', 'dismissed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pr_reviews');
    }
};

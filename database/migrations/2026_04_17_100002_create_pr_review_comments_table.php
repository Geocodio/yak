<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pr_review_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pr_review_id')->constrained('pr_reviews')->cascadeOnDelete();
            $table->unsignedBigInteger('github_comment_id')->unique();
            $table->string('file_path');
            $table->unsignedInteger('line_number');
            $table->text('body');
            $table->string('category');
            $table->enum('severity', ['must_fix', 'should_fix', 'consider']);
            $table->boolean('is_suggestion')->default(false);
            $table->unsignedInteger('thumbs_up')->default(0);
            $table->unsignedInteger('thumbs_down')->default(0);
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamps();

            $table->index('category');
            $table->index('severity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pr_review_comments');
    }
};

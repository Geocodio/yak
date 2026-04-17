<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pr_review_comment_reactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pr_review_comment_id')->constrained('pr_review_comments')->cascadeOnDelete();
            $table->unsignedBigInteger('github_reaction_id')->unique();
            $table->string('github_user_login');
            $table->unsignedBigInteger('github_user_id');
            $table->string('content', 16);
            $table->timestamp('reacted_at');
            $table->timestamps();

            $table->index(['github_user_login']);
            $table->index(['content']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pr_review_comment_reactions');
    }
};

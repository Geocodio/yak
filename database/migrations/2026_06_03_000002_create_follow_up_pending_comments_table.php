<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_up_pending_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('yak_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('pr_url')->index();
            $table->text('body');
            $table->string('file')->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->text('diff_hunk')->nullable();
            $table->unsignedBigInteger('github_comment_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_up_pending_comments');
    }
};

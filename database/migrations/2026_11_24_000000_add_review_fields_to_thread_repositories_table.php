<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('thread_repositories', function (Blueprint $table) {
            $table->boolean('pull_request_is_draft')->default(false);
            $table->json('pull_request_reviews')->nullable();
            $table->string('pull_request_mergeable_state', 32)->nullable();
            $table->timestamp('pull_request_opened_at')->nullable();
            $table->string('pull_request_head_sha', 64)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('thread_repositories', function (Blueprint $table) {
            $table->dropColumn([
                'pull_request_is_draft',
                'pull_request_reviews',
                'pull_request_mergeable_state',
                'pull_request_opened_at',
                'pull_request_head_sha',
            ]);
        });
    }
};

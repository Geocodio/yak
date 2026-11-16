<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('thread_repositories', function (Blueprint $table) {
            $table->unsignedInteger('pull_request_number')->nullable()->after('pull_request_url');
            $table->string('pull_request_state', 16)->nullable()->after('pull_request_number');
            $table->string('pull_request_title')->nullable()->after('pull_request_state');
            $table->timestamp('pull_request_closed_at')->nullable()->after('pull_request_title');
            $table->string('ci_status', 16)->nullable()->after('ci_retry_count');
            $table->json('ci_checks')->nullable()->after('ci_status');
            $table->string('last_commit_sha', 64)->nullable()->after('ci_checks');
            $table->text('merge_reason')->nullable()->after('last_commit_sha');

            $table->index('pull_request_url');
            $table->index(['repo', 'pull_request_number']);
        });
    }

    public function down(): void
    {
        Schema::table('thread_repositories', function (Blueprint $table) {
            $table->dropIndex(['pull_request_url']);
            $table->dropIndex(['repo', 'pull_request_number']);
            $table->dropColumn([
                'pull_request_number', 'pull_request_state', 'pull_request_title', 'pull_request_closed_at',
                'ci_status', 'ci_checks', 'last_commit_sha', 'merge_reason',
            ]);
        });
    }
};

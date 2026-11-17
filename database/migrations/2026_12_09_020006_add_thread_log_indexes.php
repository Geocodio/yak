<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            // The thread log's repo filter and repo sort.
            $table->index('repo');
        });

        Schema::table('thread_repositories', function (Blueprint $table) {
            // The thread log's pull request filter looks up a thread's repositories by role and state.
            $table->index(['thread_id', 'role', 'pull_request_state'], 'thread_repositories_pull_request_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('thread_repositories', function (Blueprint $table) {
            $table->dropIndex('thread_repositories_pull_request_lookup');
        });

        Schema::table('threads', function (Blueprint $table) {
            $table->dropIndex(['repo']);
        });
    }
};

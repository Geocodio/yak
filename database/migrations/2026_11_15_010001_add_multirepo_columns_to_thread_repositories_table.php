<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('thread_repositories', function (Blueprint $table) {
            $table->string('last_refresh_commit', 64)->nullable();
            $table->text('setup_output')->nullable();
            $table->text('add_reason')->nullable();
            $table->string('added_by')->nullable();
            $table->timestamp('setup_started_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('thread_repositories', function (Blueprint $table) {
            $table->dropColumn(['last_refresh_commit', 'setup_output', 'add_reason', 'added_by', 'setup_started_at']);
        });
    }
};

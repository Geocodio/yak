<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->foreign('claude_account_id')->references('id')->on('claude_accounts')->nullOnDelete();
        });
        Schema::table('task_runs', function (Blueprint $table) {
            $table->foreign('claude_account_id')->references('id')->on('claude_accounts')->nullOnDelete();
        });
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreign('requested_claude_account_id')->references('id')->on('claude_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['requested_claude_account_id']);
        });
        Schema::table('task_runs', function (Blueprint $table) {
            $table->dropForeign(['claude_account_id']);
        });
        Schema::table('threads', function (Blueprint $table) {
            $table->dropForeign(['claude_account_id']);
        });
    }
};

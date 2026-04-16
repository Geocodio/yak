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
        Schema::table('tasks', function (Blueprint $table) {
            // The Slack user ID of the person who mentioned @yak. Used
            // to @-mention the requester on status-changing notifications
            // (Result, Error, Expiry) so they get a push.
            $table->string('slack_user_id')->nullable()->after('slack_thread_ts');

            // The Slack message ts of the originating @yak mention.
            // Distinct from slack_thread_ts (which is the thread root),
            // because mentions can occur mid-thread. Used for applying
            // status reactions (eyes / construction / check / x) to the
            // user's own message.
            $table->string('slack_message_ts')->nullable()->after('slack_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['slack_user_id', 'slack_message_ts']);
        });
    }
};

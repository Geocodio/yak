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
            $table->foreignId('started_by_user_id')->nullable()->after('responsible_name')->constrained('users')->nullOnDelete();
            $table->foreignId('responsible_user_id')->nullable()->after('started_by_user_id')->constrained('users')->nullOnDelete();
            $table->string('slack_follow_up_user_id')->nullable()->after('slack_user_id');
            $table->timestamp('clarification_reminder_at')->nullable()->after('clarification_expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('started_by_user_id');
            $table->dropConstrainedForeignId('responsible_user_id');
            $table->dropColumn(['slack_follow_up_user_id', 'clarification_reminder_at']);
        });
    }
};

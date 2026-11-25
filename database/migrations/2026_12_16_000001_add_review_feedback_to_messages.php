<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['tasks', 'pending_steering_messages', 'thread_turn_messages'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->json('review_feedback')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['tasks', 'pending_steering_messages', 'thread_turn_messages'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('review_feedback');
            });
        }
    }
};

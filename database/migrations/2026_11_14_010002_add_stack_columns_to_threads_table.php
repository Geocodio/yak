<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->timestamp('taken_over_at')->nullable();
            $table->timestamp('messages_paused_at')->nullable();
            $table->timestamp('queued_for_slot_at')->nullable();
            $table->string('current_step', 120)->nullable();
            $table->unsignedInteger('stack_alert_turn_mark')->default(0);
            $table->unsignedInteger('stack_alert_hours_mark')->default(0);
            $table->unsignedInteger('stack_alert_child_mark')->default(0);
            $table->timestamp('stack_stopped_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->dropColumn(['taken_over_at', 'messages_paused_at', 'queued_for_slot_at', 'current_step', 'stack_alert_turn_mark', 'stack_alert_hours_mark', 'stack_alert_child_mark', 'stack_stopped_at']);
        });
    }
};

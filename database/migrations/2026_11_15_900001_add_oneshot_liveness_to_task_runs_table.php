<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_runs', function (Blueprint $table) {
            $table->timestamp('oneshot_heartbeat_at')->nullable();
            $table->timestamp('oneshot_deadline_at')->nullable();
            $table->index(['account_slot_held_at', 'oneshot_deadline_at'], 'task_runs_oneshot_liveness_index');
        });
    }

    public function down(): void
    {
        Schema::table('task_runs', function (Blueprint $table) {
            $table->dropIndex('task_runs_oneshot_liveness_index');
            $table->dropColumn(['oneshot_heartbeat_at', 'oneshot_deadline_at']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_runs', function (Blueprint $table) {
            $table->timestamp('account_slot_held_at')->nullable();
            $table->index(['claude_account_id', 'account_slot_held_at'], 'task_runs_account_slot_index');
        });
    }

    public function down(): void
    {
        Schema::table('task_runs', function (Blueprint $table) {
            $table->dropIndex('task_runs_account_slot_index');
            $table->dropColumn('account_slot_held_at');
        });
    }
};

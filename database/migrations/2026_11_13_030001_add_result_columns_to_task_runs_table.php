<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_runs', function (Blueprint $table) {
            $table->json('last_result_event')->nullable();
            $table->timestamp('completion_dispatched_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('task_runs', function (Blueprint $table) {
            $table->dropColumn(['last_result_event', 'completion_dispatched_at']);
        });
    }
};

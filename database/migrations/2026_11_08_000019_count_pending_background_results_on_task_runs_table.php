<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_runs', function (Blueprint $table) {
            $table->dropColumn('awaiting_background_result');
        });

        Schema::table('task_runs', function (Blueprint $table) {
            $table->unsignedInteger('pending_background_results')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('task_runs', function (Blueprint $table) {
            $table->dropColumn('pending_background_results');
        });

        Schema::table('task_runs', function (Blueprint $table) {
            $table->boolean('awaiting_background_result')->default(false);
        });
    }
};

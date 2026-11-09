<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_steering_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('read_after_sequence')->nullable();
        });

        Schema::table('task_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('prompt_read_after_sequence')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pending_steering_messages', function (Blueprint $table) {
            $table->dropColumn('read_after_sequence');
        });

        Schema::table('task_runs', function (Blueprint $table) {
            $table->dropColumn('prompt_read_after_sequence');
        });
    }
};

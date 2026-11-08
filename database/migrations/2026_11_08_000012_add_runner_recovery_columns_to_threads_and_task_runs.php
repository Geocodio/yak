<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->string('runner_model')->nullable();
            $table->longText('append_system_prompt')->nullable();
        });

        Schema::table('task_runs', function (Blueprint $table) {
            $table->longText('prompt')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->dropColumn(['runner_model', 'append_system_prompt']);
        });

        Schema::table('task_runs', function (Blueprint $table) {
            $table->dropColumn('prompt');
        });
    }
};

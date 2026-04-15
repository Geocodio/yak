<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_logs', function (Blueprint $table) {
            $table->unsignedSmallInteger('attempt_number')->default(1)->after('yak_task_id');
        });
    }

    public function down(): void
    {
        Schema::table('task_logs', function (Blueprint $table) {
            $table->dropColumn('attempt_number');
        });
    }
};

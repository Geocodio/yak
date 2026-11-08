<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_runs', function (Blueprint $table) {
            $table->json('pending_effects')->nullable();
        });

        Schema::table('threads', function (Blueprint $table) {
            $table->timestamp('blocked_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('task_runs', function (Blueprint $table) {
            $table->dropColumn('pending_effects');
        });

        Schema::table('threads', function (Blueprint $table) {
            $table->dropColumn('blocked_until');
        });
    }
};

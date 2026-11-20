<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Steps a person added to a running turn's step limit with "Add 300 steps".
     */
    public function up(): void
    {
        Schema::table('task_runs', function (Blueprint $table): void {
            $table->unsignedInteger('extra_turns')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('task_runs', function (Blueprint $table): void {
            $table->dropColumn('extra_turns');
        });
    }
};

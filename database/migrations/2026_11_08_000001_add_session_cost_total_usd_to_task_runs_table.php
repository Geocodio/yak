<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_runs', function (Blueprint $table) {
            // The session's cumulative total_cost_usd as the CLI reported it
            // at the end of this run; cost_usd holds only this run's share.
            $table->decimal('session_cost_total_usd', 10, 4)->nullable()->after('cost_usd');
        });
    }

    public function down(): void
    {
        Schema::table('task_runs', function (Blueprint $table) {
            $table->dropColumn('session_cost_total_usd');
        });
    }
};

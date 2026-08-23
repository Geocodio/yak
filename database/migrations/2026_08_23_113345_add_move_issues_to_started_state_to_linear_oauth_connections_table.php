<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('linear_oauth_connections', function (Blueprint $table) {
            $table->boolean('move_issues_to_started_state')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('linear_oauth_connections', function (Blueprint $table) {
            $table->dropColumn('move_issues_to_started_state');
        });
    }
};

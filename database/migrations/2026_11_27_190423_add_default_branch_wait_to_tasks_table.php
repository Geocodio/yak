<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->timestamp('waiting_on_default_branch_at')->nullable();
            $table->json('default_branch_wait_checks')->nullable();
            $table->string('default_branch_synced_sha', 40)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['waiting_on_default_branch_at', 'default_branch_wait_checks', 'default_branch_synced_sha']);
        });
    }
};

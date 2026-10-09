<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->string('config_commit_sha', 40)->nullable();
            $table->timestamp('config_read_at')->nullable();
            $table->string('config_read_error', 500)->nullable();
            $table->timestamp('co_owner_gate_enforced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->dropColumn(['config_commit_sha', 'config_read_at', 'config_read_error', 'co_owner_gate_enforced_at']);
        });
    }
};

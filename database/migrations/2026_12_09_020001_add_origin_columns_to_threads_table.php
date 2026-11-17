<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->string('source', 32)->default('dashboard')->index();
            $table->string('source_reference')->nullable();
            $table->string('source_url', 2048)->nullable();
            $table->string('starter_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn(['source', 'source_reference', 'source_url', 'starter_name']);
        });
    }
};

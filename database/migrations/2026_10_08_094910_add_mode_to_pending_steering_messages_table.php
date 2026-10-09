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
        Schema::table('pending_steering_messages', function (Blueprint $table) {
            $table->string('mode', 16)->default('queue')->after('source');
            $table->string('author_name')->nullable()->after('reviewer_login');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pending_steering_messages', function (Blueprint $table) {
            $table->dropColumn(['mode', 'author_name']);
        });
    }
};

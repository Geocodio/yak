<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->string('pull_request_opening_state', 16)->nullable();
            $table->text('pull_request_opening_error')->nullable();
            $table->timestamp('pull_request_opening_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->dropColumn(['pull_request_opening_state', 'pull_request_opening_error', 'pull_request_opening_at']);
        });
    }
};

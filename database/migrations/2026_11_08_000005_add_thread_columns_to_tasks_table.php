<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('thread_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('requested_claude_account_id')->nullable();
            $table->string('requested_model')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('thread_id');
            $table->dropColumn(['requested_claude_account_id', 'requested_model']);
        });
    }
};

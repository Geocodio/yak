<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_switched_by_user_id');
            $table->dropColumn(['account_switched_at', 'runner_claude_account_id']);
        });
    }

    public function down(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->foreignId('account_switched_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('account_switched_at')->nullable();
            $table->unsignedBigInteger('runner_claude_account_id')->nullable();
        });
    }
};

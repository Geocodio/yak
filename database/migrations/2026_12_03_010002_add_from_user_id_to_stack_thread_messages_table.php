<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stack_thread_messages', function (Blueprint $table) {
            $table->foreignId('from_user_id')->nullable()->after('to_thread_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stack_thread_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('from_user_id');
        });
    }
};

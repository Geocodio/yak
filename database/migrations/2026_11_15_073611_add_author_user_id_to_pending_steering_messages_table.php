<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_steering_messages', function (Blueprint $table) {
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pending_steering_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('author_user_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->string('send_key', 36)->nullable();
            $table->unique(['created_by_user_id', 'send_key']);
        });
    }

    public function down(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->dropUnique(['created_by_user_id', 'send_key']);
            $table->dropColumn('send_key');
        });
    }
};

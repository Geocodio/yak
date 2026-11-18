<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('thread_turn_messages', function (Blueprint $table) {
            $table->string('source', 32)->nullable()->after('text');
            $table->string('author_name')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('thread_turn_messages', function (Blueprint $table) {
            $table->dropColumn(['source', 'author_name']);
        });
    }
};

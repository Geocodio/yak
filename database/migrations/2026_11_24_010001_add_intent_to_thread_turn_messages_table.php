<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('thread_turn_messages', function (Blueprint $table) {
            $table->string('intent', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('thread_turn_messages', function (Blueprint $table) {
            $table->dropColumn('intent');
        });
    }
};

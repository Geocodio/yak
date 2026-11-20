<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('thread_agents', function (Blueprint $table) {
            $table->text('prompt')->nullable()->after('description');
            $table->string('last_tool_target', 255)->nullable()->after('last_tool');
        });
    }

    public function down(): void
    {
        Schema::table('thread_agents', function (Blueprint $table) {
            $table->dropColumn(['prompt', 'last_tool_target']);
        });
    }
};

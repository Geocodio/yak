<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claude_accounts', function (Blueprint $table) {
            $table->string('icon', 24)->default('briefcase');
            $table->string('color', 16)->default('violet');
        });
    }

    public function down(): void
    {
        Schema::table('claude_accounts', function (Blueprint $table) {
            $table->dropColumn(['icon', 'color']);
        });
    }
};

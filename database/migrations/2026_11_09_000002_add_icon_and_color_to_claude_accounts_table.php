<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claude_accounts', function (Blueprint $table) {
            $table->string('icon')->default('sparkles');
            $table->string('color')->default('accent');
        });
    }

    public function down(): void
    {
        Schema::table('claude_accounts', function (Blueprint $table) {
            $table->dropColumn(['icon', 'color']);
        });
    }
};

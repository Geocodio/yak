<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['threads', 'repositories'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->json('slash_commands')->nullable();
                $table->timestamp('slash_commands_captured_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['threads', 'repositories'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['slash_commands', 'slash_commands_captured_at']);
            });
        }
    }
};

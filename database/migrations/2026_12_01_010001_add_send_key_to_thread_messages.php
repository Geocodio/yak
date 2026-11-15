<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['thread_turn_messages', 'pending_steering_messages'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('send_key', 36)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (['thread_turn_messages', 'pending_steering_messages'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->dropIndex($tableName . '_send_key_index');
                $table->dropColumn('send_key');
            });
        }
    }
};

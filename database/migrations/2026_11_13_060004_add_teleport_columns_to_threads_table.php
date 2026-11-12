<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->timestamp('first_teleported_at')->nullable();
            $table->timestamp('waiting_for_computer_at')->nullable();
            $table->string('teleport_requested_running_turn', 8)->nullable();
            $table->timestamp('owner_changed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->dropColumn(['first_teleported_at', 'waiting_for_computer_at', 'teleport_requested_running_turn', 'owner_changed_at']);
        });
    }
};

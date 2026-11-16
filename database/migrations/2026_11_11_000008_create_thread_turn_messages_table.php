<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thread_turn_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_run_id')->constrained('task_runs')->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('kind', 16);
            $table->string('state', 16)->default('sent');
            $table->foreignId('pending_steering_message_id')->nullable()->constrained('pending_steering_messages')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thread_turn_messages');
    }
};

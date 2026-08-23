<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pending_steering_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('root_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->text('text');
            $table->string('source', 32);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pending_steering_messages');
    }
};

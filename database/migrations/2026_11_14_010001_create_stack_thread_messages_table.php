<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stack_thread_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('root_thread_id')->constrained('threads')->cascadeOnDelete();
            $table->foreignId('from_thread_id')->constrained('threads')->cascadeOnDelete();
            $table->foreignId('to_thread_id')->constrained('threads')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->text('text');
            $table->string('branch')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['to_thread_id', 'delivered_at']);
            $table->index(['root_thread_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stack_thread_messages');
    }
};

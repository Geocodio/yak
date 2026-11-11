<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('held_thread_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 24);
            $table->string('title');
            $table->text('summary');
            $table->string('pull_request_url')->nullable();
            $table->json('payload');
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['thread_id', 'delivered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('held_thread_events');
    }
};

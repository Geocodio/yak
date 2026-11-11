<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stack_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 24);
            $table->foreignId('from_thread_id')->nullable()->constrained('threads')->nullOnDelete();
            $table->text('text');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['thread_id', 'delivered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stack_messages');
    }
};
